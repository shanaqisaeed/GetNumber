<?php

namespace App\Http\Controllers\User;

use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\VerifyCryptoDepositJob;
use App\Models\CryptoDeposit;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentDepositController extends Controller
{
    public function __construct(
        protected PaymentServiceInterface $paymentService
    ) {}

    /**
     * Display the crypto deposit creation screen (redirect to Profile recharge section).
     */
    public function create(Request $request): RedirectResponse
    {
        return redirect()->route('profile.edit');
    }

    /**
     * Create a new deposit request with auto-selected gateway and 1:1 integer units.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gte:1'],
            'network' => ['nullable', 'string', 'in:TRON,TRC20'],
        ], [
            'amount.required' => __('controllers.user.payments.val_amount_required'),
            'amount.numeric' => __('controllers.user.payments.val_amount_numeric'),
            'amount.gte' => __('controllers.user.payments.val_amount_gte'),
        ]);

        try {
            $deposit = $this->paymentService->createDepositRequest(
                user: $request->user(),
                trxAmount: (string) ((int) $validated['amount']),
                network: $validated['network'] ?? 'TRON',
                gatewayDriver: null // intelligent load balance across active gateways
            );

            return redirect()->route('wallet.deposit.show', $deposit->tracking_code)
                ->with('success', __('controllers.user.payments.invoice_created'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: __('controllers.user.payments.create_invoice_failed'));
        }
    }

    /**
     * Show deposit invoice with offline QR code, address, amount and TX Hash submission form.
     */
    public function show(Request $request, string $trackingCode): Response
    {
        $deposit = CryptoDeposit::where('tracking_code', $trackingCode)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return Inertia::render('User/Wallet/DepositShow', [
            'deposit' => [
                'id' => $deposit->id,
                'tracking_code' => $deposit->tracking_code,
                'currency' => $deposit->currency,
                'network' => $deposit->network,
                'gateway_driver' => $deposit->gateway_driver,
                'requested_amount' => (int) round((float) $deposit->requested_amount),
                'coin_amount' => (int) round((float) $deposit->coin_amount),
                'deposit_address' => $deposit->deposit_address,
                'deposit_tag' => $deposit->deposit_tag,
                'tx_hash' => $deposit->tx_hash,
                'actual_amount' => (int) round((float) ($deposit->actual_amount ?? $deposit->requested_amount)),
                'status' => $deposit->status->value,
                'status_label' => $deposit->status->label(),
                'status_badge' => $deposit->status->badgeClasses(),
                'failure_reason' => $deposit->failure_reason,
                'attempts' => $deposit->attempts,
                'can_submit_tx' => $deposit->canSubmitTx(),
                'is_completed' => $deposit->isCompleted(),
                'is_expired' => $deposit->isExpired(),
                'expires_at' => $deposit->expires_at?->toIso8601String(),
                'created_at' => $deposit->created_at->format('Y/m/d H:i'),
            ],
            'rates' => [
                'trx_to_coin_rate' => 1,
            ],
        ]);
    }

    /**
     * Submit TX Hash for a deposit and trigger 3-stage verification job.
     */
    public function submitTx(Request $request, string $trackingCode): RedirectResponse
    {
        $deposit = CryptoDeposit::where('tracking_code', $trackingCode)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'tx_hash' => ['required', 'string', 'max:128'],
        ], [
            'tx_hash.required' => __('controllers.user.payments.val_tx_hash_required'),
            'tx_hash.max' => __('controllers.user.payments.val_tx_hash_max'),
        ]);

        try {
            $updated = $this->paymentService->submitTxHash(
                deposit: $deposit,
                txHash: $validated['tx_hash'],
                actor: $request->user()
            );

            // If not immediately completed, queue the 30-min interval check job
            if (! $updated->isCompleted() && $updated->status !== PaymentStatus::Failed) {
                VerifyCryptoDepositJob::dispatch($updated->id)->delay(now()->addMinutes(30));
            }

            if ($updated->isCompleted()) {
                $coinInt = (int) round((float) $updated->coin_amount);
                return redirect()->route('wallet.deposit.show', $deposit->tracking_code)
                    ->with('success', __('controllers.user.payments.tx_verified', ['amount' => $coinInt]));
            }

            return redirect()->route('wallet.deposit.show', $deposit->tracking_code)
                ->with('success', __('controllers.user.payments.tx_registered'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: __('controllers.user.payments.tx_submit_failed'));
        }
    }

    /**
     * Lightweight status check endpoint for live UI updates.
     */
    public function status(Request $request, string $trackingCode): JsonResponse
    {
        $deposit = CryptoDeposit::where('tracking_code', $trackingCode)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'status' => $deposit->status->value,
            'status_label' => $deposit->status->label(),
            'is_completed' => $deposit->isCompleted(),
            'is_expired' => $deposit->isExpired(),
            'coin_amount' => (int) round((float) $deposit->coin_amount),
        ]);
    }
}