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
                        $uq->where('username', 'like', "%{$search}%")
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

        $users = User::select('id', 'name', 'username')
            ->orderBy('name')
            ->get();

        $prefixes = \App\Models\PhonePrefix::active()
            ->get(['id', 'prefix', 'name', 'is_default']);

        $stats = [
            'total' => PhoneNumber::count(),
            'allocated' => PhoneNumber::where('status', PhoneNumberStatus::Allocated)->count(),
            'available' => PhoneNumber::where('status', PhoneNumberStatus::Available)->count(),
            'suspended' => PhoneNumber::where('status', PhoneNumberStatus::Suspended)->count(),
        ];

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
                'count.required' => 'تعیین تعداد خطوط الزامی است.',
                'digits.required' => 'تعیین طول ارقام شماره الزامی است.',
                'digits.min' => 'حداقل طول مجاز ارقام ۴ رقم می‌باشد.',
                'digits.max' => 'حداکثر طول مجاز ارقام ۱۱ رقم می‌باشد.',
            ]);

            $type = PhoneNumberType::from($validated['type']);
            $digits = (int) $validated['digits'];

            $generatedCount = $this->allocationService->generateBatchAvailableNumbers(
                count: (int) $validated['count'],
                type: $type,
                digits: $digits
            );

            return back()->with('success', "تعداد {$generatedCount} خط سازمانی با طول {$digits} رقم با موفقیت در استخر شماره‌های آزاد سامانه تولید و ثبت گردید.");
        }

        // Single number creation
        $validated = $request->validate([
            'number' => ['required', 'string', 'regex:/^[0-9]{4,11}$/', 'unique:phone_numbers,number'],
            'phone_prefix_id' => ['nullable', 'exists:phone_prefixes,id'],
            'type' => ['required', 'string', 'in:user_standard,user_service,platform_service'],
            'display_title' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'exists:users,id'],
            'accepts_incoming_sms' => ['nullable', 'boolean'],
        ], [
            'number.required' => 'وارد نمودن مقدار عددی شماره الزامی است.',
            'number.regex' => 'قالب شماره باید بین ۴ تا ۱۱ رقم عددی باشد.',
            'number.unique' => 'این شماره قبلاً در سامانه ثبت شده است.',
        ]);

        $type = PhoneNumberType::from($validated['type']);
        $user = ! empty($validated['user_id']) ? User::find($validated['user_id']) : null;

        // Platform service numbers cannot belong to regular users
        if ($type === PhoneNumberType::PlatformService && $user && ! $user->isAdmin()) {
            return back()->with('error', 'سرشماره‌های اختصاصی سامانه قابل تخصیص به کاربران عادی نمی‌باشند.');
        }

        $status = $user ? PhoneNumberStatus::Allocated : PhoneNumberStatus::Available;
        if ($type === PhoneNumberType::PlatformService && ! $user) {
            // Platform lines managed directly by system are active
            $status = PhoneNumberStatus::Allocated;
        }

        $prefixId = ! empty($validated['phone_prefix_id'])
            ? (int) $validated['phone_prefix_id']
            : \App\Models\PhonePrefix::getDefaultPrefix()->id;

        $phone = PhoneNumber::create([
            'phone_prefix_id' => $prefixId,
            'number' => trim($validated['number']),
            'type' => $type,
            'status' => $status,
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
            'display_title' => $phone->display_title,
            'user_id' => $phone->user_id,
        ]);

        return back()->with('success', "خط سازمانی {$phone->formatted_number} با موفقیت در سامانه ثبت گردید.");
    }

    /**
     * Update details of an existing phone number.
     */
    public function update(Request $request, PhoneNumber $number): RedirectResponse
    {
        $validated = $request->validate([
            'display_title' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:user_standard,user_service,platform_service'],
            'phone_prefix_id' => ['nullable', 'exists:phone_prefixes,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'accepts_incoming_sms' => ['nullable', 'boolean'],
        ], [
            'display_title.max' => 'عنوان نمایشی نباید بیش از ۱۰۰ کاراکتر باشد.',
            'type.required' => 'تعیین نوع خط الزامی است.',
            'type.in' => 'نوع خط انتخاب شده نامعتبر است.',
        ]);

        $type = PhoneNumberType::from($validated['type']);
        $newUserId = ! empty($validated['user_id']) ? (int) $validated['user_id'] : null;
        $user = $newUserId ? User::find($newUserId) : null;

        if ($type === PhoneNumberType::PlatformService && $user && ! $user->isAdmin()) {
            return back()->with('error', 'سرشماره‌های اختصاصی سامانه قابل تخصیص به کاربران عادی نمی‌باشند.');
        }

        $oldValues = [
            'display_title' => $number->display_title,
            'type' => $number->type->value,
            'user_id' => $number->user_id,
            'phone_prefix_id' => $number->phone_prefix_id,
            'accepts_incoming_sms' => $number->accepts_incoming_sms,
        ];

        $updateData = [
            'display_title' => $validated['display_title'] ? trim($validated['display_title']) : null,
            'type' => $type,
            'accepts_incoming_sms' => $request->has('accepts_incoming_sms') ? $request->boolean('accepts_incoming_sms') : true,
        ];

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

        return back()->with('success', "اطلاعات خط سازمانی {$number->fresh()->formatted_number} با موفقیت ویرایش و ذخیره شد.");
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

        return back()->with('success', "خط سازمانی {$formatted} با موفقیت از سیستم حذف گردید.");
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
            reason: $validated['reason'] ?? 'تغییر وضعیت توسط راهبر سامانه'
        );

        return back()->with('success', "وضعیت خط سازمانی {$number->formatted_number} با موفقیت به «{$newStatus->label()}» تغییر یافت.");
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
            return back()->with('error', 'سرشماره‌های اختصاصی سامانه قابل تخصیص به کاربران عادی نمی‌باشند.');
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
                    displayTitle: $title ?: 'سرشماره سازمانی',
                    actor: $request->user()
                );
            }

            return back()->with('success', "خط سازمانی {$number->formatted_number} با موفقیت به کاربر {$targetUser->name} تخصیص یافت.");
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
            reason: 'آزادسازی توسط راهبر ارشد سامانه'
        );

        return back()->with('success', "خط سازمانی {$number->formatted_number} با موفقیت آزاد شده و به استخر شماره‌ها بازگشت.");
    }
}
