<?php

namespace App\Services\Communication;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\DTOs\CallPushPayload;
use App\Enums\PhoneNumberStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceCallSignal;
use App\Services\Communication\Push\PushGatewayService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VoiceCallService implements VoiceCallServiceInterface
{
    public const DEFAULT_RING_TIMEOUT_SECONDS = 45;

    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    public function initiateCall(User $user, PhoneNumber $fromNumber, string $destinationNumber, bool $hasVideo = false): VoiceCall
    {
        if (! $user->isAdmin() && $fromNumber->user_id !== $user->id && $fromNumber->assigned_user_id !== $user->id) {
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

        // 2. Determine tariff rate per minute based on communication type from system tariffs
        $isInternal = ($resolvedDestRecord !== null);
        $isIdCall = str_starts_with($cleanDestination, '@') || ! preg_match('/^\+?[0-9]+$/', $cleanDestination);

        if ($isIdCall) {
            $ratePerMinute = (float) SystemSetting::get('id_voice_rate_per_minute', '0.0500');
        } else {
            $cleanDestDigits = PhoneNumberNormalizer::clean($cleanDestination);
            $isIntra = $isInternal || ($cleanFromPrefix !== '' && (str_starts_with($cleanDestDigits, $cleanFromPrefix) || strlen($cleanDestDigits) <= 7));
            if ($isIntra) {
                $ratePerMinute = (float) ($fromPrefix?->getIntraVoiceRate() ?? SystemSetting::get('voice_intra_prefix_rate', '0.1000'));
            } else {
                $ratePerMinute = (float) ($fromPrefix?->getInterVoiceRate() ?? SystemSetting::get('voice_inter_prefix_rate', '0.2500'));
            }
        }

        if ($ratePerMinute <= 0.0) {
            $ratePerMinute = 0.0000;
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

        $callUuid = (string) Str::uuid();
        $correlationId = 'corr-' . $callUuid;
        $ringTimeoutSec = max(15, (int) SystemSetting::get('voice_ring_timeout_seconds', self::DEFAULT_RING_TIMEOUT_SECONDS));
        $ringTimeoutAt = now()->addSeconds($ringTimeoutSec);

        // 4. Bridge & Signaling Orchestration (Go Voice Bridge Daemon)
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
                    'call_uuid' => $callUuid,
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

        $wsEndpoint = null;
        if ($bridgeSessionId) {
            $publicWsUrl = trim((string) SystemSetting::get('voice_bridge_ws_public_url', ''));
            $wsEndpoint = $publicWsUrl !== ''
                ? $publicWsUrl
                : "ws://{$bridgeHost}:{$bridgeWsPort}/ws/signaling";
            $wsEndpoint .= (str_contains($wsEndpoint, '?') ? '&' : '?') . 'session=' . urlencode($bridgeSessionId);
        }

        $callerSourceIdent = $fromNumber->formatted_number ?: $fromNumber->number;

        $outboundCall = VoiceCall::create([
            'call_uuid' => $callUuid,
            'user_id' => $user->id,
            'phone_number_id' => $fromNumber->id,
            'source_identifier' => $callerSourceIdent,
            'destination' => $cleanDestination,
            'direction' => 'outbound',
            'status' => 'in_call',
            'has_video' => $hasVideo,
            'started_at' => now(),
            'ring_timeout_at' => $ringTimeoutAt,
            'answered_at' => now(),
            'last_pulse_at' => now(),
            'duration_seconds' => 0,
            'billed_minutes' => 0,
            'rate_per_minute' => $ratePerMinute,
            'cost' => 0.0000,
            'correlation_id' => $correlationId,
            'metadata' => [
                'call_uuid' => $callUuid,
                'codec' => 'Opus/48000',
                'ping_ms' => $pingMs,
                'bridge_session_id' => $bridgeSessionId,
                'bridge_ws_endpoint' => $wsEndpoint,
                'mock_engine' => $bridgeSessionId ? 'Go-VoiceBridge-v2' : 'WebRTC-SIP-Stub-v1',
                'is_internal' => $isInternal,
                'has_video' => $hasVideo,
            ],
        ]);

        // If destination belongs to an internal user, create the Inbound Call record in 'ringing' status
        if ($destUser) {
            $calleeCall = VoiceCall::create([
                'call_uuid' => $callUuid,
                'user_id' => $destUser->id,
                'phone_number_id' => ($resolvedDestRecord instanceof PhoneNumber) ? $resolvedDestRecord->id : null,
                'source_identifier' => $callerSourceIdent,
                'destination' => $cleanDestination,
                'direction' => 'inbound',
                'status' => VoiceCall::STATE_RINGING,
                'has_video' => $hasVideo,
                'started_at' => now(),
                'ring_timeout_at' => $ringTimeoutAt,
                'answered_at' => null,
                'duration_seconds' => 0,
                'billed_minutes' => 0,
                'rate_per_minute' => 0.0000,
                'cost' => 0.0000,
                'correlation_id' => $correlationId,
                'metadata' => [
                    'call_uuid' => $callUuid,
                    'peer_call_id' => $outboundCall->id,
                    'caller_user_id' => $user->id,
                    'codec' => 'Opus/48000',
                    'ping_ms' => $pingMs,
                    'bridge_session_id' => $bridgeSessionId,
                    'bridge_ws_endpoint' => $wsEndpoint,
                    'is_internal' => true,
                    'has_video' => $hasVideo,
                ],
            ]);

            $outMeta = $outboundCall->metadata;
            $outMeta['callee_call_id'] = $calleeCall->id;
            $outMeta['callee_user_id'] = $destUser->id;
            $outboundCall->metadata = $outMeta;
            $outboundCall->status = VoiceCall::STATE_RINGING;
            $outboundCall->answered_at = null;
            $outboundCall->save();

            // Record lifecycle event in stream
            try {
                app(CallEventStreamService::class)->publishStateTransition(
                    call: $calleeCall,
                    previousState: VoiceCall::STATE_INITIATED,
                    currentState: VoiceCall::STATE_RINGING,
                    eventType: 'call.initiated'
                );
            } catch (\Throwable) {
            }

            // Notify callee via Reverb WebSocket broadcast
            try {
                broadcast(new \App\Events\CallReceived($calleeCall));
            } catch (\Throwable) {
            }

            // Route push invitation via unified PushGatewayService (Local Push first, then APNs/FCM/WebPush if WAN is active)
            try {
                $pushPayload = new CallPushPayload(
                    callUuid: $callUuid,
                    callId: $calleeCall->id,
                    callerHandle: $callerSourceIdent,
                    callerDisplayName: $user->name ?: $callerSourceIdent,
                    destinationLine: $cleanDestination,
                    hasVideo: $hasVideo,
                    issuedAt: time(),
                    expiresAt: $ringTimeoutAt->getTimestamp(),
                    correlationId: $correlationId,
                );
                app(PushGatewayService::class)->dispatchIncomingCall($destUser->id, $pushPayload, $calleeCall);
            } catch (\Throwable) {
            }
        }

        return $outboundCall;
    }

    /**
     * Atomically accept an incoming call on a specific device (Stage 2.2 Multi-device Atomic Accept).
     *
     * - Idempotent if called again from the same device_uuid.
     * - Throws 409 RuntimeException if another device of the same user already accepted the call.
     * - Dispatches `call.answered_elsewhere` to all other devices of the user to stop ringing immediately.
     */
    public function acceptCallAtomically(VoiceCall $voiceCall, User $user, ?string $deviceUuid = null): VoiceCall
    {
        $cleanDeviceUuid = $deviceUuid ? trim($deviceUuid) : null;

        $expiredByTimeout = false;

        $acceptedCall = DB::transaction(function () use ($voiceCall, $user, $cleanDeviceUuid, &$expiredByTimeout) {
            /** @var VoiceCall $locked */
            $locked = VoiceCall::where('id', $voiceCall->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->user_id !== $user->id) {
                throw new AccessDeniedHttpException(__('controllers.user.calls.access_denied'));
            }

            // Check ring timeout expiration before accepting (commit state change before throwing 410)
            if ($locked->isRinging() && $locked->ring_timeout_at && $locked->ring_timeout_at->isPast()) {
                $locked->update([
                    'status' => VoiceCall::STATE_MISSED,
                    'end_reason' => 'ring_timeout',
                    'ended_at' => now(),
                ]);

                $peerId = $locked->metadata['peer_call_id'] ?? $locked->metadata['callee_call_id'] ?? null;
                if ($peerId) {
                    VoiceCall::where('id', $peerId)->where('status', VoiceCall::STATE_RINGING)->update([
                        'status' => VoiceCall::STATE_MISSED,
                        'end_reason' => 'ring_timeout',
                        'ended_at' => now(),
                    ]);
                }

                $expiredByTimeout = true;
                return $locked->fresh();
            }

            // Check if already accepted
            if ($locked->isAcceptedOrActive()) {
                if ($cleanDeviceUuid !== null && $locked->accepted_device_id !== null && $locked->accepted_device_id !== $cleanDeviceUuid) {
                    throw new \RuntimeException('CALL_ALREADY_ACCEPTED_ON_ANOTHER_DEVICE', 409);
                }
                return $locked; // Idempotent retry from the same device
            }

            if ($locked->isTerminal()) {
                throw new \RuntimeException('CALL_ALREADY_TERMINATED_' . strtoupper($locked->status), 409);
            }

            $now = now();
            $locked->update([
                'status' => 'in_call',
                'accepted_device_id' => $cleanDeviceUuid,
                'answered_at' => $now,
                'last_pulse_at' => $now,
            ]);

            $peerId = $locked->metadata['peer_call_id'] ?? $locked->metadata['callee_call_id'] ?? null;
            if ($peerId) {
                VoiceCall::where('id', $peerId)->update([
                    'status' => 'in_call',
                    'accepted_device_id' => $cleanDeviceUuid,
                    'answered_at' => $now,
                    'last_pulse_at' => $now,
                ]);
            }

            return $locked->fresh();
        });

        if ($expiredByTimeout) {
            throw new \RuntimeException('CALL_RING_TIMEOUT_EXPIRED', 410);
        }

        try {
            app(CallEventStreamService::class)->publishStateTransition(
                call: $acceptedCall,
                previousState: VoiceCall::STATE_RINGING,
                currentState: VoiceCall::STATE_ACCEPTED,
                eventType: 'call.accepted',
                actorDeviceUuid: $cleanDeviceUuid
            );
        } catch (\Throwable) {
        }

        // Notify all other devices of this user that the call was answered elsewhere so they stop ringing
        if ($acceptedCall->call_uuid) {
            try {
                app(PushGatewayService::class)->dispatchCallControlToUserDevices(
                    userId: $user->id,
                    callUuid: $acceptedCall->call_uuid,
                    eventType: 'call.answered_elsewhere',
                    excludeDeviceUuid: $cleanDeviceUuid,
                    extra: ['accepted_device_id' => $cleanDeviceUuid ?? 'web']
                );
            } catch (\Throwable) {
            }
        }

        return $acceptedCall;
    }

    /**
     * Atomically reject/decline an incoming ringing call (idempotent).
     */
    public function rejectCallAtomically(VoiceCall $voiceCall, User $user, ?string $deviceUuid = null, string $reason = 'rejected_by_callee'): VoiceCall
    {
        $cleanDeviceUuid = $deviceUuid ? trim($deviceUuid) : null;

        $rejectedCall = DB::transaction(function () use ($voiceCall, $user, $reason) {
            /** @var VoiceCall $locked */
            $locked = VoiceCall::where('id', $voiceCall->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->user_id !== $user->id) {
                throw new AccessDeniedHttpException(__('controllers.user.calls.access_denied'));
            }

            if (in_array($locked->status, [VoiceCall::STATE_DECLINED, VoiceCall::STATE_REJECTED], true)) {
                return $locked; // Idempotent
            }

            if ($locked->isAcceptedOrActive()) {
                throw new \RuntimeException('CALL_ALREADY_ACTIVE_CANNOT_REJECT', 409);
            }

            if ($locked->isTerminal()) {
                return $locked;
            }

            $now = now();
            $locked->update([
                'status' => 'declined',
                'end_reason' => $reason,
                'ended_at' => $now,
            ]);

            $peerId = $locked->metadata['peer_call_id'] ?? $locked->metadata['callee_call_id'] ?? null;
            if ($peerId) {
                VoiceCall::where('id', $peerId)->update([
                    'status' => 'declined',
                    'end_reason' => $reason,
                    'ended_at' => $now,
                ]);
            }

            return $locked->fresh();
        });

        try {
            app(CallEventStreamService::class)->publishStateTransition(
                call: $rejectedCall,
                previousState: VoiceCall::STATE_RINGING,
                currentState: VoiceCall::STATE_REJECTED,
                eventType: 'call.rejected',
                actorDeviceUuid: $cleanDeviceUuid
            );
        } catch (\Throwable) {
        }

        if ($rejectedCall->call_uuid) {
            try {
                app(PushGatewayService::class)->dispatchCallControlToUserDevices(
                    userId: $user->id,
                    callUuid: $rejectedCall->call_uuid,
                    eventType: 'call.rejected',
                    excludeDeviceUuid: $cleanDeviceUuid
                );
            } catch (\Throwable) {
            }
        }

        return $rejectedCall;
    }

    public function endCall(VoiceCall|int $call, ?int $durationSeconds = null, ?string $endReason = null): VoiceCall
    {
        $voiceCall = is_int($call) ? VoiceCall::findOrFail($call) : $call;

        if ($voiceCall->status === 'completed' || $voiceCall->status === VoiceCall::STATE_CANCELLED) {
            return $voiceCall;
        }

        $wasRingingUnanswered = ($voiceCall->status === VoiceCall::STATE_RINGING && $voiceCall->answered_at === null);
        $previousState = $voiceCall->canonicalStatus();
        $now = now();

        if ($wasRingingUnanswered) {
            $calculatedDuration = 0;
            $effectiveEndReason = $endReason ?: 'cancelled_by_caller';
        } else {
            $startTime = $voiceCall->answered_at ?: $voiceCall->started_at ?: $now;
            $calculatedDuration = $durationSeconds !== null
                ? max(1, $durationSeconds)
                : max(1, $now->diffInSeconds($startTime));
            $effectiveEndReason = $endReason ?: 'normal_hangup';
        }

        $rate = (float) ($voiceCall->rate_per_minute !== null ? $voiceCall->rate_per_minute : SystemSetting::get('voice_call_rate_per_minute', 0.1000));
        if ($rate <= 0 || $wasRingingUnanswered) {
            $rate = 0.0000;
        }
        $costData = $this->calculateCallCost($calculatedDuration, $rate);

        $totalCostStr = number_format($costData['cost'], 4, '.', '');
        $user = $voiceCall->user;

        // Atomically debit user or organization wallet ONLY IF call was answered, rate > 0 and cost > 0
        if (! $wasRingingUnanswered && $rate > 0 && bccomp($totalCostStr, '0.0000', 4) > 0) {
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
                            'call_uuid' => $voiceCall->call_uuid,
                            'source' => $voiceCall->source_identifier,
                            'destination' => $voiceCall->destination,
                            'duration_seconds' => $costData['duration_seconds'],
                            'billed_minutes' => $costData['billed_minutes'],
                            'rate_per_minute' => $rate,
                        ]
                    );
                }
            } catch (\Throwable $e) {
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
            'end_reason' => $effectiveEndReason,
            'ended_at' => $now,
            'duration_seconds' => $costData['duration_seconds'],
            'billed_minutes' => $costData['billed_minutes'],
            'cost' => $totalCostStr,
        ]);

        // Drop the signaling mailbox of both legs
        $legIds = array_filter([
            $voiceCall->id,
            $meta['callee_call_id'] ?? null,
            $meta['peer_call_id'] ?? null,
        ]);
        try {
            VoiceCallSignal::whereIn('voice_call_id', $legIds)->delete();
        } catch (\Throwable) {
        }

        // Update matching callee/peer call in their history and notify ringing devices if cancelled
        $peerCall = null;
        if (! empty($meta['callee_call_id'])) {
            $peerCall = VoiceCall::find($meta['callee_call_id']);
        } elseif (! empty($meta['peer_call_id'])) {
            $peerCall = VoiceCall::find($meta['peer_call_id']);
        }

        if ($peerCall && $peerCall->status !== 'completed') {
            $peerCall->update([
                'status' => 'completed',
                'end_reason' => $effectiveEndReason,
                'ended_at' => $now,
                'duration_seconds' => $costData['duration_seconds'],
                'billed_minutes' => $costData['billed_minutes'],
            ]);

            if ($wasRingingUnanswered && $peerCall->call_uuid) {
                try {
                    app(PushGatewayService::class)->dispatchCallControlToUserDevices(
                        userId: $peerCall->user_id,
                        callUuid: $peerCall->call_uuid,
                        eventType: 'call.cancelled',
                        extra: ['reason' => $effectiveEndReason]
                    );
                } catch (\Throwable) {
                }
            }
        }

        try {
            app(CallEventStreamService::class)->publishStateTransition(
                call: $voiceCall,
                previousState: $previousState,
                currentState: $wasRingingUnanswered ? VoiceCall::STATE_CANCELLED : VoiceCall::STATE_ENDED,
                eventType: $wasRingingUnanswered ? 'call.cancelled' : 'call.ended',
                extraPayload: [
                    'duration_seconds' => $costData['duration_seconds'],
                    'end_reason' => $effectiveEndReason,
                ]
            );
        } catch (\Throwable) {
        }

        return $voiceCall->fresh();
    }

    /**
     * Expire ringing calls that have exceeded their ring_timeout_at and notify devices.
     */
    public function expireTimedOutRingingCalls(): int
    {
        $expiredCalls = VoiceCall::where('status', VoiceCall::STATE_RINGING)
            ->whereNotNull('ring_timeout_at')
            ->where('ring_timeout_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expiredCalls as $call) {
            $call->update([
                'status' => VoiceCall::STATE_MISSED,
                'end_reason' => 'ring_timeout',
                'ended_at' => now(),
            ]);
            $count++;

            if ($call->direction === 'inbound' && $call->call_uuid) {
                try {
                    app(PushGatewayService::class)->dispatchCallControlToUserDevices(
                        userId: $call->user_id,
                        callUuid: $call->call_uuid,
                        eventType: 'call.missed',
                        extra: ['reason' => 'ring_timeout']
                    );
                } catch (\Throwable) {
                }
            }
        }

        return $count;
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
            'call_uuid' => $voiceCall->call_uuid,
            'status' => $voiceCall->status,
            'canonical_status' => $voiceCall->canonicalStatus(),
            'accepted_device_id' => $voiceCall->accepted_device_id,
            'end_reason' => $voiceCall->end_reason,
            'started_at' => $voiceCall->started_at?->toIso8601String(),
            'duration_seconds' => $duration,
            'rate_per_minute' => $rate,
            'estimated_cost' => $costData['cost'],
        ];
    }

    public function calculateCallCost(int $durationSeconds, ?float $customRate = null): array
    {
        $durationSeconds = max(0, $durationSeconds);
        $rate = $customRate !== null
            ? (float) $customRate
            : (float) SystemSetting::get('voice_call_rate_per_minute', 0.1000);

        if ($rate <= 0) {
            $rate = 0.0000;
        }

        $billedMinutes = $durationSeconds > 0 ? (int) ceil($durationSeconds / 60) : 0;
        $cost = $rate > 0 ? (float) bcmul((string) $billedMinutes, (string) $rate, 4) : 0.0000;

        return [
            'duration_seconds' => $durationSeconds,
            'billed_minutes' => $billedMinutes,
            'rate_per_minute' => $rate,
            'cost' => $cost,
        ];
    }

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
