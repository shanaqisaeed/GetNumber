<?php

namespace App\Services;

use App\Contracts\NumberRequestServiceInterface;
use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Exceptions\InvalidNumberFormatException;
use App\Exceptions\InvalidNumberRequestException;
use App\Exceptions\NumberAlreadyAllocatedException;
use App\Exceptions\NumberAllocationException;
use App\Exceptions\NumberRequestAlreadyReviewedException;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NumberRequestService implements NumberRequestServiceInterface
{
    /**
     * Maximum collision retry attempts for service number generation.
     */
    protected const MAX_GENERATION_ATTEMPTS = 20;

    public function __construct(
        protected PhoneNumberAllocationServiceInterface $allocationService
    ) {}

    public function createRequest(
        User $user,
        int $requestedLength,
        string $serviceTitle,
        string $description,
        ?string $preferredNumber = null
    ): NumberRequest {
        if ($requestedLength < 4 || $requestedLength > 8) {
            throw new InvalidNumberRequestException('طول سرشماره درخواستی باید بین ۴ تا ۸ رقم باشد.');
        }

        $cleanTitle = trim($serviceTitle);
        if ($cleanTitle === '') {
            throw new InvalidNumberRequestException('تعیین عنوان نمایشی سرشماره الزامی است.');
        }

        $cleanDescription = trim($description);
        if ($cleanDescription === '') {
            throw new InvalidNumberRequestException('توضیح کاربرد سرشماره سازمانی الزامی است.');
        }

        $cleanPreferred = null;
        if ($preferredNumber !== null && trim($preferredNumber) !== '') {
            $cleanPreferred = preg_replace('/\D/', '', trim($preferredNumber));
            if (strlen((string) $cleanPreferred) !== $requestedLength) {
                throw new InvalidNumberRequestException("سرشماره پیشنهادی باید دقیقاً {$requestedLength} رقم عددی باشد.");
            }
        }

        return NumberRequest::create([
            'user_id' => $user->id,
            'requested_length' => $requestedLength,
            'service_title' => $cleanTitle,
            'description' => $cleanDescription,
            'preferred_number' => $cleanPreferred,
            'status' => NumberRequestStatus::Pending,
        ]);
    }

    public function approveRequest(
        NumberRequest $request,
        User $admin,
        string $mode = 'auto',
        ?string $customNumber = null,
        ?int $poolNumberId = null
    ): NumberRequest {
        return DB::transaction(function () use ($request, $admin, $mode, $customNumber, $poolNumberId) {
            /** @var NumberRequest $req */
            $req = NumberRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            if (! $req->isPending()) {
                throw new NumberRequestAlreadyReviewedException();
            }

            $applicant = $req->user;
            if (! $applicant) {
                throw new NumberAllocationException('کاربر متقاضی این سرشماره یافت نشد.');
            }

            /** @var PhoneNumber $phone */
            $phone = match ($mode) {
                'custom' => $this->allocateCustomNumber($req, $applicant, $customNumber, $admin),
                'pool' => $this->allocatePoolNumber($req, $applicant, $poolNumberId, $admin),
                default => $this->allocateAutoNumber($req, $applicant, $admin),
            };

            $req->update([
                'status' => NumberRequestStatus::Approved,
                'allocated_number_id' => $phone->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $req->fresh(['user', 'reviewer', 'allocatedNumber']);
        });
    }

    public function rejectRequest(
        NumberRequest $request,
        User $admin,
        string $reason
    ): NumberRequest {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new InvalidNumberRequestException('درج دلیل رد درخواست الزامی است.');
        }

        return DB::transaction(function () use ($request, $admin, $cleanReason) {
            /** @var NumberRequest $req */
            $req = NumberRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            if (! $req->isPending()) {
                throw new NumberRequestAlreadyReviewedException();
            }

            $req->update([
                'status' => NumberRequestStatus::Rejected,
                'rejection_reason' => $cleanReason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $req->fresh(['user', 'reviewer']);
        });
    }

    public function cancelRequest(
        NumberRequest $request,
        User $user
    ): void {
        DB::transaction(function () use ($request, $user) {
            /** @var NumberRequest $req */
            $req = NumberRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            if ($req->user_id !== $user->id && ! $user->isAdmin()) {
                throw new NumberAllocationException('شما دسترسی لازم برای لغو این درخواست را ندارید.');
            }

            if (! $req->isPending()) {
                throw new NumberRequestAlreadyReviewedException('امکان لغو درخواستی که در وضعیت انتظار نیست وجود ندارد.');
            }

            $req->delete();
        });
    }

    /**
     * Allocates a specific custom number requested or entered by admin.
     */
    protected function allocateCustomNumber(
        NumberRequest $req,
        User $applicant,
        ?string $customNumber,
        User $admin
    ): PhoneNumber {
        if ($customNumber === null || trim($customNumber) === '') {
            throw new InvalidNumberFormatException('وارد کردن شماره دستی الزامی است.');
        }

        $cleanNumber = preg_replace('/\D/', '', trim($customNumber));
        if (strlen((string) $cleanNumber) !== $req->requested_length) {
            throw new InvalidNumberFormatException("طول شماره دستی باید دقیقاً {$req->requested_length} رقم باشد.");
        }

        /** @var PhoneNumber|null $existing */
        $existing = PhoneNumber::where('number', $cleanNumber)->lockForUpdate()->first();

        if ($existing !== null) {
            throw new NumberAlreadyAllocatedException($cleanNumber);
        }

        $phone = PhoneNumber::create([
            'number' => $cleanNumber,
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $applicant->id,
            'allocated_at' => now(),
            'display_title' => $req->service_title,
        ]);

        $this->createAudit($phone, $admin, 'custom', $req->id);

        return $phone;
    }

    /**
     * Allocates an existing available service number from pool.
     */
    protected function allocatePoolNumber(
        NumberRequest $req,
        User $applicant,
        ?int $poolNumberId,
        User $admin
    ): PhoneNumber {
        if (! $poolNumberId) {
            throw new InvalidNumberRequestException('انتخاب سرشماره آزاد از استخر الزامی است.');
        }

        /** @var PhoneNumber|null $phone */
        $phone = PhoneNumber::where('id', $poolNumberId)
            ->where('status', PhoneNumberStatus::Available->value)
            ->lockForUpdate()
            ->first();

        if (! $phone) {
            throw new NumberAllocationException('سرشماره انتخابی در وضعیت آزاد قرار ندارد.');
        }

        if (strlen($phone->number) !== $req->requested_length) {
            throw new InvalidNumberRequestException("طول سرشماره انتخابی با طول درخواستی ({$req->requested_length} رقم) مطابقت ندارد.");
        }

        $phone->update([
            'user_id' => $applicant->id,
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'allocated_at' => now(),
            'display_title' => $req->service_title,
        ]);

        $this->createAudit($phone, $admin, 'pool', $req->id);

        return $phone->fresh();
    }

    /**
     * Auto-allocates by searching available pool numbers of requested length, or safely generates one.
     */
    protected function allocateAutoNumber(
        NumberRequest $req,
        User $applicant,
        User $admin
    ): PhoneNumber {
        $digits = $req->requested_length;

        // 1. Try finding an existing available service number matching digits
        /** @var PhoneNumber|null $availablePhone */
        $availablePhone = PhoneNumber::where('status', PhoneNumberStatus::Available->value)
            ->whereRaw('LENGTH(number) = ?', [$digits])
            ->lockForUpdate()
            ->orderBy('id', 'asc')
            ->first();

        if ($availablePhone !== null) {
            $availablePhone->update([
                'user_id' => $applicant->id,
                'type' => PhoneNumberType::UserService,
                'status' => PhoneNumberStatus::Allocated,
                'allocated_at' => now(),
                'display_title' => $req->service_title,
            ]);

            $this->createAudit($availablePhone, $admin, 'auto_pool', $req->id);

            return $availablePhone->fresh();
        }

        // 2. Generate a unique random number of exact length
        $max = (int) str_repeat('9', $digits);
        for ($attempt = 0; $attempt < self::MAX_GENERATION_ATTEMPTS; $attempt++) {
            $generated = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);

            $exists = PhoneNumber::where('number', $generated)->exists();
            if (! $exists) {
                $newPhone = PhoneNumber::create([
                    'number' => $generated,
                    'type' => PhoneNumberType::UserService,
                    'status' => PhoneNumberStatus::Allocated,
                    'user_id' => $applicant->id,
                    'allocated_at' => now(),
                    'display_title' => $req->service_title,
                ]);

                $this->createAudit($newPhone, $admin, 'auto_generated', $req->id);

                return $newPhone->fresh();
            }
        }

        throw new NumberAllocationException('خطا در تولید سرشماره یکتا پس از چند تلاش متوالی. لطفاً دستی شماره انتخاب نمایید.');
    }

    /**
     * Writes security audit record.
     */
    protected function createAudit(
        PhoneNumber $phone,
        User $admin,
        string $source,
        int $requestId
    ): void {
        PhoneNumberAudit::create([
            'phone_number_id' => $phone->id,
            'user_id' => $admin->id,
            'action' => 'allocated_via_request',
            'from_status' => PhoneNumberStatus::Available->value,
            'to_status' => PhoneNumberStatus::Allocated->value,
            'metadata' => [
                'request_id' => $requestId,
                'source' => $source,
                'admin_username' => $admin->username,
            ],
            'ip_address' => request()?->ip(),
        ]);
    }
}
