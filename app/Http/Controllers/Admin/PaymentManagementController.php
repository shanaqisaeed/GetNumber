<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\PaymentServiceInterface;
use App\DTOs\PaymentVerificationResult;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentManagementController extends Controller
{
    public function __construct(
        protected PaymentServiceInterface $paymentService
    ) {}

    /**
     * Display listing of all crypto deposits with filtering and summary metrics.
     */
    public function index(Request $request): Response
    {
        $query = CryptoDeposit::with(['user', 'gateway']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('gateway')) {
            $query->where('gateway_driver', $request->input('gateway'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('tracking_code', 'like', "%{$search}%")
                    ->orWhere('tx_hash', 'like', "%{$search}%")
                    ->orWhere('deposit_address', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%")->orWhereHas('identities', fn ($iq) => $iq->where('provider_id', 'like', "%{$search}%"))->orWhere('account_id', 'like', "%{$search}%"));
            });
        }

        $deposits = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($d) => [
                'id' => $d->id,
                'user' => [
                    'id' => $d->user->id,
                    'name' => $d->user->name,
                    'username' => $d->user->username,
                ],
                'tracking_code' => $d->tracking_code,
                'gateway_driver' => $d->gateway_driver,
                'currency' => $d->currency,
                'network' => $d->network,
                'requested_amount' => (string) $d->requested_amount,
                'actual_amount' => (string) ($d->actual_amount ?? $d->requested_amount),
                'coin_amount' => (string) $d->coin_amount,
                'deposit_address' => $d->deposit_address,
                'tx_hash' => $d->tx_hash,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'status_badge' => $d->status->badgeClasses(),
                'attempts' => $d->attempts,
                'failure_reason' => $d->failure_reason,
                'created_at' => $d->created_at->format('Y/m/d H:i'),
                'completed_at' => $d->completed_at?->format('Y/m/d H:i'),
            ]);

        $totalCompletedTrx = CryptoDeposit::where('status', PaymentStatus::Completed->value)->sum('actual_amount') ?? '0.0000';
        $totalCompletedCoins = CryptoDeposit::where('status', PaymentStatus::Completed->value)->sum('coin_amount') ?? '0.0000';
        $pendingCount = CryptoDeposit::whereIn('status', [PaymentStatus::PendingTransfer->value, PaymentStatus::TxSubmitted->value, PaymentStatus::Verifying->value])->count();
        $completedCount = CryptoDeposit::where('status', PaymentStatus::Completed->value)->count();

        $statuses = collect(PaymentStatus::cases())->map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ]);

        $gateways = PaymentGateway::all(['id', 'name', 'driver']);

        return Inertia::render('Admin/Payments/Index', [
            'deposits' => $deposits,
            'stats' => [
                'total_completed_trx' => number_format((float) $totalCompletedTrx, 4, '.', ''),
                'total_completed_coins' => number_format((float) $totalCompletedCoins, 4, '.', ''),
                'pending_count' => $pendingCount,
                'completed_count' => $completedCount,
            ],
            'statuses' => $statuses,
            'gateways' => $gateways,
            'filters' => $request->only(['status', 'gateway', 'user_id', 'search']),
        ]);
    }

    /**
     * Show detailed crypto deposit record with logs and raw verification responses.
     */
    public function show(CryptoDeposit $deposit): Response
    {
        $deposit->load(['user', 'gateway']);

        return Inertia::render('Admin/Payments/Show', [
            'deposit' => [
                'id' => $deposit->id,
                'user' => [
                    'id' => $deposit->user->id,
                    'name' => $deposit->user->name,
                    'username' => $deposit->user->username,
                ],
                'tracking_code' => $deposit->tracking_code,
                'gateway_driver' => $deposit->gateway_driver,
                'gateway_name' => $deposit->gateway?->name ?? $deposit->gateway_driver,
                'currency' => $deposit->currency,
                'network' => $deposit->network,
                'requested_amount' => (string) $deposit->requested_amount,
                'actual_amount' => (string) ($deposit->actual_amount ?? $deposit->requested_amount),
                'coin_amount' => (string) $deposit->coin_amount,
                'trx_to_coin_rate' => (string) $deposit->trx_to_coin_rate,
                'deposit_address' => $deposit->deposit_address,
                'deposit_tag' => $deposit->deposit_tag,
                'tx_hash' => $deposit->tx_hash,
                'status' => $deposit->status->value,
                'status_label' => $deposit->status->label(),
                'status_badge' => $deposit->status->badgeClasses(),
                'failure_reason' => $deposit->failure_reason,
                'attempts' => $deposit->attempts,
                'last_checked_at' => $deposit->last_checked_at?->format('Y/m/d H:i:s'),
                'next_check_at' => $deposit->next_check_at?->format('Y/m/d H:i:s'),
                'expires_at' => $deposit->expires_at?->format('Y/m/d H:i:s'),
                'completed_at' => $deposit->completed_at?->format('Y/m/d H:i:s'),
                'created_at' => $deposit->created_at->format('Y/m/d H:i:s'),
                'metadata' => $deposit->metadata,
            ],
        ]);
    }

    /**
     * Trigger manual verification against the blockchain or exchange gateway.
     */
    public function verify(Request $request, CryptoDeposit $deposit): RedirectResponse
    {
        if (! $deposit->tx_hash) {
            return back()->with('error', __('controllers.admin.payments.tx_hash_required_first'));
        }

        try {
            $result = $this->paymentService->verifyDeposit($deposit, $request->user());

            if ($result->isVerified) {
                return back()->with('success', __('controllers.admin.payments.verify_success'));
            }

            if ($result->status === 'Rejected') {
                return back()->with('error', __('controllers.admin.payments.verify_rejected', ['reason' => $result->failureReason ?? __('controllers.admin.payments.reason_invalid')]));
            }

            return back()->with('info', __('controllers.admin.payments.verify_pending', ['reason' => $result->failureReason ?? __('controllers.admin.payments.reason_awaiting_block')]));
        } catch (\Throwable $e) {
            return back()->with('error', __('controllers.admin.payments.verify_error', ['message' => $e->getMessage()]));
        }
    }

    /**
     * Manually approve and complete a deposit request with mandatory reason and Audit Log.
     */
    public function completeManually(Request $request, CryptoDeposit $deposit): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'tx_hash' => ['nullable', 'string', 'max:128'],
        ], [
            'reason.required' => __('controllers.admin.payments.val_reason_required_manual'),
        ]);

        if ($deposit->isCompleted()) {
            return back()->with('error', __('controllers.admin.payments.already_completed'));
        }

        $admin = $request->user();
        $txHash = $validated['tx_hash'] ?: ($deposit->tx_hash ?: 'MANUAL-CONFIRM-' . strtoupper(\Illuminate\Support\Str::random(12)));

        if (! $deposit->tx_hash) {
            $deposit->update(['tx_hash' => $txHash]);
        }

        try {
            $syntheticVerification = PaymentVerificationResult::verified(
                txHash: $txHash,
                amount: (string) $deposit->requested_amount,
                currency: $deposit->currency,
                destinationAddress: $deposit->deposit_address,
                network: $deposit->network,
                confirmations: 99,
                raw: ['manual_approval_by' => $admin->id, 'reason' => $validated['reason']]
            );

            $this->paymentService->processVerifiedDeposit($deposit, $syntheticVerification, $admin);

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'admin.payment.manual_completed',
                'entity_type' => CryptoDeposit::class,
                'entity_id' => $deposit->id,
                'payload' => [
                    'tracking_code' => $deposit->tracking_code,
                    'tx_hash' => $txHash,
                    'reason' => $validated['reason'],
                ],
                'ip_address' => $request->ip() ?? '127.0.0.1',
            ]);

            return back()->with('success', __('controllers.admin.payments.manual_approved_success'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: __('controllers.admin.payments.manual_approve_error'));
        }
    }

    /**
     * Manually reject a deposit request with mandatory reason and Audit Log.
     */
    public function rejectManually(Request $request, CryptoDeposit $deposit): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => __('controllers.admin.payments.val_reason_required_reject'),
        ]);

        if ($deposit->isCompleted()) {
            return back()->with('error', __('controllers.admin.payments.cannot_reject_completed'));
        }

        $admin = $request->user();

        try {
            $deposit->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => $validated['reason'],
                'metadata' => array_merge($deposit->metadata ?? [], [
                    'manual_rejection' => [
                        'rejected_by_user_id' => $admin->id,
                        'rejected_by_name' => $admin->name,
                        'rejected_at' => now()->toIso8601String(),
                        'reason' => $validated['reason'],
                    ],
                ]),
            ]);

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'admin.payment.manual_rejected',
                'entity_type' => CryptoDeposit::class,
                'entity_id' => $deposit->id,
                'payload' => [
                    'tracking_code' => $deposit->tracking_code,
                    'tx_hash' => $deposit->tx_hash,
                    'reason' => $validated['reason'],
                ],
                'ip_address' => $request->ip() ?? '127.0.0.1',
            ]);

            return back()->with('success', __('controllers.admin.payments.manual_rejected_success'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: __('controllers.admin.payments.manual_reject_error'));
        }
    }

    /**
     * Gateway management screen.
     */
    public function gateways(): Response
    {
        $gateways = PaymentGateway::all();

        return Inertia::render('Admin/Payments/Gateways', [
            'gateways' => $gateways,
        ]);
    }

    /**
     * Update gateway configuration.
     */
    public function updateGateway(
        Request $request,
        PaymentGateway $gateway,
        \App\Services\Gateways\PaymentGatewayManager $gatewayManager
    ): RedirectResponse {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'config' => ['nullable', 'array'],
        ]);

        $admin = $request->user();
        $mergedConfig = array_merge($gateway->config ?? [], $validated['config'] ?? []);

        // Default min_deposit to 1.0000 if not specified
        if (! isset($mergedConfig['min_deposit']) || (float) $mergedConfig['min_deposit'] <= 0) {
            $mergedConfig['min_deposit'] = '1.0000';
        }

        // Auto-fix Nobitex base_url if empty or old api.nobitex.ir
        if ($gateway->driver === 'nobitex') {
            $url = trim((string) ($mergedConfig['base_url'] ?? ''));
            if (empty($url) || str_contains($url, 'api.nobitex.ir')) {
                $mergedConfig['base_url'] = 'https://apiv2.nobitex.ir';
            } else {
                $mergedConfig['base_url'] = rtrim($url, '/');
            }
        }

        if ($validated['is_default']) {
            PaymentGateway::where('id', '!=', $gateway->id)->update(['is_default' => false]);
        }

        // Validate API credentials & sync wallet if token provided or gateway activated
        $apiKey = trim((string) ($mergedConfig['api_key'] ?? ''));
        $validationError = null;

        if (! empty($apiKey) && in_array($gateway->driver, ['nobitex', 'wallex'])) {
            try {
                $adapter = $gatewayManager->forModel($gateway);
                $valResult = $adapter->validateCredentials($mergedConfig);

                if (! ($valResult['valid'] ?? false)) {
                    $validationError = $valResult['message'] ?? __('controllers.admin.payments.token_invalid');
                    if (empty($mergedConfig['deposit_address_override'])) {
                        $validated['is_active'] = false;
                    }
                } else {
                    // If valid, sync TRX wallet address into config
                    $syncResult = $adapter->syncWallets($mergedConfig);
                    if (! empty($syncResult['address'])) {
                        $mergedConfig['deposit_address'] = $syncResult['address'];
                    }
                }
            } catch (\Throwable $e) {
                $validationError = __('controllers.admin.payments.exchange_connection_error', ['message' => $e->getMessage()]);
                if (empty($mergedConfig['deposit_address_override'])) {
                    $validated['is_active'] = false;
                }
            }
        }

        // ALWAYS update gateway in DB so user configuration & base_url are saved
        $gateway->update([
            'is_active' => $validated['is_active'],
            'is_default' => $validated['is_default'],
            'config' => $mergedConfig,
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'admin.gateway.updated',
            'entity_type' => PaymentGateway::class,
            'entity_id' => $gateway->id,
            'payload' => [
                'driver' => $gateway->driver,
                'is_active' => $gateway->is_active,
                'is_default' => $gateway->is_default,
            ],
            'ip_address' => $request->ip() ?? '127.0.0.1',
        ]);

        if ($validationError) {
            return back()->with('error', __('controllers.admin.payments.gateway_saved_validation_failed', ['error' => $validationError]));
        }

        return back()->with('success', __('controllers.admin.payments.gateway_saved_success', ['name' => $gateway->name]));
    }

    /**
     * Manually trigger wallet sync for a payment gateway.
     */
    public function syncGateway(
        Request $request,
        PaymentGateway $gateway,
        \App\Services\Gateways\PaymentGatewayManager $gatewayManager
    ): RedirectResponse {
        try {
            $adapter = $gatewayManager->forModel($gateway);
            $config = $gateway->config ?? [];

            $valResult = $adapter->validateCredentials($config);
            if (! ($valResult['valid'] ?? false)) {
                $errMsg = $valResult['message'] ?? __('controllers.admin.payments.gateway_token_invalid');
                return back()->with('error', __('controllers.admin.payments.gateway_validation_failed', ['error' => $errMsg]));
            }

            $syncResult = $adapter->syncWallets($config);
            $gateway->update(['config' => $config]);

            if ($syncResult['success'] ?? false) {
                return back()->with('success', $syncResult['message'] ?? __('controllers.admin.payments.wallet_synced_success'));
            }

            return back()->with('error', $syncResult['message'] ?? __('controllers.admin.payments.wallet_sync_failed'));
        } catch (\Throwable $e) {
            return back()->with('error', __('controllers.admin.payments.gateway_sync_error', ['message' => $e->getMessage()]));
        }
    }
}