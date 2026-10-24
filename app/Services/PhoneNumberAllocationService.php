<?php

namespace App\Services;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Exceptions\InvalidNumberFormatException;
use App\Exceptions\NumberAllocationException;
use App\Exceptions\NumberAlreadyAllocatedException;
use App\Exceptions\UserQuotaExceededException;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PhoneNumberAllocationService implements PhoneNumberAllocationServiceInterface
{
    /**
     * Default maximum active allocated numbers per standard user.
     */
    public const DEFAULT_MAX_QUOTA_PER_USER = 10;

    /**
     * Maximum attempts to resolve collision when generating random numbers.
     */
    protected const MAX_GENERATION_ATTEMPTS = 15;

    public function allocateStandardNumberToUser(
        User $user,
        ?string $preferredNumber = null,
        ?string $displayTitle = null,
        ?User $actor = null
    ): PhoneNumber {
        return DB::transaction(function () use ($user, $preferredNumber, $displayTitle, $actor) {
            $this->ensureUserWithinQuota($user);

            if ($preferredNumber !== null && trim($preferredNumber) !== '') {
                $cleanNumber = $this->sanitizeStandardNumber($preferredNumber);
                $phone = $this->allocateSpecificNumber(
                    $cleanNumber,
                    PhoneNumberType::UserStandard,
                    $user,
                    $displayTitle,
                    $actor
                );
            } else {
                $phone = $this->allocateFromPoolOrGenerate(
                    PhoneNumberType::UserStandard,
                    $user,
                    $displayTitle,
                    $actor
                );
            }

            return $phone;
        });
    }

    public function allocateServiceNumberToUser(
        User $user,
        string $serviceNumber,
        string $displayTitle,
        ?User $actor = null
    ): PhoneNumber {
        $cleanNumber = trim($serviceNumber);

        if (! preg_match('/^[0-9]{4,8}$/', $cleanNumber)) {
            throw new InvalidNumberFormatException('طول سرشماره اختصاصی باید بین ۴ تا ۸ رقم عددی باشد.');
        }

        if (trim($displayTitle) === '') {
            throw new InvalidNumberFormatException('تعیین عنوان نمایشی برای سرشماره اختصاصی الزامی است.');
        }

        return DB::transaction(function () use ($user, $cleanNumber, $displayTitle, $actor) {
            $this->ensureUserWithinQuota($user);

            return $this->allocateSpecificNumber(
                $cleanNumber,
                PhoneNumberType::UserService,
                $user,
                $displayTitle,
                $actor
            );
        });
    }

    public function releaseNumber(
        PhoneNumber $phoneNumber,
        ?User $actor = null,
        ?string $reason = null
    ): PhoneNumber {
        return DB::transaction(function () use ($phoneNumber, $actor, $reason) {
            /** @var PhoneNumber $phone */
            $phone = PhoneNumber::where('id', $phoneNumber->id)->lockForUpdate()->firstOrFail();

            $fromStatus = $phone->status;
            $previousUserId = $phone->user_id;

            $phone->update([
                'user_id' => null,
                'status' => PhoneNumberStatus::Available,
                'allocated_at' => null,
                'display_title' => null,
            ]);

            $this->createAudit(
                phoneNumber: $phone,
                action: 'released',
                fromStatus: $fromStatus->value,
                toStatus: PhoneNumberStatus::Available->value,
                actor: $actor,
                metadata: [
                    'previous_user_id' => $previousUserId,
                    'reason' => $reason,
                ]
            );

            return $phone->fresh();
        });
    }

    public function updateStatus(
        PhoneNumber $phoneNumber,
        PhoneNumberStatus $newStatus,
        ?User $actor = null,
        ?string $reason = null
    ): PhoneNumber {
        return DB::transaction(function () use ($phoneNumber, $newStatus, $actor, $reason) {
            /** @var PhoneNumber $phone */
            $phone = PhoneNumber::where('id', $phoneNumber->id)->lockForUpdate()->firstOrFail();

            if ($phone->status === $newStatus) {
                return $phone;
            }

            $fromStatus = $phone->status;
            $phone->update(['status' => $newStatus]);

            $this->createAudit(
                phoneNumber: $phone,
                action: 'status_changed',
                fromStatus: $fromStatus->value,
                toStatus: $newStatus->value,
                actor: $actor,
                metadata: ['reason' => $reason]
            );

            return $phone->fresh();
        });
    }

    /**
     * Checks if there is an available standard number in the pool for allocation.
     * Respects line type "شماره عادی کاربر" (PhoneNumberType::UserStandard).
     */
    public function hasAvailableStandardNumber(): bool
    {
        return PhoneNumber::where('type', PhoneNumberType::UserStandard->value)
            ->where('status', PhoneNumberStatus::Available->value)
            ->exists();
    }

    public function generateBatchAvailableNumbers(
        int $count,
        PhoneNumberType $type = PhoneNumberType::UserStandard,
        int $digits = 9
    ): int {
        $createdCount = 0;
        $max = (int) str_repeat('9', $digits);

        for ($i = 0; $i < $count; $i++) {
            for ($attempt = 0; $attempt < self::MAX_GENERATION_ATTEMPTS; $attempt++) {
                $randomNumber = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

                if (! PhoneNumber::where('number', $randomNumber)->exists()) {
                    PhoneNumber::create([
                        'number' => $randomNumber,
                        'type' => $type,
                        'status' => PhoneNumberStatus::Available,
                    ]);
                    $createdCount++;
                    break;
                }
            }
        }

        return $createdCount;
    }

    /**
     * Checks if user has exceeded maximum active phone number quota.
     */
    protected function ensureUserWithinQuota(User $user, int $maxQuota = self::DEFAULT_MAX_QUOTA_PER_USER): void
    {
        // Admin users have unlimited quota
        if ($user->isAdmin()) {
            return;
        }

        $activeCount = PhoneNumber::where('user_id', $user->id)
            ->where('status', PhoneNumberStatus::Allocated)
            ->count();

        if ($activeCount >= $maxQuota) {
            throw new UserQuotaExceededException($maxQuota);
        }
    }

    /**
     * Sanitizes and validates standard 9-digit number format.
     */
    protected function sanitizeStandardNumber(string $rawNumber): string
    {
        // Strip optional '+85' or whitespace
        $cleaned = preg_replace('/^\+?85/', '', trim($rawNumber));
        $cleaned = preg_replace('/\D/', '', (string) $cleaned);

        if (strlen($cleaned) !== 9) {
            throw new InvalidNumberFormatException('شماره استاندارد عادی باید دقیقاً ۹ رقم عددی باشد.');
        }

        return $cleaned;
    }

    /**
     * Allocates a specific number row or creates one if it doesn't exist.
     */
    protected function allocateSpecificNumber(
        string $number,
        PhoneNumberType $type,
        User $user,
        ?string $displayTitle,
        ?User $actor
    ): PhoneNumber {
        /** @var PhoneNumber|null $phone */
        $phone = PhoneNumber::where('number', $number)->lockForUpdate()->first();

        if ($phone !== null) {
            if ($phone->type === PhoneNumberType::PlatformService && ! $user->isAdmin()) {
                throw new NumberAllocationException('سرشماره‌های اختصاصی سامانه قابل تخصیص به کاربران عادی نمی‌باشند.');
            }

            if ($phone->status !== PhoneNumberStatus::Available) {
                throw new NumberAlreadyAllocatedException($number);
            }

            $fromStatus = $phone->status;
            $phone->update([
                'user_id' => $user->id,
                'type' => $type,
                'status' => PhoneNumberStatus::Allocated,
                'allocated_at' => now(),
                'display_title' => $displayTitle,
            ]);
        } else {
            $fromStatus = PhoneNumberStatus::Available;
            $phone = PhoneNumber::create([
                'number' => $number,
                'type' => $type,
                'status' => PhoneNumberStatus::Allocated,
                'user_id' => $user->id,
                'allocated_at' => now(),
                'display_title' => $displayTitle,
            ]);
        }

        $this->createAudit(
            phoneNumber: $phone,
            action: 'allocated',
            fromStatus: $fromStatus->value,
            toStatus: PhoneNumberStatus::Allocated->value,
            actor: $actor ?? $user,
            metadata: [
                'user_id' => $user->id,
                'username' => $user->username,
                'display_title' => $displayTitle,
            ]
        );

        return $phone->fresh();
    }

    /**
     * Picks an available number from the pool with lock, or safely generates one.
     */
    protected function allocateFromPoolOrGenerate(
        PhoneNumberType $type,
        User $user,
        ?string $displayTitle,
        ?User $actor
    ): PhoneNumber {
        /** @var PhoneNumber|null $availablePhone */
        $availablePhone = PhoneNumber::where('type', $type->value)
            ->where('status', PhoneNumberStatus::Available->value)
            ->lockForUpdate()
            ->orderBy('id', 'asc')
            ->first();

        if ($availablePhone !== null) {
            $fromStatus = $availablePhone->status;
            $availablePhone->update([
                'user_id' => $user->id,
                'status' => PhoneNumberStatus::Allocated,
                'allocated_at' => now(),
                'display_title' => $displayTitle,
            ]);

            $this->createAudit(
                phoneNumber: $availablePhone,
                action: 'allocated',
                fromStatus: $fromStatus->value,
                toStatus: PhoneNumberStatus::Allocated->value,
                actor: $actor ?? $user,
                metadata: [
                    'source' => 'available_pool',
                    'user_id' => $user->id,
                    'username' => $user->username,
                ]
            );

            return $availablePhone->fresh();
        }

        // Pool empty: generate a unique 9-digit string preserving leading zeros
        for ($attempt = 0; $attempt < self::MAX_GENERATION_ATTEMPTS; $attempt++) {
            $generated = str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

            // Double check uniqueness with lock
            $exists = PhoneNumber::where('number', $generated)->exists();
            if (! $exists) {
                $newPhone = PhoneNumber::create([
                    'number' => $generated,
                    'type' => $type,
                    'status' => PhoneNumberStatus::Allocated,
                    'user_id' => $user->id,
                    'allocated_at' => now(),
                    'display_title' => $displayTitle,
                ]);

                $this->createAudit(
                    phoneNumber: $newPhone,
                    action: 'allocated',
                    fromStatus: PhoneNumberStatus::Available->value,
                    toStatus: PhoneNumberStatus::Allocated->value,
                    actor: $actor ?? $user,
                    metadata: [
                        'source' => 'auto_generated',
                        'user_id' => $user->id,
                        'username' => $user->username,
                    ]
                );

                return $newPhone->fresh();
            }
        }

        throw new NumberAllocationException('خطا در تولید شماره یکتا پس از چند تلاش متوالی. لطفاً دوباره تلاش نمایید.');
    }

    /**
     * Helper to write audit log.
     *
     * @param array<string, mixed>|null $metadata
     */
    protected function createAudit(
        PhoneNumber $phoneNumber,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?User $actor = null,
        ?array $metadata = null
    ): void {
        PhoneNumberAudit::create([
            'phone_number_id' => $phoneNumber->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata,
            'ip_address' => request()?->ip(),
        ]);

        $auditAction = match ($action) {
            'allocated' => 'number_assigned',
            'released' => 'number_released',
            'status_changed' => 'number_status_changed',
            default => 'number_' . $action,
        };

        AuditLog::record(
            action: $auditAction,
            entity: $phoneNumber,
            actor: $actor,
            payload: array_merge($metadata ?? [], [
                'number' => $phoneNumber->number,
                'formatted_number' => $phoneNumber->formatted_number,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ])
        );
    }
}
