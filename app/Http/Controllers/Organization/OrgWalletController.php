<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgWalletController extends Controller
{
    /**
     * Display corporate wallet, balance, and staff usage breakdown.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();
        $wallet = $org->getOrCreateWallet();

        $transactions = $wallet->transactions()
            ->with('user')
            ->latest('id')
            ->paginate(20)
            ->through(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'balance_before' => (float) $tx->balance_before,
                'balance_after' => (float) $tx->balance_after,
                'reference_id' => $tx->reference_id,
                'description' => $tx->description,
                'user_name' => $tx->user?->name ?: __('organization.wallet.title'),
                'is_credit' => $tx->isCredit(),
                'created_at' => $tx->created_at?->format('Y/m/d H:i:s'),
            ]);

        // Staff usage report (aggregate debits by staff member)
        $memberUsage = $wallet->transactions()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, count(id) as total_tx, sum(amount) as total_spent')
            ->groupBy('user_id')
            ->with('user')
            ->get()
            ->map(fn ($r) => [
                'user_id' => $r->user_id,
                'user_name' => $r->user?->name,
                'email' => $r->user?->email,
                'total_tx' => (int) $r->total_tx,
                'total_spent' => (float) $r->total_spent,
            ]);

        $isAdmin = (bool) ($user->isAdmin() || $org->members()->where('user_id', $user->id)->where('role', 'admin')->exists());

        return Inertia::render('Organization/Wallet/Index', [
            'balance' => (string) $wallet->balance,
            'personal_balance' => (string) $user->getOrCreateWallet()->balance,
            'is_admin' => $isAdmin,
            'currency' => $wallet->currency,
            'transactions' => $transactions,
            'member_usage' => $memberUsage,
        ]);
    }

    /**
     * Deposit funds into corporate wallet.
     */
    public function deposit(Request $request): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();
        $wallet = $org->getOrCreateWallet();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
        ]);

        $wallet->credit(
            amount: (string) $validated['amount'],
            type: 'deposit',
            description: __('organization.wallet.deposit'),
            userId: $user->id
        );

        return back()->with('success', __('organization.dashboard.charge_org_wallet'));
    }

    /**
     * Transfer funds from admin personal wallet to organization corporate wallet.
     */
    public function transferFromPersonal(Request $request): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        // Strictly allow only organization admin or superadmin
        $isAdmin = (bool) ($user->isAdmin() || $org->members()->where('user_id', $user->id)->where('role', 'admin')->exists());
        if (! $isAdmin) {
            abort(403, __('organization.wallet.admin_only_transfer'));
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.0001', 'max:1000000'],
        ]);

        $amount = (string) $validated['amount'];
        $personalWallet = $user->getOrCreateWallet();
        $orgWallet = $org->getOrCreateWallet();

        if (bccomp((string) $personalWallet->balance, $amount, 4) < 0) {
            return back()->with('error', __('organization.wallet.insufficient_personal_balance'));
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($personalWallet, $orgWallet, $amount, $user, $org) {
            // 1. Debit admin personal wallet
            $personalWallet->debit(
                amount: $amount,
                type: 'transfer_to_org',
                description: __('organization.wallet.tx_transfer_to_org', ['org' => $org->name]),
                userId: $user->id,
                metadata: ['organization_id' => $org->id]
            );

            // 2. Credit corporate organization wallet
            $orgWallet->credit(
                amount: $amount,
                type: 'transfer_from_personal',
                description: __('organization.wallet.tx_transfer_from_personal', ['user' => $user->name]),
                userId: $user->id,
                metadata: ['from_user_id' => $user->id, 'personal_wallet_id' => $personalWallet->id]
            );
        });

        return back()->with('success', __('organization.wallet.transfer_success', ['amount' => $amount]));
    }
}
