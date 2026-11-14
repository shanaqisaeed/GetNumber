<?php

namespace App\Contracts\Communication;

use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\VoiceCall;

interface VoiceCallServiceInterface
{
    /**
     * Initiate a new voice call (or simulated mock connection).
     *
     * @param User $user
     * @param PhoneNumber $fromNumber
     * @param string $destinationNumber
     * @return VoiceCall
     */
    public function initiateCall(User $user, PhoneNumber $fromNumber, string $destinationNumber): VoiceCall;

    /**
     * End a call session, calculate duration in seconds, determine billed minutes,
     * calculate tariff and atomically deduct from user wallet via ledger/wallet_transactions.
     *
     * @param VoiceCall|int $call
     * @param int|null $durationSeconds
     * @return VoiceCall
     */
    public function endCall(VoiceCall|int $call, ?int $durationSeconds = null): VoiceCall;

    /**
     * Get real-time status and live duration of an active call.
     *
     * @param VoiceCall|int $call
     * @return array{
     *     call_id: int,
     *     status: string,
     *     started_at: ?string,
     *     duration_seconds: int,
     *     rate_per_minute: float,
     *     estimated_cost: float
     * }
     */
    public function getCallStatus(VoiceCall|int $call): array;

    /**
     * Calculate cost based on seconds and rate per minute.
     *
     * @param int $durationSeconds
     * @param float|null $customRate
     * @return array{duration_seconds: int, billed_minutes: int, rate_per_minute: float, cost: float}
     */
    public function calculateCallCost(int $durationSeconds, ?float $customRate = null): array;
}

