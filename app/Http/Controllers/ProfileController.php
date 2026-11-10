<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form with wallet & transaction integration.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $wallet = $user->getOrCreateWallet();

        $transactions = $wallet->transactions()
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'type_label' => $t->type->label(),
                'is_credit' => $t->type->isCredit(),
                'amount' => (string) $t->amount,
                'balance_after' => (string) $t->balance_after,
                'reference' => $t->reference,
                'created_at' => $t->created_at->format('Y/m/d H:i'),
                'created_ago' => $t->created_at->diffForHumans(),
            ]);

        $activeGateways = \App\Models\PaymentGateway::query()
            ->where('is_active', true)
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'driver' => $g->driver,
                'supported_currencies' => $g->supported_currencies ?? ['TRX'],
                'supported_networks' => $g->supported_networks ?? ['TRON'],
            ]);

        $pendingDeposit = \App\Models\CryptoDeposit::where('user_id', $user->id)
            ->whereIn('status', [
                \App\Enums\PaymentStatus::PendingTransfer,
                \App\Enums\PaymentStatus::TxSubmitted,
                \App\Enums\PaymentStatus::Verifying,
            ])
            ->latest('id')
            ->first();

        $pendingDepositData = null;
        if ($pendingDeposit) {
            $pendingDepositData = [
                'tracking_code' => $pendingDeposit->tracking_code,
                'currency' => $pendingDeposit->currency,
                'network' => $pendingDeposit->network,
                'requested_amount' => (string) $pendingDeposit->requested_amount,
                'coin_amount' => (string) $pendingDeposit->coin_amount,
                'deposit_address' => $pendingDeposit->deposit_address,
                'deposit_tag' => $pendingDeposit->deposit_tag,
                'status' => $pendingDeposit->status->value,
                'status_label' => $pendingDeposit->status->label(),
                'status_badge' => $pendingDeposit->status->badgeClasses(),
                'tx_hash' => $pendingDeposit->tx_hash,
                'can_submit_tx' => $pendingDeposit->canSubmitTx(),
                'is_completed' => $pendingDeposit->isCompleted(),
                'is_expired' => $pendingDeposit->isExpired(),
                'created_at' => $pendingDeposit->created_at->format('Y/m/d H:i'),
            ];
        }

        $identities = $user->identities()->get()->map(fn ($id) => [
            'id' => $id->id,
            'provider' => $id->provider,
            'provider_label' => $id->providerLabel(),
            'provider_id' => $id->provider_id,
            'provider_email' => $id->provider_email,
            'linked_at' => $id->linked_at ? $id->linked_at->format('Y/m/d H:i') : null,
        ]);

        $authSettings = [
            'username_enabled' => \App\Models\SystemSetting::isAuthProviderEnabled('username'),
            'email_enabled' => \App\Models\SystemSetting::isAuthProviderEnabled('email'),
            'ldap_enabled' => \App\Models\SystemSetting::isAuthProviderEnabled('ldap'),
            'keycloak_enabled' => \App\Models\SystemSetting::isAuthProviderEnabled('keycloak'),
        ];

        $sessionService = app(\App\Services\Auth\SessionManagerService::class);
        $sessions = $sessionService->getSessions($user, $request);

        $activeNumbersCount = $user->phoneNumbers()
            ->where('status', \App\Enums\PhoneNumberStatus::Allocated)
            ->count();
        $maxLinesQuota = (int) \App\Models\SystemSetting::get(
            'max_lines_per_user',
            \App\Services\PhoneNumberAllocationService::DEFAULT_MAX_QUOTA_PER_USER
        );

        return Inertia::render('Profile/Edit', [
            'status' => session('status'),
            'initialTab' => $request->query('tab', 'info'),
            'initialView' => $request->query('view', 'charge'),
            'wallet' => [
                'balance' => (string) $wallet->balance,
            ],
            'stats' => [
                'active_lines' => $activeNumbersCount,
                'max_lines' => $maxLinesQuota,
                'active_sessions' => count($sessions),
                'connected_identities' => $identities->count(),
            ],
            'sessions' => $sessions,
            'transactions' => $transactions,
            'activeGateways' => $activeGateways,
            'pendingDeposit' => $pendingDepositData,
            'identities' => $identities,
            'authSettings' => $authSettings,
            'hasPassword' => !empty($user->password),
            'keycloakLinkUrl' => route('auth.keycloak.redirect'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'name' => $request->validated('name'),
        ]);

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Safely release any active numbers allocated to this user and purge private messages
        $allocationService = app(\App\Contracts\PhoneNumberAllocationServiceInterface::class);
        foreach ($user->phoneNumbers as $phoneNumber) {
            $allocationService->releaseNumber($phoneNumber, $user, 'آزادسازی خودکار به دلیل حذف حساب کاربری');
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');

    }
}
