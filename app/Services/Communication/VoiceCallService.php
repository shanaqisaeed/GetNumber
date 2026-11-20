<?php

namespace App\Services\Communication;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallSignal;
use App\Services\PhoneNumberNormalizer;
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
            throw new AccessDeniedHttpException(__('services.communication.voice.not_owner'));
        }

        if ($fromNumber->status !== PhoneNumberStatus::Allocated) {
            throw new AccessDeniedHttpException(__('services.communication.voice.line_not_active'));
        }

        $cleanDestination = trim($destinationNumber);
        if ($cleanDestination === '') {
            throw new \InvalidArgumentException(__('services.communication.voice.dest_empty'));
        }

        // 1. Auto-resolve short extensions / numbers without prefix
        $fromNumber->loadMissing('prefix');
        $fromPrefix = $fromNumber->prefix;
        $cleanFromPrefix = $fromPrefix ? preg_replace('/^\+/', '', $fromPrefix->prefix) : '';

        $resolvedDestRecord = null;
        $destUser = null;

        if (str_starts_with($cleanDestination, '@') || ! preg_match('/^\+?[0-9]+$/', $cleanDestination)) {
            // Custom ID lookup
            $handleWithAt = '@' . ltrim($cleanDestination, '@');
            $resolvedDestRecord = \App\Models\ServiceResourceAllocation::with(['user', 'phoneNumber'])
                ->where('resource_identifier', $handleWithAt)
                ->where('status', 'active')
                ->first();
            if ($resolvedDestRecord) {
                $destUser = $resolvedDestRecord->user;
                $cleanDestination = $handleWithAt;
            }
        } else {
            // Numeric destination lookup (pure database columns: number & prefix)
            $cleanDestDigits = PhoneNumberNormalizer::clean($cleanDestination);

            // 1. Exact match on stored number
            $resolvedDestRecord = PhoneNumber::with(['user', 'prefix'])
                ->where('number', $cleanDestDigits)
                ->first();

            // 2. Check if destination was entered with an active system prefix (e.g. 851000 or +851000)
            if (! $resolvedDestRecord) {
                $activeCleanPrefixes = PhonePrefix::getActiveCleanPrefixes();
                usort($activeCleanPrefixes, fn ($a, $b) => strlen($b) <=> strlen($a));
                foreach ($activeCleanPrefixes as $prefixStr) {
                    if (str_starts_with($cleanDestDigits, $prefixStr) && strlen($cleanDestDigits) > strlen($prefixStr)) {
                        $withoutPrefix = substr($cleanDestDigits, strlen($prefixStr));
                        $found = PhoneNumber::with(['user', 'prefix'])
                            ->where('number', $withoutPrefix)
                            ->first();
                        if ($found) {
                            $resolvedDestRecord = $found;
                            break;
                        }
                    }
                }
            }

            // 3. If caller has prefix and destination entered was short extension (e.g. 1000), check with prefix attached
            if (! $resolvedDestRecord && $cleanFromPrefix !== '') {
                $prefixed = $cleanFromPrefix . $cleanDestDigits;
                $resolvedDestRecord = PhoneNumber::with(['user', 'prefix'])
                    ->where('number', $prefixed)
                    ->first();
            }

            if ($resolvedDestRecord) {
                $destUser = $resolvedDestRecord->user;
                $cleanDestination = $resolvedDestRecord->formatted_number ?: ('+' . $cleanDestDigits);
            }
        }

        // 2. Determine tariff rate per minute based on communication type from system tariffs:
        $isInternal = ($resolvedDestRecord !== null);

        if (str_starts_with($cleanDestination, '@') || ! preg_match('/^\+?[0-9]+$/', $cleanDestination)) {
            // ID-to-ID or Custom ID communication tariff (configured in global admin tariffs)
            $ratePerMinute = (float) SystemSetting::get('id_voice_rate_per_minute', '0.0500');
        } else {
            $cleanDestDigits = PhoneNumberNormalizer::clean($cleanDestination);
            // Check if call is intra-prefix (internal extension, same prefix, or short extension)
            $isIntra = $isInternal || ($cleanFromPrefix !== '' && (str_starts_with($cleanDestDigits, $cleanFromPrefix) || strlen($cleanDestDigits) <= 7));
            if ($isIntra) {
                // Intra-prefix voice call rate (configured per prefix or global intra rate)
                $ratePerMinute = (float) ($fromPrefix?->getIntraVoiceRate() ?? SystemSetting::get('voice_intra_prefix_rate', '0.1000'));
            } else {
                // Inter-prefix / external voice call rate
                $ratePerMinute = (float) ($fromPrefix?->getInterVoiceRate() ?? SystemSetting::get('voice_inter_prefix_rate', '0.2500'));
            }
        }

        // 3. Pre-check wallet has at least 1 minute of credit (only if rate > 0)
        if ($ratePerMinute > 0) {
            $wallet = ($fromNumber->is_organization && $fromNumber->organization_id)
                ? $fromNumber->organization?->getOrCreateWallet()
                : $this->walletService->getWallet($user);

            if ($wallet && bccomp((string) $wallet->balance, (string) $ratePerMinute, 4) < 0) {
                throw new InsufficientWalletBalanceException(
                    requiredAmount: (string) $ratePerMinute,
                    currentBalance: (string) $wallet->balance,
                    message: __('services.communication.voice.insufficient_balance_first_minute', ['rate' => $ratePerMinute])
                );
            }
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
                $client = Http::timeout(2.5);
                if (! empty($bridgeSecret)) {
                    $client = $client->withHeaders(['X-Bridge-Secret' => $bridgeSecret]);
                }
                $startUrl = self::buildBridgeUrl($bridgeHost, $bridgeApiPort, '/api/sessions/start');
                $bridgeRes = $client->post($startUrl, [
                    'call_id' => 0,
                    'user_id' => $user->id,
                    'source' => $fromNumber->number,
                    'destination' => $cleanDestination,
                    'callback_url' => url('/api/voice/bridge-event'),
                ]);
                if ($bridgeRes->successful()) {
                    $bData = $bridgeRes->json();
                    $bridgeSessionId = $bData['session']['id'] ?? null;
                    $pingMs = (int) ($bData['session']['ping_ms'] ?? 22);
                } else {
                    \Illuminate\Support\Facades\Log::warning('[VoiceCallService] Voice Bridge start session responded with HTTP ' . $bridgeRes->status(), [
                        'body' => $bridgeRes->body(),
                        'url' => $startUrl,
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[VoiceCallService] Failed contacting Voice Bridge API: ' . $e->getMessage(), [
                    'url' => $startUrl ?? null,
                ]);
            }
        }

        // Browser-facing signaling endpoint. SECURITY: never hand the master bridge
        // secret to browsers; the bridge authorizes WS clients by the (random,
        // unguessable) session id it just provisioned.
        $wsEndpoint = null;
        if ($bridgeSessionId) {
            $publicWsUrl = trim((string) SystemSetting::get('voice_bridge_ws_public_url', ''));
            $wsEndpoint = $publicWsUrl !== ''
                ? $publicWsUrl
                : "ws://{$bridgeHost}:{$bridgeWsPort}/ws/signaling";
            $wsEndpoint .= (str_contains($wsEndpoint, '?') ? '&' : '?') . 'session=' . urlencode($bridgeSessionId);
        }

        $outboundCall = VoiceCall::create([
            'user_id' => $user->id,
            'phone_number_id' => $fromNumber->id,
            'source_identifier' => $fromNumber->formatted_number ?: $fromNumber->number,
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
                'is_internal' => $isInternal,
            ],
        ]);

        // If destination belongs to an internal user, create the Inbound Call record in 'ringing' status
        if ($destUser) {
            $calleeCall = VoiceCall::create([
                'user_id' => $destUser->id,
                'phone_number_id' => ($resolvedDestRecord instanceof PhoneNumber) ? $resolvedDestRecord->id : null,
                'source_identifier' => $fromNumber->formatted_number ?: $fromNumber->number,
                'destination' => $cleanDestination,
                'direction' => 'inbound',
                'status' => 'ringing',
                'started_at' => now(),
                'answered_at' => null,
                'duration_seconds' => 0,
                'billed_minutes' => 0,
                'rate_per_minute' => 0.0000,
                'cost' => 0.0000,
                'metadata' => [
                    'peer_call_id' => $outboundCall->id,
                    'codec' => 'Opus/48000',
                    'ping_ms' => $pingMs,
                    'bridge_session_id' => $bridgeSessionId,
                    'bridge_ws_endpoint' => $wsEndpoint,
                    'is_internal' => true,
                ],
            ]);

            $outMeta = $outboundCall->metadata;
            $outMeta['callee_call_id'] = $calleeCall->id;
            $outboundCall->metadata = $outMeta;
            $outboundCall->status = 'ringing';
            $outboundCall->answered_at = null;
            $outboundCall->save();

            // Notify callee via WebSocket broadcast & Web Push Notification
            try {
                broadcast(new \App\Events\CallReceived($calleeCall));
            } catch (\Throwable) {}

            try {
                app(\App\Services\WebPushNotificationService::class)->sendVoiceCallNotification($calleeCall);
            } catch (\Throwable) {}
        }

        return $outboundCall;
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

        // Atomically debit user or organization wallet if cost > 0
        if (bccomp($totalCostStr, '0.0000', 4) > 0) {
            $refId = 'CALL-' . Str::upper(Str::random(12));
            $phone = $voiceCall->phoneNumber;
            $isOrgLine = $phone && ($phone->is_organization || $phone->organization_id);

            try {
                if ($isOrgLine && $phone->organization) {
                    $orgWallet = $phone->organization->getOrCreateWallet();
                    $orgWallet->debit(
                        amount: (float) $totalCostStr,
                        type: 'voice_call',
                        description: __('services.communication.voice.desc_call_charge', [
                            'destination' => $voiceCall->destination,
                            'seconds' => $costData['duration_seconds'],
                            'minutes' => $costData['billed_minutes'],
                        ]),
                        userId: $user?->id
                    );
                } elseif ($user) {
                    $this->walletService->chargeForUsage(
                        user: $user,
                        amount: $totalCostStr,
                        referenceId: $refId,
                        type: WalletTransactionType::VoiceCall,
                        description: __('services.communication.voice.desc_call_charge', [
                            'destination' => $voiceCall->destination,
                            'seconds' => $costData['duration_seconds'],
                            'minutes' => $costData['billed_minutes'],
                        ]),
                        metadata: [
                            'call_id' => $voiceCall->id,
                            'source' => $voiceCall->source_identifier,
                            'destination' => $voiceCall->destination,
                            'duration_seconds' => $costData['duration_seconds'],
                            'billed_minutes' => $costData['billed_minutes'],
                            'rate_per_minute' => $rate,
                        ]
                    );
                }
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
                $endUrl = self::buildBridgeUrl($bridgeHost, $bridgeApiPort, '/api/sessions/end');
                $client->post($endUrl, [
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

        // Drop the signaling mailbox of both legs; the peer detects the hangup via call status.
        $legIds = array_filter([
            $voiceCall->id,
            $meta['callee_call_id'] ?? null,
            $meta['peer_call_id'] ?? null,
        ]);
        try {
            VoiceCallSignal::whereIn('voice_call_id', $legIds)->delete();
        } catch (\Throwable) {
            // Signaling cleanup must never block billing / call termination.
        }

        // Also update matching callee call in their history
        if (! empty($meta['callee_call_id'])) {
            $calleeCall = VoiceCall::find($meta['callee_call_id']);
            if ($calleeCall && $calleeCall->status !== 'completed') {
                $calleeCall->update([
                    'status' => 'completed',
                    'ended_at' => $now,
                    'duration_seconds' => $costData['duration_seconds'],
                    'billed_minutes' => $costData['billed_minutes'],
                ]);
            }
        } elseif (! empty($meta['peer_call_id'])) {
            $peerCall = VoiceCall::find($meta['peer_call_id']);
            if ($peerCall && $peerCall->status !== 'completed') {
                $peerCall->update([
                    'status' => 'completed',
                    'ended_at' => $now,
                    'duration_seconds' => $costData['duration_seconds'],
                    'billed_minutes' => $costData['billed_minutes'],
                ]);
            }
        }

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

    /**
     * Build an RFC-compliant HTTP/HTTPS endpoint for the Voice Bridge daemon.
     * Supports standard ports 80/443 and domain names with SSL termination.
     */
    public static function buildBridgeUrl(string $host, int $port, string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $cleanHost = trim($host);

        if (str_starts_with($cleanHost, 'http://') || str_starts_with($cleanHost, 'https://')) {
            $base = rtrim($cleanHost, '/');
            $parsedPort = parse_url($base, PHP_URL_PORT);
            if ($parsedPort || $port === 80 || $port === 443) {
                return "{$base}{$path}";
            }
            return "{$base}:{$port}{$path}";
        }

        $scheme = ($port === 443) ? 'https' : 'http';
        $portSuffix = ($port === 80 || $port === 443) ? '' : ":{$port}";
        return "{$scheme}://{$cleanHost}{$portSuffix}{$path}";
    }
}

