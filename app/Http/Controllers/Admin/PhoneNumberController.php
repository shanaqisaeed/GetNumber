<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Exceptions\NumberAllocationException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhoneNumberController extends Controller
{
    public function __construct(
        protected PhoneNumberAllocationServiceInterface $allocationService
    ) {}

    /**
     * Display a listing of all phone numbers in system.
     */
    public function index(Request $request): Response
    {
        $query = PhoneNumber::with(['user', 'prefix']);


        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->where('id', (int) $search);
                }
                $q->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('display_title', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->whereHas('identities', fn ($iq) => $iq->where('provider_id', 'like', "%{$search}%"))->orWhere('account_id', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('length')) {
            $query->whereRaw('LENGTH(number) = ?', [(int) $request->input('length')]);
        }

        // Sorting
        $sort = $request->input('sort', 'newest');
        match ($sort) {
            'oldest' => $query->oldest('id'),
            'number_asc' => $query->orderBy('number', 'asc'),
            'number_desc' => $query->orderBy('number', 'desc'),
            'allocated_at' => $query->orderByRaw('allocated_at IS NULL, allocated_at DESC'),
            default => $query->latest('id'),
        };

        $numbers = $query->paginate(15)
            ->withQueryString()
            ->through(fn ($phone) => [
                'id' => $phone->id,
                'number' => $phone->number,
                'formatted_number' => $phone->formatted_number,
                'phone_prefix_id' => $phone->phone_prefix_id,
                'prefix' => $phone->prefix ? [
                    'id' => $phone->prefix->id,
                    'prefix' => $phone->prefix->prefix,
                    'name' => $phone->prefix->name,
                ] : null,
                'effective_title' => $phone->effective_title,
                'display_title' => $phone->display_title,
                'rond_tier' => $phone->rond_tier ?? 'regular',
                'rond_tier_label' => $phone->rond_tier_label ?? __('controllers.user.phone_numbers.rond_default'),
                'rond_pattern' => $phone->rond_pattern,
                'type' => $phone->type->value,
                'type_label' => $phone->type->label(),
                'type_badge' => $phone->type->badgeClasses(),
                'status' => $phone->status->value,
                'status_label' => $phone->status->label(),
                'status_badge' => $phone->status->badgeClasses(),
                'accepts_incoming_sms' => (bool) $phone->accepts_incoming_sms,
                'allocated_at' => $phone->allocated_at?->format('Y/m/d H:i'),
                'created_at' => $phone->created_at->format('Y/m/d H:i'),
                'user' => $phone->user ? [
                    'id' => $phone->user->id,
                    'name' => $phone->user->name,
                    'username' => $phone->user->username,
                ] : null,
            ]);

        $cacheService = app(\App\Contracts\SystemCacheServiceInterface::class);
        $users = $cacheService->getAdminUsersDropdown();
        $prefixes = \App\Models\PhonePrefix::active()->get(['id', 'prefix', 'name', 'is_default']);
        $stats = $cacheService->getAdminNumbersStats();

        return Inertia::render('Admin/Numbers/Index', [
            'numbers' => $numbers,
            'filters' => $request->only(['search', 'type', 'status', 'user_id', 'length', 'sort']),
            'users' => $users,
            'prefixes' => $prefixes,
            'stats' => $stats,
        ]);
    }

    /**
     * Store new numbers (single entry or batch generation).
     */
    public function store(Request $request): RedirectResponse
    {
        $mode = $request->input('mode', 'single');

        if ($mode === 'batch') {
            $validated = $request->validate([
                'count' => ['required', 'integer', 'min:1', 'max:100'],
                'digits' => ['required', 'integer', 'min:4', 'max:11'],
                'type' => ['required', 'string', 'in:user_standard,user_service,platform_service'],
            ], [
                'count.required' => __('controllers.admin.phone_numbers.val_count_required'),
                'digits.required' => __('controllers.admin.phone_numbers.val_length_required'),
                'digits.min' => __('controllers.admin.phone_numbers.val_digits_min'),
                'digits.max' => __('controllers.admin.phone_numbers.val_digits_max'),
            ]);

            $type = PhoneNumberType::from($validated['type']);
            $digits = (int) $validated['digits'];

            $generatedCount = $this->allocationService->generateBatchAvailableNumbers(
                count: (int) $validated['count'],
                type: $type,
                digits: $digits
            );

            return back()->with('success', __('controllers.admin.phone_numbers.batch_generated_flash', ['count' => $generatedCount, 'digits' => $digits]));
        }

        // Single number creation
        $validated = $request->validate([
            'number' => ['required', 'string', 'regex:/^[0-9]{4,11}$/', 'unique:phone_numbers,number'],
            'phone_prefix_id' => ['nullable', 'exists:phone_prefixes,id'],
            'type' => ['required', 'string', 'in:user_standard,user_service,platform_service'],
            'rond_tier' => ['nullable', 'string', 'in:regular,semi_rond,golden_rond'],
            'rond_pattern' => ['nullable', 'string', 'max:100'],
            'display_title' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'exists:users,id'],
            'accepts_incoming_sms' => ['nullable', 'boolean'],
        ], [
            'number.required' => __('controllers.admin.phone_numbers.val_number_required'),
            'number.regex' => __('controllers.admin.phone_numbers.val_number_regex'),
            'number.unique' => __('controllers.admin.phone_numbers.val_number_unique'),
        ]);

        $type = PhoneNumberType::from($validated['type']);
        $user = ! empty($validated['user_id']) ? User::find($validated['user_id']) : null;

        // Platform service numbers cannot belong to regular users
        if ($type === PhoneNumberType::PlatformService && $user && ! $user->isAdmin()) {
            return back()->with('error', __('controllers.admin.phone_numbers.platform_service_cannot_allocate'));
        }

        $status = $user ? PhoneNumberStatus::Allocated : PhoneNumberStatus::Available;
        if ($type === PhoneNumberType::PlatformService && ! $user) {
            // Platform lines managed directly by system are active
            $status = PhoneNumberStatus::Allocated;
        }

        $prefixId = ! empty($validated['phone_prefix_id'])
            ? (int) $validated['phone_prefix_id']
            : \App\Models\PhonePrefix::getDefaultPrefix()->id;

        $cleanNumber = trim($validated['number']);
        $rondInfo = app(\App\Services\Numbers\RondPatternDetectorService::class)->detect($cleanNumber);
        $finalRondTier = ! empty($validated['rond_tier']) ? $validated['rond_tier'] : $rondInfo['tier'];
        $finalRondPattern = ! empty($validated['rond_pattern']) ? trim($validated['rond_pattern']) : $rondInfo['pattern'];

        $phone = PhoneNumber::create([
            'phone_prefix_id' => $prefixId,
            'number' => $cleanNumber,
            'type' => $type,
            'status' => $status,
            'rond_tier' => $finalRondTier,
            'rond_pattern' => $finalRondPattern,
            'display_title' => $validated['display_title'] ? trim($validated['display_title']) : null,
            'user_id' => $user?->id,
            'allocated_at' => $status === PhoneNumberStatus::Allocated ? now() : null,
            'accepts_incoming_sms' => $request->has('accepts_incoming_sms') ? $request->boolean('accepts_incoming_sms') : true,
        ]);

        AuditLog::record('number_created', $phone, $request->user(), [
            'number' => $phone->number,
            'formatted_number' => $phone->formatted_number,
            'type' => $phone->type->value,
            'status' => $phone->status->value,
            'rond_tier' => $phone->rond_tier,
            'display_title' => $phone->display_title,
            'user_id' => $phone->user_id,
        ]);

        return back()->with('success', __('controllers.admin.phone_numbers.number_created', ['number' => $phone->formatted_number]));
    }

    /**
     * Update details of an existing phone number.
     */
    public function update(Request $request, PhoneNumber $number): RedirectResponse
    {
        $validated = $request->validate([
            'display_title' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:user_standard,user_service,platform_service'],
            'rond_tier' => ['nullable', 'string', 'in:regular,semi_rond,golden_rond'],
            'rond_pattern' => ['nullable', 'string', 'max:100'],
            'phone_prefix_id' => ['nullable', 'exists:phone_prefixes,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'accepts_incoming_sms' => ['nullable', 'boolean'],
        ], [
            'display_title.max' => __('controllers.user.phone_numbers.val_title_max'),
            'type.required' => __('controllers.admin.phone_numbers.val_type_required'),
            'type.in' => __('controllers.admin.phone_numbers.val_type_in'),
        ]);

        $type = PhoneNumberType::from($validated['type']);
        $newUserId = ! empty($validated['user_id']) ? (int) $validated['user_id'] : null;
        $user = $newUserId ? User::find($newUserId) : null;

        if ($type === PhoneNumberType::PlatformService && $user && ! $user->isAdmin()) {
            return back()->with('error', __('controllers.admin.phone_numbers.platform_service_cannot_allocate'));
        }

        $oldValues = [
            'display_title' => $number->display_title,
            'type' => $number->type->value,
            'user_id' => $number->user_id,
            'phone_prefix_id' => $number->phone_prefix_id,
            'accepts_incoming_sms' => $number->accepts_incoming_sms,
            'rond_tier' => $number->rond_tier,
        ];

        $updateData = [
            'display_title' => $validated['display_title'] ? trim($validated['display_title']) : null,
            'type' => $type,
            'accepts_incoming_sms' => $request->has('accepts_incoming_sms') ? $request->boolean('accepts_incoming_sms') : true,
        ];

        if ($request->filled('rond_tier')) {
            $updateData['rond_tier'] = $validated['rond_tier'];
        }
        if ($request->filled('rond_pattern')) {
            $updateData['rond_pattern'] = trim($validated['rond_pattern']);
        }

        if (! empty($validated['phone_prefix_id'])) {
            $updateData['phone_prefix_id'] = (int) $validated['phone_prefix_id'];
        }

        // Handle user assignment changes
        if ($newUserId !== $number->user_id) {
            $updateData['user_id'] = $newUserId;
            if ($newUserId) {
                $updateData['status'] = PhoneNumberStatus::Allocated;
                $updateData['allocated_at'] = now();
            } else {
                $updateData['status'] = PhoneNumberStatus::Available;
                $updateData['allocated_at'] = null;
            }
        }

        $number->update($updateData);

        AuditLog::record('number_updated', $number, $request->user(), [
            'number' => $number->number,
            'formatted_number' => $number->fresh()->formatted_number,
            'old' => $oldValues,
            'new' => $updateData,
        ]);

        return back()->with('success', __('controllers.admin.phone_numbers.number_updated', ['number' => $number->fresh()->formatted_number]));
    }

    /**
     * Delete a phone number permanently from system.
     */
    public function destroy(Request $request, PhoneNumber $number): RedirectResponse
    {
        $formatted = $number->formatted_number;

        AuditLog::record('number_deleted', $number, $request->user(), [
            'number' => $number->number,
            'formatted_number' => $formatted,
            'user_id' => $number->user_id,
        ]);

        $number->delete();

        return back()->with('success', __('controllers.admin.phone_numbers.number_deleted', ['number' => $formatted]));
    }

    /**
     * Update status of a number (suspend, activate, etc.)
     */
    public function updateStatus(Request $request, PhoneNumber $number): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:available,allocated,reserved,suspended,inactive'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $newStatus = PhoneNumberStatus::from($validated['status']);

        $this->allocationService->updateStatus(
            phoneNumber: $number,
            newStatus: $newStatus,
            actor: $request->user(),
            reason: $validated['reason'] ?? __('controllers.admin.phone_numbers.status_reason_admin')
        );

        return back()->with('success', __('controllers.admin.phone_numbers.status_updated', ['number' => $number->formatted_number, 'status' => $newStatus->label()]));
    }

    /**
     * Assign an available number to a user.
     */
    public function assign(Request $request, PhoneNumber $number): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'display_title' => ['nullable', 'string', 'max:100'],
        ]);

        $targetUser = User::findOrFail($validated['user_id']);

        if ($number->type === PhoneNumberType::PlatformService && ! $targetUser->isAdmin()) {
            return back()->with('error', __('controllers.admin.phone_numbers.platform_service_cannot_allocate'));
        }

        $title = ! empty($validated['display_title'])
            ? trim($validated['display_title'])
            : ($number->display_title ?: null);

        try {
            if ($number->type === PhoneNumberType::UserStandard) {
                $this->allocationService->allocateStandardNumberToUser(
                    user: $targetUser,
                    preferredNumber: $number->number,
                    displayTitle: $title,
                    actor: $request->user()
                );
            } else {
                $this->allocationService->allocateServiceNumberToUser(
                    user: $targetUser,
                    serviceNumber: $number->number,
                    displayTitle: $title ?: __('controllers.api.v1_numbers.default_title'),
                    actor: $request->user()
                );
            }

            return back()->with('success', __('controllers.admin.phone_numbers.allocated_flash', ['number' => $number->formatted_number, 'user' => $targetUser->name]));
        } catch (NumberAllocationException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Release number back to pool.
     */
    public function release(Request $request, PhoneNumber $number): RedirectResponse
    {
        $this->allocationService->releaseNumber(
            phoneNumber: $number,
            actor: $request->user(),
            reason: __('controllers.admin.phone_numbers.release_reason_admin')
        );

        return back()->with('success', __('controllers.admin.phone_numbers.released_flash', ['number' => $number->formatted_number]));
    }

    /**
     * Dedicated full-featured edit page for a line with consumption monitoring.
     */
    public function edit(PhoneNumber $number): Response
    {
        $number->load(['user', 'prefix']);

        $sentCount = \App\Models\Message::where('phone_number_id', $number->id)
            ->where('direction', \App\Enums\MessageDirection::Outbound)
            ->count();

        $receivedCount = \App\Models\Message::where('phone_number_id', $number->id)
            ->where('direction', \App\Enums\MessageDirection::Inbound)
            ->count();

        $voiceCallsCount = \App\Models\VoiceCall::where('phone_number_id', $number->id)->count();

        $lastActivity = \App\Models\Message::where('phone_number_id', $number->id)
            ->latest('server_received_at')
            ->value('server_received_at');

        $users = User::select('id', 'name', 'account_id')->limit(100)->get();
        $prefixes = \App\Models\PhonePrefix::select('id', 'prefix', 'name')->get();

        return Inertia::render('Admin/Numbers/Edit', [
            'number' => [
                'id' => $number->id,
                'number' => $number->number,
                'formatted_number' => $number->formatted_number,
                'type' => $number->type->value,
                'type_label' => $number->type->label(),
                'status' => $number->status->value,
                'status_label' => $number->status->label(),
                'display_title' => $number->display_title,
                'accepts_incoming_sms' => (bool) $number->accepts_incoming_sms,
                'rond_tier' => $number->rond_tier ?? 'regular',
                'rond_pattern' => $number->rond_pattern,
                'phone_prefix_id' => $number->phone_prefix_id,
                'user_id' => $number->user_id,
                'user' => $number->user ? [
                    'id' => $number->user->id,
                    'account_id' => $number->user->account_id,
                    'name' => $number->user->name,
                ] : null,
                'allocated_at' => $number->allocated_at?->format('Y/m/d H:i'),
                'created_at' => $number->created_at->format('Y/m/d H:i'),
            ],
            'metrics' => [
                'sent_sms_count' => $sentCount,
                'received_sms_count' => $receivedCount,
                'voice_calls_count' => $voiceCallsCount,
                'last_activity' => $lastActivity ? \Carbon\Carbon::parse($lastActivity)->diffForHumans() : 'بدون فعالیت',
            ],
            'users' => $users,
            'prefixes' => $prefixes,
        ]);
    }
}