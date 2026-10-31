<?php

namespace App\Http\Controllers\User;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\NumberAllocationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\AllocatePhoneNumberRequest;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PhoneNumberController extends Controller
{
    public function __construct(
        protected PhoneNumberAllocationServiceInterface $allocationService,
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Display a listing of user's allocated phone numbers.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = PhoneNumber::with('prefix')->where('user_id', $user->id);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('display_title', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $numbers = $query->latest('allocated_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($phone) => [
                'id' => $phone->id,
                'number' => $phone->number,
                'formatted_number' => $phone->formatted_number,
                'effective_title' => $phone->effective_title,
                'display_title' => $phone->display_title,
                'type' => $phone->type->value,
                'type_label' => $phone->type->label(),
                'type_badge' => $phone->type->badgeClasses(),
                'status' => $phone->status->value,
                'status_label' => $phone->status->label(),
                'status_badge' => $phone->status->badgeClasses(),
                'allocated_at' => $phone->allocated_at?->format('Y/m/d H:i'),
                'created_at' => $phone->created_at->format('Y/m/d'),
            ]);

        $activeCount = PhoneNumber::where('user_id', $user->id)
            ->where('status', \App\Enums\PhoneNumberStatus::Allocated)
            ->count();

        return Inertia::render('User/Numbers/Index', [
            'numbers' => $numbers,
            'filters' => $request->only(['search', 'type']),
            'quota' => [
                'active' => $activeCount,
                'max' => \App\Services\PhoneNumberAllocationService::DEFAULT_MAX_QUOTA_PER_USER,
            ],
            'wallet' => [
                'balance' => (string) $user->getOrCreateWallet()->balance,
                'number_price_coin' => SystemSetting::getNumberPriceCoin(),
            ],
            'default_prefix' => \App\Models\PhonePrefix::getDefaultPrefixString(),
            'active_prefixes' => \App\Models\PhonePrefix::active()->get(['id', 'prefix', 'name', 'is_default']),
        ]);
    }

    /**
     * Allocate a new standard number for the authenticated user with atomic wallet charging.
     */
    public function store(AllocatePhoneNumberRequest $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $numberPrice = SystemSetting::getNumberPriceCoin();
        $purchaseRef = 'PURCHASE-' . Str::uuid();

        try {
            // 1. Atomically charge wallet (verifies balance under lock, throws InsufficientWalletBalanceException if low)
            $tx = $this->walletService->chargeForNumberPurchase(
                user: $user,
                amount: $numberPrice,
                referenceId: $purchaseRef,
                description: 'خرید و فعال‌سازی خط سازمانی جدید',
                metadata: [
                    'preferred_number' => $request->validated('preferred_number'),
                    'display_title' => $request->validated('display_title'),
                ]
            );

            // 2. Allocate number
            try {
                $phone = $this->allocationService->allocateStandardNumberToUser(
                    user: $user,
                    preferredNumber: $request->validated('preferred_number'),
                    displayTitle: $request->validated('display_title')
                );

                $tx->update([
                    'metadata' => array_merge($tx->metadata ?? [], [
                        'phone_number_id' => $phone->id,
                        'number' => $phone->number,
                    ]),
                ]);

                return back()->with('success', "خط سازمانی جدید با شماره {$phone->formatted_number} با موفقیت خریداری و به حساب شما تخصیص داده شد (کسر {$numberPrice} کوین).");
            } catch (\Throwable $allocationException) {
                // In case allocation fails, automatically refund coins into the ledger
                $this->walletService->refund(
                    user: $user,
                    amount: $numberPrice,
                    referenceId: 'REFUND-' . $purchaseRef,
                    description: 'استرداد خودکار وجه به علت خطا در تخصیص شماره'
                );

                throw $allocationException;
            }
        } catch (InsufficientWalletBalanceException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['wallet' => [$e->getMessage()]],
                ], 422);
            }

            return redirect()->route('wallet.index')->with('error', $e->getMessage() . " برای خرید خط به {$numberPrice} کوین اعتبار نیاز دارید. لطفاً ابتدا کیف پول خود را شارژ فرمایید.");
        } catch (NumberAllocationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'خطایی در فرآیند تخصیص خط رخ داد. در صورت کسر وجه، مبلغ بلافاصله به کیف پول مسترد شد.');
        }
    }

    /**
     * Update display title of an allocated phone number.
     */
    public function update(Request $request, PhoneNumber $number): RedirectResponse
    {
        $this->authorize('update', $number);

        $validated = $request->validate([
            'display_title' => ['nullable', 'string', 'max:100'],
        ], [
            'display_title.max' => 'عنوان نمایشی نباید بیش از ۱۰۰ کاراکتر باشد.',
        ]);

        $number->update([
            'display_title' => $validated['display_title'] ? trim($validated['display_title']) : null,
        ]);

        return back()->with('success', 'عنوان نمایشی شماره با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Release user's allocated number back to the available pool.
     */
    public function destroy(Request $request, PhoneNumber $number): RedirectResponse
    {
        $this->authorize('release', $number);

        try {
            $this->allocationService->releaseNumber(
                phoneNumber: $number,
                actor: $request->user(),
                reason: 'آزادسازی توسط کاربر سازمانی'
            );

            return back()->with('success', "خط سازمانی با شماره {$number->formatted_number} با موفقیت آزاد شد و به استخر شماره‌ها بازگشت.");
        } catch (\Throwable $e) {
            return back()->with('error', 'خطا در فرآیند آزادسازی خط. لطفاً بعداً تلاش فرمایید.');
        }
    }
}
