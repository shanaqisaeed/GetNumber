<?php

namespace App\Contracts;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use Carbon\Carbon;

interface PhoneNumberAllocationServiceInterface
{
    /**
     * Atomically allocates a 9-digit standard number to the user.
     * Preserves leading zeroes (000000000 - 999999999).
     */
    public function allocateStandardNumberToUser(
        User $user,
        ?string $preferredNumber = null,
        ?string $displayTitle = null,
        ?User $actor = null,
        ?PhonePrefix $prefix = null
    ): PhoneNumber;

    /**
     * Atomically allocates a 4 to 8 digit service number to the user.
     */
    public function allocateServiceNumberToUser(
        User $user,
        string $serviceNumber,
        string $displayTitle,
        ?User $actor = null,
        ?PhonePrefix $prefix = null
    ): PhoneNumber;

    /**
     * Reserves a phone number for a user for a specific duration.
     */
    public function reserveNumber(
        PhoneNumber $phoneNumber,
        User $user,
        ?Carbon $until = null,
        ?User $actor = null,
        ?string $reason = null
    ): PhoneNumber;

    /**
     * Marks a phone number as expired and revokes active usage.
     */
    public function expireNumber(
        PhoneNumber $phoneNumber,
        ?User $actor = null,
        ?string $reason = null
    ): PhoneNumber;

    /**
     * Releases an allocated number back to the available pool.
     * CRITICAL PRIVACY RULE: Purges all messages of the released line so the next assignee cannot view history.
     */
    public function releaseNumber(
        PhoneNumber $phoneNumber,
        ?User $actor = null,
        ?string $reason = null
    ): PhoneNumber;

    /**
     * Updates status of a phone number (e.g. suspend or activate).
     */
    public function updateStatus(
        PhoneNumber $phoneNumber,
        PhoneNumberStatus $newStatus,
        ?User $actor = null,
        ?string $reason = null
    ): PhoneNumber;

    /**
     * Checks if there is an available standard number in the pool for allocation.
     */
    public function hasAvailableStandardNumber(): bool;

    /**
     * Generates a batch of available numbers for the pool.
     */
    public function generateBatchAvailableNumbers(
        int $count,
        PhoneNumberType $type = PhoneNumberType::UserStandard,
        int $digits = 9
    ): int;

    /**
     * Process and release/expire numbers whose reservation or validity has elapsed.
     */
    public function processExpiredNumbers(): int;
}
