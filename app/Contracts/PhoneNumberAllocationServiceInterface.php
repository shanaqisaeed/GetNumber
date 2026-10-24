<?php

namespace App\Contracts;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\PhoneNumber;
use App\Models\User;

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
        ?User $actor = null
    ): PhoneNumber;

    /**
     * Atomically allocates a 4 to 8 digit service number to the user.
     */
    public function allocateServiceNumberToUser(
        User $user,
        string $serviceNumber,
        string $displayTitle,
        ?User $actor = null
    ): PhoneNumber;

    /**
     * Releases an allocated number back to the available pool.
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
}
