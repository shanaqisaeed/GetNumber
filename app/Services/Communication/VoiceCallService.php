<?php

namespace App\Services\Communication;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VoiceCallService implements VoiceCallServiceInterface
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    public function initiateCall(User $user, PhoneNumber $fromNumber, string $destinationNumber): VoiceCall
    {
        if (! $user->isAdmin() && $fromNumber->user_id !== $user->id) {
            throw new AccessDeniedHttpException('شما مالک این خط ارتباطی نمی‌باشید.');
        }

        if ($fromNumber->status !== PhoneNumberStatus::Allocated) {
            throw new AccessDeniedHttpException('خط انتخابی در وضعیت فعال قرار ندارد.');
        }

        $cleanDestination = trim($destinationNumber);
        if ($cleanDestination === '') {
            throw new \InvalidArgumentException('شماره یا آیدی مقصد نباید خالی باشد.');
        }

        // Determine tariff rate per minute based on communication type:
        $isId = str_starts_with($cleanDestination, '@') || ! preg_match('/^\+?[0-9]+$/', $cleanDestination);
        if ($isId) {
            // ID-to-ID communication tariff
            $ratePerMinute = (float) SystemSetting::get('id_voice_rate_per_minute', 0.0500);
        } else {
            $fromNumber->loadMissing('prefix');
            $fromPrefix = $fromNumber->prefix;
            $cleanDestDigits = preg_replace('/^\+/', '', $cleanDestination);
            $cleanFromPrefix = $fromPrefix ? preg_replace('/^\+/', '', $fromPrefix->prefix) : '';

            // Check if call is intra-prefix (same prefix) or inter-prefix
            $isIntra = $cleanFromPrefix !== '' && str_starts_with($cleanDestDigits, $cleanFromPrefix);
            if ($isIntra) {
                $ratePerMinute = (float) ($fromPrefix?->getIntraVoiceRate() ?? SystemSetting::get('voice_intra_prefix_rate', 0.1000));
            } else {
                $ratePerMinute = (float) ($fromPrefix?->getInterVoiceRate() ?? SystemSetting::get('voice_inter_prefix_rate', 0.2500));
            }
        }

        // Pre-check wallet has at least 1 minute of credit
        $wallet = $this->walletService->getWallet($user);
        if (bccomp((string) $wallet->balance, (string) $ratePerMinute, 4) < 0) {
            throw new InsufficientWalletBalanceException(
                requiredAmount: (string) $ratePerMinute,
                currentBalance: (string) $wallet->balance,
                message: "موجودی کیف‌پول شما جهت برقراری تماس صوتی (حداقل {$ratePerMinute} کوین برای دقیقه اول) کافی نمی‌باشد."
            );
        }

        // Bridge & Signaling Orchestration (Go Voice Bridge Daemon)
        $bridgeHost = SystemSetting::get('voice_bridge_host', '127.0.0.1');
        $bridgeApiPort = (int) SystemSetting::get('voice_bridge_api_port', 49000);
        $bridgeWsPort = (int) SystemSetting::get('voice_bridge_ws_port', 49001);
        $bridgeSecret = SystemSetting::get('voice_bridge_secret', '');
        $bridgeEnabled = (bool) SystemSetting::get('voice_bridge_enabled', true);

        $bridgeSessionId = null;
        $pingMs = 22;

        if ($bridgeEnabled) {
            try {
                $client = Http::timeout(0.5);
                if (! empty($bridgeSecret)) {
                    $client = $client->withHeaders(['X-Bridge-Secret' => $bridgeSecret]);
                }
                $bridgeRes = $client->post("http://{$bridgeHost}:{$bridgeApiPort}/api/sessions/start", [
                    'call_id' => 0,
                    'user_id' => $user->id,
                    'source' => $fromNumber->number,
                    'destination' => $cleanDestination,
                ]);
                if ($bridgeRes->successful()) {
                    $bData = $bridgeRes->json();
                    $bridgeSessionId = $bData['session']['id'] ?? null;
                    $pingMs = (int) ($bData['session']['ping_ms'] ?? 22);
                }
            } catch (\Throwable) {
                // Bridge daemon might not be running locally; gracefully fall back to native mock
            }
        }

        $wsEndpoint = "ws://{$bridgeHost}:{$bridgeWsPort}/ws/signaling";
        if (! empty($bridgeSecret)) {
            $wsEndpoint .= '?secret=' . urlencode($bridgeSecret);
        }

        return VoiceCall::create([
            'user_id' => $user->id,
            'phone_number_id' => $fromNumber->id,
            'source_identifier' => $fromNumber->number,
            'destination' => $cleanDestination,
            'direction' => 'outbound',
            'status' => 'in_call',
            'started_at' => now(),
            'answered_at' => now(),
            'duration_seconds' => 0,
            'billed_minutes' => 0,
            'rate_per_minute' => $ratePerMinute,
            'cost' => 0.0000,
            'metadata' => [
                'codec' => 'Opus/48000',
                'ping_ms' => $pingMs,
                'bridge_session_id' => $bridgeSessionId,
                'bridge_ws_endpoint' => $wsEndpoint,
                'mock_engine' => $bridgeSessionId ? 'Go-VoiceBridge-v1' : 'WebRTC-SIP-Stub-v1',
            ],
        ]);
    }

    public function endCall(VoiceCall|int $call, ?int $durationSeconds = null): VoiceCall
    {
        $voiceCall = is_int($call) ? VoiceCall::findOrFail($call) : $call;

        if ($voiceCall->status === 'completed') {
            return $voiceCall;
        }

        $now = now();
        $startTime = $voiceCall->answered_at ?: $voiceCall->started_at ?: $now;
        $calculatedDuration = $durationSeconds !== null
            ? max(1, $durationSeconds)
            : max(1, $now->diffInSeconds($startTime));

        $rate = (float) ($voiceCall->rate_per_minute ?: SystemSetting::get('voice_call_rate_per_minute', 0.1000));
        $costData = $this->calculateCallCost($calculatedDuration, $rate);

        $totalCostStr = number_format($costData['cost'], 4, '.', '');
        $user = $voiceCall->user;

        // Atomically debit user wallet if cost > 0
        if (bccomp($totalCostStr, '0.0000', 4) > 0 && $user) {
            $refId = 'CALL-' . Str::upper(Str::random(12));
            try {
                $this->walletService->chargeForUsage(
                    user: $user,
                    amount: $totalCostStr,
                    referenceId: $refId,
                    type: WalletTransactionType::VoiceCall,
                    description: "هزینه مکالمه صوتی به مقصد {$voiceCall->destination} ({$costData['duration_seconds']} ثانیه - {$costData['billed_minutes']} دقیقه)",
                    metadata: [
                        'call_id' => $voiceCall->id,
                        'source' => $voiceCall->source_identifier,
                        'destination' => $voiceCall->destination,
                        'duration_seconds' => $costData['duration_seconds'],
                        'billed_minutes' => $costData['billed_minutes'],
                        'rate_per_minute' => $rate,
                    ]
                );
            } catch (\Throwable $e) {
                // If wallet deduction fails (e.g. balance exhausted mid-call), record in metadata
                $meta = $voiceCall->metadata ?? [];
                $meta['billing_error'] = $e->getMessage();
                $voiceCall->metadata = $meta;
            }
        }

        // Terminate bridge session if registered
        $meta = $voiceCall->metadata ?? [];
        if (! empty($meta['bridge_session_id'])) {
            try {
                $bridgeHost = SystemSetting::get('voice_bridge_host', '127.0.0.1');
                $bridgeApiPort = (int) SystemSetting::get('voice_bridge_api_port', 49000);
                $bridgeSecret = SystemSetting::get('voice_bridge_secret', '');
                $client = Http::timeout(0.5);
                if (! empty($bridgeSecret)) {
                    $client = $client->withHeaders(['X-Bridge-Secret' => $bridgeSecret]);
                }
                $client->post("http://{$bridgeHost}:{$bridgeApiPort}/api/sessions/end", [
                    'session_id' => $meta['bridge_session_id'],
                    'duration_seconds' => $costData['duration_seconds'],
                ]);
            } catch (\Throwable) {
                // Ignore bridge connection timeout on teardown
            }
        }

        $voiceCall->update([
            'status' => 'completed',
            'ended_at' => $now,
            'duration_seconds' => $costData['duration_seconds'],
            'billed_minutes' => $costData['billed_minutes'],
            'cost' => $totalCostStr,
        ]);

        return $voiceCall->fresh();
    }

    public function getCallStatus(VoiceCall|int $call): array
    {
        $voiceCall = is_int($call) ? VoiceCall::findOrFail($call) : $call;

        $now = now();
        $duration = $voiceCall->status === 'completed'
            ? $voiceCall->duration_seconds
            : ($voiceCall->answered_at ? max(0, $now->diffInSeconds($voiceCall->answered_at)) : 0);

        $rate = (float) $voiceCall->rate_per_minute;
        $costData = $this->calculateCallCost($duration, $rate);

        return [
            'call_id' => $voiceCall->id,
            'status' => $voiceCall->status,
            'started_at' => $voiceCall->started_at?->toIso8601String(),
            'duration_seconds' => $duration,
            'rate_per_minute' => $rate,
            'estimated_cost' => $costData['cost'],
        ];
    }

    public function calculateCallCost(int $durationSeconds, ?float $customRate = null): array
    {
        $durationSeconds = max(0, $durationSeconds);
        $rate = $customRate !== null && $customRate > 0
            ? $customRate
            : (float) SystemSetting::get('voice_call_rate_per_minute', 0.1000);

        // Every commenced minute is billed (ceil)
        $billedMinutes = $durationSeconds > 0 ? (int) ceil($durationSeconds / 60) : 0;
        $cost = (float) bcmul((string) $billedMinutes, (string) $rate, 4);

        return [
            'duration_seconds' => $durationSeconds,
            'billed_minutes' => $billedMinutes,
            'rate_per_minute' => $rate,
            'cost' => $cost,
        ];
    }
}

