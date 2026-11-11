<?php

namespace App\Services;

use App\Contracts\NumberRequestServiceInterface;
use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Exceptions\InvalidNumberFormatException;
use App\Exceptions\InvalidNumberRequestException;
use App\Exceptions\NumberAlreadyAllocatedException;
use App\Exceptions\NumberAllocationException;
use App\Exceptions\NumberRequestAlreadyReviewedException;
use App\Models\AuditLog;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberAudit;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Numbers\RondPatternDetectorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NumberRequestService implements NumberRequestServiceInterface
{
    /**
     * Maximum collision retry attempts for service number generation.
     */
    protected const MAX_GENERATION_ATTEMPTS = 20;

    protected WalletServiceInterface $walletService;
    protected RondPatternDetectorService $rondDetector;

    public function __construct(
        protected PhoneNumberAllocationServiceInterface $allocationService,
        ?WalletServiceInterface $walletService = null,
        ?RondPatternDetectorService $rondDetector = null
    ) {
        $this->walletService = $walletService ?? app(WalletServiceInterface::class);
        $this->rondDetector = $rondDetector ?? app(RondPatternDetectorService::class);
    }

    public function createRequest(
        User $user,
        int $requestedLength,
        string $serviceTitle,
        string $description,
        ?string $preferredNumber = null,
        bool $acceptsIncomingSms = true,
        ?int $phonePrefixId = null
    ): NumberRequest {
        if ($requestedLength < 4 || $requestedLength > 8) {
            throw new InvalidNumberRequestException(__('services.number_requests.length_between'));
        }

        $cleanTitle = trim($serviceTitle);
        if ($cleanTitle === '') {
            throw new InvalidNumberRequestException(__('services.number_requests.title_required'));
        }

        $cleanDescription = trim($description);
        if ($cleanDescription === '') {
            throw new InvalidNumberRequestException(__('services.number_requests.description_required'));
        }

        $cleanPreferred = null;
        if ($preferredNumber !== null && trim($preferredNumber) !== '') {
            $cleanPreferred = preg_replace('/\D/', '', trim($preferredNumber));
            if (strlen((string) $cleanPreferred) !== $requestedLength) {
                throw new InvalidNumberRequestException(__('services.number_requests.preferred_length_mismatch', ['length' => $requestedLength]));
            }

            $alreadyAllocated = PhoneNumber::query()
                ->where('number', $cleanPreferred)
                ->where('status', '!=', PhoneNumberStatus::Available)
                ->exists();

            if ($alreadyAllocated) {
                throw new InvalidNumberRequestException(__('catalog.validation.already_allocated', ['identifier' => $cleanPreferred]));
            }
        }

        $rond = $cleanPreferred ? $this->rondDetector->detect($cleanPreferred) : ['is_rond' => false, 'tier' => 'regular'];
        $isRond = (bool) ($rond['is_rond'] ?? false);
        $price = SystemSetting::getOrgNumberPrice($requestedLength, $isRond);

        return DB::transaction(function () use ($user, $phonePrefixId, $requestedLength, $cleanTitle, $cleanDescription, $cleanPreferred, $acceptsIncomingSms, $isRond, $rond, $price) {
            $holdRef = 'HOLD-REQ-' . Str::uuid();

            // Atomically hold/block funds in user's wallet
            $this->walletService->holdForNumberRequest(
                user: $user,
                amount: $price,
                referenceId: $holdRef,
                description: __('services.number_requests.desc_block', ['title' => $cleanTitle]),
                metadata: [
                    'phone_prefix_id' => $phonePrefixId,
                    'requested_length' => $requestedLength,
                    'service_title' => $cleanTitle,
                    'preferred_number' => $cleanPreferred,
                    'is_rond' => $isRond,
                ]
            );

            return NumberRequest::create([
                'user_id' => $user->id,
                'phone_prefix_id' => $phonePrefixId,
                'requested_length' => $requestedLength,
                'service_title' => $cleanTitle,
                'description' => $cleanDescription,
                'preferred_number' => $cleanPreferred,
                'accepts_incoming_sms' => $acceptsIncomingSms,
                'status' => NumberRequestStatus::Pending,
                'held_amount' => $price,
                'hold_reference_id' => $holdRef,
                'is_rond' => $isRond,
                'rond_tier' => $rond['tier'] ?? 'regular',
            ]);
        });
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
                throw new NumberAllocationException(__('services.number_requests.user_not_found'));
            }

            /** @var PhoneNumber $phone */
            $phone = match ($mode) {
                'custom' => $this->allocateCustomNumber($req, $applicant, $customNumber, $admin),
                'pool' => $this->allocatePoolNumber($req, $applicant, $poolNumberId, $admin),
                default => $this->allocateAutoNumber($req, $applicant, $admin),
            };

            // Finalize held funds if present
            if ((float) $req->held_amount > 0 && $req->hold_reference_id) {
                $this->walletService->finalizeNumberRequestHold(
                    user: $applicant,
                    amount: (string) $req->held_amount,
                    referenceId: 'FIN-' . $req->hold_reference_id,
                    description: __('services.number_requests.desc_settle', ['title' => $req->service_title]),
                    metadata: [
                        'request_id' => $req->id,
                        'phone_number_id' => $phone->id,
                    ]
                );
            }

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
            throw new InvalidNumberRequestException(__('services.number_requests.reject_reason_required'));
        }

        return DB::transaction(function () use ($request, $admin, $cleanReason) {
            /** @var NumberRequest $req */
            $req = NumberRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            if (! $req->isPending()) {
                throw new NumberRequestAlreadyReviewedException();
            }

            // Release held funds immediately if present
            if ((float) $req->held_amount > 0 && $req->hold_reference_id) {
                $this->walletService->releaseNumberRequestHold(
                    user: $req->user,
                    amount: (string) $req->held_amount,
                    referenceId: 'REL-' . $req->hold_reference_id,
                    description: __('services.number_requests.desc_reject_refund', ['title' => $req->service_title]),
                    metadata: [
                        'request_id' => $req->id,
                        'reason' => $cleanReason,
                    ]
                );
            }

            $req->update([
                'status' => NumberRequestStatus::Rejected,
                'rejection_reason' => $cleanReason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record('request_rejected', $req, $admin, [
                'request_id' => $req->id,
                'applicant_id' => $req->user_id,
                'rejection_reason' => $cleanReason,
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
                throw new NumberAllocationException(__('services.number_requests.unauthorized_cancel'));
            }

            if (! $req->isPending()) {
                throw new NumberRequestAlreadyReviewedException(__('services.number_requests.cannot_cancel_reviewed'));
            }

            // Release held funds immediately if present
            if ((float) $req->held_amount > 0 && $req->hold_reference_id) {
                $this->walletService->releaseNumberRequestHold(
                    user: $req->user,
                    amount: (string) $req->held_amount,
                    referenceId: 'CAN-' . $req->hold_reference_id,
                    description: __('services.number_requests.desc_cancel_refund', ['title' => $req->service_title]),
                    metadata: [
                        'request_id' => $req->id,
                    ]
                );
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
            throw new InvalidNumberFormatException(__('services.number_requests.custom_number_required'));
        }

        $cleanNumber = preg_replace('/\D/', '', trim($customNumber));
        if (strlen((string) $cleanNumber) !== $req->requested_length) {
            throw new InvalidNumberFormatException(__('services.number_requests.custom_number_length', ['length' => $req->requested_length]));
        }

        /** @var PhoneNumber|null $existing */
        $existing = PhoneNumber::where('number', $cleanNumber)->lockForUpdate()->first();

        if ($existing !== null) {
            throw new NumberAlreadyAllocatedException($cleanNumber);
        }

        $prefixId = $req->phone_prefix_id ?? \App\Models\PhonePrefix::getDefaultPrefix()->id;

        $phone = PhoneNumber::create([
            'phone_prefix_id' => $prefixId,
            'number' => $cleanNumber,
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $applicant->id,
            'allocated_at' => now(),
            'display_title' => $req->service_title,
            'accepts_incoming_sms' => $req->accepts_incoming_sms ?? true,
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
            throw new InvalidNumberRequestException(__('services.number_requests.pool_selection_required'));
        }

        /** @var PhoneNumber|null $phone */
        $phone = PhoneNumber::where('id', $poolNumberId)
            ->where('status', PhoneNumberStatus::Available->value)
            ->where('type', '!=', PhoneNumberType::PlatformService->value)
            ->lockForUpdate()
            ->first();

        if (! $phone) {
            throw new NumberAllocationException(__('services.number_requests.pool_number_not_available'));
        }

        if (strlen($phone->number) !== $req->requested_length) {
            throw new InvalidNumberRequestException(__('services.number_requests.pool_number_length_mismatch', ['length' => $req->requested_length]));
        }

        $phone->update([
            'user_id' => $applicant->id,
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'allocated_at' => now(),
            'display_title' => $req->service_title,
            'accepts_incoming_sms' => $req->accepts_incoming_sms ?? true,
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
            ->where('type', '!=', PhoneNumberType::PlatformService->value)
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
                'accepts_incoming_sms' => $req->accepts_incoming_sms ?? true,
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
                $prefixId = $req->phone_prefix_id ?? \App\Models\PhonePrefix::getDefaultPrefix()->id;
                $newPhone = PhoneNumber::create([
                    'phone_prefix_id' => $prefixId,
                    'number' => $generated,
                    'type' => PhoneNumberType::UserService,
                    'status' => PhoneNumberStatus::Allocated,
                    'user_id' => $applicant->id,
                    'allocated_at' => now(),
                    'display_title' => $req->service_title,
                    'accepts_incoming_sms' => $req->accepts_incoming_sms ?? true,
                ]);

                $this->createAudit($newPhone, $admin, 'auto_generated', $req->id);

                return $newPhone->fresh();
            }
        }

        throw new NumberAllocationException(__('services.number_requests.unique_generation_failed'));
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

        AuditLog::record('request_approved', $phone, $admin, [
            'request_id' => $requestId,
            'source' => $source,
            'allocated_number' => $phone->number,
            'formatted_number' => $phone->formatted_number,
            'applicant_id' => $phone->user_id,
        ]);
    }
}