<?php

namespace App\Contracts\Communication;

use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\VoiceCall;

interface VoiceCallServiceInterface
{
    /**
     * Initiate a new voice or video call.
     */
    public function initiateCall(User $user, PhoneNumber $fromNumber, string $destinationNumber, bool $hasVideo = false): VoiceCall;

    /**
     * Atomically accept an incoming call on a specific device (idempotent for same device, 409 for competing devices).
     */
    public function acceptCallAtomically(VoiceCall $voiceCall, User $user, ?string $deviceUuid = null): VoiceCall;

    /**
     * Atomically reject/decline an incoming ringing call (idempotent).
     */
    public function rejectCallAtomically(VoiceCall $voiceCall, User $user, ?string $deviceUuid = null, string $reason = 'rejected_by_callee'): VoiceCall;

    /**
     * End a call session, calculate duration in seconds, determine billed minutes,
     * calculate tariff and atomically deduct from user wallet via ledger/wallet_transactions.
     */
    public function endCall(VoiceCall|int $call, ?int $durationSeconds = null, ?string $endReason = null): VoiceCall;

    /**
     * Expire ringing calls that have exceeded their ring_timeout_at.
     */
    public function expireTimedOutRingingCalls(): int;

    /**
     * Get real-time status and live duration of an active call.
     *
     * @return array<string, mixed>
     */
    public function getCallStatus(VoiceCall|int $call): array;

    /**
     * Calculate cost based on seconds and rate per minute.
     *
     * @return array{duration_seconds: int, billed_minutes: int, rate_per_minute: float, cost: float}
     */
    public function calculateCallCost(int $durationSeconds, ?float $customRate = null): array;
}
