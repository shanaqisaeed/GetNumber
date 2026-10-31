<?php

namespace App\Http\Controllers\User;

use App\Contracts\PaymentServiceInterface;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
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
     * Display the crypto deposit creation screen.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        $activeGateways = PaymentGateway::active()
            ->get(['id', 'name', 'driver', 'is_default', 'supported_currencies', 'supported_networks']);

        $recentDeposits = CryptoDeposit::where('user_id', $user->id)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'tracking_code' => $d->tracking_code,
                'requested_amount' => (string) $d->requested_amount,
                'coin_amount' => (string) $d->coin_amount,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'status_badge' => $d->status->badgeClasses(),
                'created_at' => $d->created_at->format('Y/m/d H:i'),
            ]);

        return Inertia::render('User/Wallet/Deposit', [
            'rates' => [
                'trx_to_coin_rate' => SystemSetting::getTrxToCoinRate(),
                'number_price_coin' => SystemSetting::getNumberPriceCoin(),
            ],
            'wallet_balance' => (string) $user->getOrCreateWallet()->balance,
            'gateways' => $activeGateways,
            'recent_deposits' => $recentDeposits,
        ]);
    }

    /**
     * Create a new deposit request and generate deposit address.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'network' => ['nullable', 'string', 'in:TRON,TRC20'],
            'gateway' => ['nullable', 'string'],
        ], [
            'amount.required' => 'وارد کردن مبلغ ترون الزامی است.',
            'amount.gt' => 'مبلغ ترون باید بزرگتر از صفر باشد.',
        ]);

        try {
            $deposit = $this->paymentService->createDepositRequest(
                user: $request->user(),
                trxAmount: (string) $validated['amount'],
                network: $validated['network'] ?? 'TRON',
                gatewayDriver: $validated['gateway'] ?? null
            );

            return redirect()->route('wallet.deposit.show', $deposit->tracking_code)
                ->with('success', 'درخواست افزایش اعتبار با موفقیت ایجاد شد. لطفاً مبلغ ترون را به آدرس نمایش داده شده واریز نمایید.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'خطا در ایجاد درخواست پرداخت.');
        }
    }

    /**
     * Show deposit details, address, QR code, and TX Hash submission form.
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
                'requested_amount' => (string) $deposit->requested_amount,
                'coin_amount' => (string) $deposit->coin_amount,
                'trx_to_coin_rate' => (string) $deposit->trx_to_coin_rate,
                'deposit_address' => $deposit->deposit_address,
                'deposit_tag' => $deposit->deposit_tag,
                'tx_hash' => $deposit->tx_hash,
                'actual_amount' => (string) ($deposit->actual_amount ?? $deposit->requested_amount),
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
                'qr_code_url' => null,
            ],
            'rates' => [
                'trx_to_coin_rate' => SystemSetting::getTrxToCoinRate(),
            ],
        ]);
    }

    /**
     * Submit TX Hash for a deposit.
     */
    public function submitTx(Request $request, string $trackingCode): RedirectResponse
    {
        $deposit = CryptoDeposit::where('tracking_code', $trackingCode)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'tx_hash' => ['required', 'string', 'max:128'],
        ], [
            'tx_hash.required' => 'وارد کردن شناسه تراکنش (TX Hash) الزامی است.',
            'tx_hash.max' => 'شناسه تراکنش نامعتبر است.',
        ]);

        try {
            $updated = $this->paymentService->submitTxHash(
                deposit: $deposit,
                txHash: $validated['tx_hash'],
                actor: $request->user()
            );

            if ($updated->isCompleted()) {
                return back()->with('success', "تراکنش با موفقیت تأیید شد و حساب شما به میزان {$updated->coin_amount} COIN شارژ گردید!");
            }

            return back()->with('success', 'شناسه تراکنش ثبت گردید و در حال استعلام و اعتبارسنجی خودکار از شبکه است.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'خطا در ثبت شناسه تراکنش.');
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
            'coin_amount' => (string) $deposit->coin_amount,
        ]);
    }
}

