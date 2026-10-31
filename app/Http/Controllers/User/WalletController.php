<?php

namespace App\Http\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
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
     * Redirect to the standalone transactions history page.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('wallet.transactions');
    }

    /**
     * Standalone user transactions history page with filters, integer amounts, and pagination.
     */
    public function transactions(Request $request): Response
    {
        $user = $request->user();
        $wallet = $user->getOrCreateWallet();

        $query = WalletTransaction::where('user_id', $user->id);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('reference_id', 'like', "%{$search}%");
            });
        }

        $transactions = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'type_label' => $t->type->label(),
                'is_credit' => $t->type->isCredit(),
                'badge_classes' => $t->type->badgeClasses(),
                'amount' => (int) round((float) $t->amount),
                'balance_before' => (int) round((float) $t->balance_before),
                'balance_after' => (int) round((float) $t->balance_after),
                'reference_id' => $t->reference_id,
                'description' => $t->description,
                'created_at' => $t->created_at->format('Y/m/d H:i'),
            ]);

        $types = collect(WalletTransactionType::cases())->map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ]);

        return Inertia::render('User/Wallet/Transactions', [
            'wallet' => [
                'id' => $wallet->id,
                'balance' => (int) round((float) $wallet->balance),
                'currency' => $wallet->currency,
            ],
            'transactions' => $transactions,
            'types' => $types,
            'filters' => $request->only(['search', 'type']),
        ]);
    }
}
