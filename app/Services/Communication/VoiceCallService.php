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

        // Get configured rate per minute
        $ratePerMinute = (float) SystemSetting::get('voice_call_rate_per_minute', 0.1000);

        // Pre-check wallet has at least 1 minute of credit
        $wallet = $this->walletService->getWallet($user);
        if (bccomp((string) $wallet->balance, (string) $ratePerMinute, 4) < 0) {
            throw new InsufficientWalletBalanceException(
                requiredAmount: (string) $ratePerMinute,
                currentBalance: (string) $wallet->balance,
                message: "موجودی کیف‌پول شما جهت برقراری تماس صوتی (حداقل {$ratePerMinute} کوین برای دقیقه اول) کافی نمی‌باشد."
            );
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
                'mock_engine' => 'WebRTC-SIP-Stub-v1',
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

