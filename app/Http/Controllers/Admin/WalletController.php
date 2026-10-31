<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\WalletServiceInterface;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidWalletTransactionException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Display financial overview, user wallets, circulation statistics, and exchange rates.
     */
    public function index(Request $request): Response
    {
        $query = User::with('wallet');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(15)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->role->value,
                'status' => $u->status->value,
                'wallet_balance' => (string) ($u->wallet?->balance ?? '0.0000'),
                'created_at' => $u->created_at->format('Y/m/d'),
            ]);

        $totalCoinsInCirculation = Wallet::sum('balance') ?? '0.0000';
        $totalWalletsCount = Wallet::count();
        $totalTransactionsCount = WalletTransaction::count();

        $trxRate = SystemSetting::getTrxToCoinRate();
        $numberPrice = SystemSetting::getNumberPriceCoin();

        return Inertia::render('Admin/Wallet/Index', [
            'users' => $users,
            'stats' => [
                'total_circulation' => number_format((float) $totalCoinsInCirculation, 4, '.', ''),
                'total_wallets' => $totalWalletsCount,
                'total_transactions' => $totalTransactionsCount,
            ],
            'rates' => [
                'trx_to_coin_rate' => $trxRate,
                'number_price_coin' => $numberPrice,
            ],
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Financial Ledger view: all system wallet transactions with filtering.
     */
    public function transactions(Request $request): Response
    {
        $query = WalletTransaction::with(['user', 'wallet']);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%"));
            });
        }

        $transactions = $query->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($tx) => [
                'id' => $tx->id,
                'user' => [
                    'id' => $tx->user->id,
                    'name' => $tx->user->name,
                    'username' => $tx->user->username,
                ],
                'type' => $tx->type->value,
                'type_label' => $tx->type->label(),
                'type_badge' => $tx->type->badgeClasses(),
                'is_credit' => $tx->type->isCredit(),
                'amount' => (string) $tx->amount,
                'balance_before' => (string) $tx->balance_before,
                'balance_after' => (string) $tx->balance_after,
                'reference_id' => $tx->reference_id,
                'description' => $tx->description,
                'metadata' => $tx->metadata,
                'created_at' => $tx->created_at->format('Y/m/d H:i'),
                'created_at_relative' => $tx->created_at->diffForHumans(),
            ]);

        $transactionTypes = collect(WalletTransactionType::cases())->map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ]);

        return Inertia::render('Admin/Wallet/Transactions', [
            'transactions' => $transactions,
            'transaction_types' => $transactionTypes,
            'filters' => $request->only(['type', 'user_id', 'search']),
        ]);
    }

    /**
     * Manually credit user account with a mandatory reason.
     */
    public function credit(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'amount.required' => 'وارد کردن مبلغ الزامی است.',
            'amount.gt' => 'مبلغ باید بزرگتر از صفر باشد.',
            'reason.required' => 'ثبت دلیل تغییر موجودی الزامی است.',
        ]);

        try {
            $this->walletService->adminCredit(
                user: $user,
                amount: (string) $validated['amount'],
                admin: $request->user(),
                reason: $validated['reason']
            );

            return back()->with('success', "مبلغ {$validated['amount']} کوین با موفقیت به حساب کاربر «{$user->name}» افزوده شد.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'خطا در افزایش اعتبار کاربر.');
        }
    }

    /**
     * Manually debit user account with a mandatory reason.
     */
    public function debit(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'amount.required' => 'وارد کردن مبلغ الزامی است.',
            'amount.gt' => 'مبلغ باید بزرگتر از صفر باشد.',
            'reason.required' => 'ثبت دلیل تغییر موجودی الزامی است.',
        ]);

        try {
            $this->walletService->adminDebit(
                user: $user,
                amount: (string) $validated['amount'],
                admin: $request->user(),
                reason: $validated['reason']
            );

            return back()->with('success', "مبلغ {$validated['amount']} کوین با موفقیت از حساب کاربر «{$user->name}» کسر گردید.");
        } catch (InsufficientWalletBalanceException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'خطا در کسر اعتبار کاربر.');
        }
    }

    /**
     * Create a reversal transaction to correct a previous transaction.
     */
    public function reverse(Request $request, WalletTransaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'ثبت دلیل اصلاح تراکنش الزامی است.',
        ]);

        try {
            $this->walletService->reverseTransaction(
                originalTransaction: $transaction,
                admin: $request->user(),
                reason: $validated['reason']
            );

            return back()->with('success', "تراکنش اصلاحی (Reversal) با موفقیت ثبت و اعمال گردید.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'خطا در ثبت تراکنش اصلاحی.');
        }
    }

    /**
     * Update exchange rate and phone number price settings.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'trx_to_coin_rate' => ['required', 'numeric', 'gt:0'],
            'number_price_coin' => ['required', 'numeric', 'gte:0'],
        ], [
            'trx_to_coin_rate.required' => 'نرخ ترون الزامی است.',
            'trx_to_coin_rate.gt' => 'نرخ ترون باید مثبت باشد.',
            'number_price_coin.required' => 'قیمت هر شماره الزامی است.',
            'number_price_coin.gte' => 'قیمت شماره نمی‌تواند منفی باشد.',
        ]);

        $admin = $request->user();

        $oldTrxRate = SystemSetting::getTrxToCoinRate();
        $oldPrice = SystemSetting::getNumberPriceCoin();

        SystemSetting::set('trx_to_coin_rate', number_format((float) $validated['trx_to_coin_rate'], 4, '.', ''), 'نرخ تبدیل TRX به COIN');
        SystemSetting::set('number_price_coin', number_format((float) $validated['number_price_coin'], 4, '.', ''), 'قیمت پایه هر شماره به COIN');

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'admin.settings.financial_updated',
            'entity_type' => SystemSetting::class,
            'entity_id' => 1,
            'payload' => [
                'trx_to_coin_rate' => [
                    'before' => $oldTrxRate,
                    'after' => $validated['trx_to_coin_rate'],
                ],
                'number_price_coin' => [
                    'before' => $oldPrice,
                    'after' => $validated['number_price_coin'],
                ],
            ],
            'ip_address' => $request->ip() ?? '127.0.0.1',
        ]);

        return back()->with('success', 'تنظیمات مالی با موفقیت به‌روزرسانی شد.');
    }
}

