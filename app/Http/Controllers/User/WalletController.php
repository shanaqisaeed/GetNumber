<?php

namespace App\Http\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Display user wallet overview, balance, exchange rate and ledger transactions.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $wallet = $this->walletService->getWallet($user);

        $query = $wallet->transactions();

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $transactions = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type->value,
                'type_label' => $tx->type->label(),
                'type_badge' => $tx->type->badgeClasses(),
                'is_credit' => $tx->type->isCredit(),
                'amount' => (string) $tx->amount,
                'balance_before' => (string) $tx->balance_before,
                'balance_after' => (string) $tx->balance_after,
                'reference_id' => $tx->reference_id,
                'description' => $tx->description,
                'created_at' => $tx->created_at->format('Y/m/d H:i'),
                'created_at_relative' => $tx->created_at->diffForHumans(),
            ]);

        $trxRate = SystemSetting::getTrxToCoinRate();
        $numberPrice = SystemSetting::getNumberPriceCoin();

        // Calculate TRX equivalent of current balance
        $trxEquivalent = bcmul((string) $wallet->balance, $trxRate, 4);

        $transactionTypes = collect(WalletTransactionType::cases())->map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ]);

        return Inertia::render('User/Wallet/Index', [
            'wallet' => [
                'id' => $wallet->id,
                'balance' => (string) $wallet->balance,
                'currency' => $wallet->currency,
                'trx_equivalent' => $trxEquivalent,
            ],
            'rates' => [
                'trx_to_coin_rate' => $trxRate,
                'number_price_coin' => $numberPrice,
            ],
            'transactions' => $transactions,
            'transaction_types' => $transactionTypes,
            'filters' => $request->only(['type', 'search']),
        ]);
    }
}

