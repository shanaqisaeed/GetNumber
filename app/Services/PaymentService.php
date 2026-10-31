<?php

namespace App\Services;

use App\Contracts\PaymentServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\DTOs\PaymentVerificationResult;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidWalletTransactionException;
use App\Models\AuditLog;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Gateways\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentService implements PaymentServiceInterface
{
    public const DECIMAL_SCALE = 4;

    public function __construct(
        protected WalletServiceInterface $walletService,
        protected PaymentGatewayManager $gatewayManager
    ) {}

    public function createDepositRequest(
        User $user,
        string $trxAmount,
        string $network = 'TRON',
        ?string $gatewayDriver = null
    ): CryptoDeposit {
        $cleanAmount = $this->sanitizeAmount($trxAmount);

        if (bccomp($cleanAmount, '0.0000', self::DECIMAL_SCALE) <= 0) {
            throw new InvalidArgumentException('مبلغ درخواستی باید بزرگتر از صفر باشد.');
        }

        $trxToCoinRate = SystemSetting::getTrxToCoinRate();
        $coinAmount = bcmul($cleanAmount, $trxToCoinRate, self::DECIMAL_SCALE);

        // Determine gateway
        $driver = $gatewayDriver ?: $this->gatewayManager->getDefaultDriver();
        $gatewayModel = PaymentGateway::where('driver', $driver)->first();
        $gateway = $this->gatewayManager->driver($driver);

        $trackingCode = 'DEP-' . date('Ymd') . '-' . strtoupper(Str::random(8));

        // Create initial deposit record
        $deposit = CryptoDeposit::create([
            'user_id' => $user->id,
            'payment_gateway_id' => $gatewayModel?->id,
            'gateway_driver' => $driver,
            'tracking_code' => $trackingCode,
            'currency' => 'TRX',
            'network' => strtoupper($network),
            'requested_amount' => $cleanAmount,
            'coin_amount' => $coinAmount,
            'trx_to_coin_rate' => $trxToCoinRate,
            'deposit_address' => 'GENERATING...',
            'status' => PaymentStatus::PendingTransfer,
            'expires_at' => now()->addHours(2),
            'metadata' => [
                'created_by_ip' => request()->ip() ?? '127.0.0.1',
            ],
        ]);

        // Request address from gateway
        try {
            $addressResult = $gateway->generateDepositAddress($deposit);

            $deposit->update([
                'deposit_address' => $addressResult->address,
                'deposit_tag' => $addressResult->tag,
                'metadata' => array_merge($deposit->metadata ?? [], [
                    'qr_code_url' => $addressResult->qrCodeUrl,
                    'gateway_extra' => $addressResult->extra,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to generate deposit address for #{$deposit->id}: " . $e->getMessage());
            // Keep fallback address generated in deposit record
            $deposit->update([
                'deposit_address' => 'TFallbackAddress' . substr(md5((string) $deposit->id), 0, 10),
            ]);
        }

        return $deposit->fresh();
    }

    public function submitTxHash(
        CryptoDeposit $deposit,
        string $txHash,
        ?User $actor = null
    ): CryptoDeposit {
        $cleanTxHash = trim($txHash);

        if ($cleanTxHash === '') {
            throw new InvalidArgumentException('کد رهگیری تراکنش (TX Hash) نمی‌تواند خالی باشد.');
        }

        if (! $deposit->canSubmitTx()) {
            throw new InvalidWalletTransactionException("امکان ثبت کد رهگیری برای این درخواست با وضعیت «{$deposit->status->label()}» وجود ندارد.");
        }

        // Prevent duplicate TX Hash usage across the system
        $duplicate = CryptoDeposit::where('tx_hash', $cleanTxHash)
            ->where('id', '!=', $deposit->id)
            ->where('status', '!=', PaymentStatus::Failed->value)
            ->exists();

        if ($duplicate) {
            throw new InvalidWalletTransactionException('این شناسه تراکنش (TX Hash) قبلاً در سامانه ثبت شده است و امکان استفاده مجدد ندارد.');
        }

        $deposit->update([
            'tx_hash' => $cleanTxHash,
            'status' => PaymentStatus::TxSubmitted,
            'attempts' => 0,
            'next_check_at' => now(),
            'failure_reason' => null,
        ]);

        // Trigger immediate verification attempt
        $this->verifyDeposit($deposit->fresh(), $actor);

        return $deposit->fresh();
    }

    public function verifyDeposit(
        CryptoDeposit $deposit,
        ?User $actor = null
    ): PaymentVerificationResult {
        if (! $deposit->tx_hash) {
            return PaymentVerificationResult::pending('NoTxHash', 'هنوز کد رهگیری برای این درخواست ثبت نشده است.');
        }

        if ($deposit->isCompleted()) {
            return PaymentVerificationResult::verified(
                txHash: $deposit->tx_hash,
                amount: (string) ($deposit->actual_amount ?? $deposit->requested_amount),
                currency: $deposit->currency,
                destinationAddress: $deposit->deposit_address,
                network: $deposit->network
            );
        }

        $gateway = $this->gatewayManager->driver($deposit->gateway_driver);

        $deposit->increment('attempts');
        $deposit->update([
            'last_checked_at' => now(),
            'status' => PaymentStatus::Verifying,
        ]);

        $verification = $gateway->verifyTransaction($deposit, $deposit->tx_hash);

        if ($verification->isVerified) {
            $this->processVerifiedDeposit($deposit, $verification, $actor);
        } elseif ($verification->status === 'Rejected') {
            $deposit->update([
                'status' => PaymentStatus::Failed,
                'failure_reason' => $verification->failureReason ?? 'تراکنش توسط شبکه یا درگاه صرافی رد شد.',
            ]);

            AuditLog::create([
                'user_id' => $actor?->id ?? $deposit->user_id,
                'action' => 'payment.deposit.failed',
                'entity_type' => CryptoDeposit::class,
                'entity_id' => $deposit->id,
                'payload' => [
                    'tx_hash' => $deposit->tx_hash,
                    'reason' => $verification->failureReason,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
            ]);
        } else {
            // Still pending / confirming: exponential backoff
            $backoffMinutes = min((int) pow(2, min($deposit->attempts, 5)), 30);

            $deposit->update([
                'next_check_at' => now()->addMinutes($backoffMinutes),
                'failure_reason' => $verification->failureReason,
            ]);
        }

        return $verification;
    }

    public function processVerifiedDeposit(
        CryptoDeposit $deposit,
        PaymentVerificationResult $verification,
        ?User $actor = null
    ): void {
        DB::transaction(function () use ($deposit, $verification, $actor) {
            /** @var CryptoDeposit $lockedDeposit */
            $lockedDeposit = CryptoDeposit::where('id', $deposit->id)->lockForUpdate()->firstOrFail();

            if ($lockedDeposit->isCompleted()) {
                return;
            }

            $actualTrxAmount = $verification->amount ?? (string) $lockedDeposit->requested_amount;
            $coinAmount = bcmul($actualTrxAmount, (string) $lockedDeposit->trx_to_coin_rate, self::DECIMAL_SCALE);

            $referenceId = "DEP-{$lockedDeposit->id}-{$lockedDeposit->tx_hash}";

            // 1. Credit wallet atomically via WalletService
            $this->walletService->deposit(
                user: $lockedDeposit->user,
                amount: $coinAmount,
                referenceId: $referenceId,
                description: "شارژ آنلاین کیف پول از طریق شبکه ترون ({$actualTrxAmount} TRX با نرخ {$lockedDeposit->trx_to_coin_rate})",
                metadata: [
                    'crypto_deposit_id' => $lockedDeposit->id,
                    'tracking_code' => $lockedDeposit->tracking_code,
                    'tx_hash' => $lockedDeposit->tx_hash,
                    'currency' => 'TRX',
                    'actual_trx' => $actualTrxAmount,
                    'network' => $lockedDeposit->network,
                    'gateway' => $lockedDeposit->gateway_driver,
                ]
            );

            // 2. Mark deposit completed
            $lockedDeposit->update([
                'status' => PaymentStatus::Completed,
                'actual_amount' => $actualTrxAmount,
                'coin_amount' => $coinAmount,
                'completed_at' => now(),
                'failure_reason' => null,
            ]);

            // 3. Security Audit Log
            AuditLog::create([
                'user_id' => $actor?->id ?? $lockedDeposit->user_id,
                'action' => 'payment.deposit.completed',
                'entity_type' => CryptoDeposit::class,
                'entity_id' => $lockedDeposit->id,
                'payload' => [
                    'tracking_code' => $lockedDeposit->tracking_code,
                    'tx_hash' => $lockedDeposit->tx_hash,
                    'trx_amount' => $actualTrxAmount,
                    'coin_amount' => $coinAmount,
                    'gateway' => $lockedDeposit->gateway_driver,
                    'actor_id' => $actor?->id,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
            ]);
        });
    }

    public function processPendingDeposits(int $limit = 50): array
    {
        $dueDeposits = CryptoDeposit::dueForCheck()->limit($limit)->get();

        $stats = [
            'checked' => 0,
            'completed' => 0,
            'failed' => 0,
            'still_pending' => 0,
        ];

        foreach ($dueDeposits as $deposit) {
            $stats['checked']++;

            try {
                $result = $this->verifyDeposit($deposit);

                if ($result->isVerified) {
                    $stats['completed']++;
                } elseif ($result->status === 'Rejected') {
                    $stats['failed']++;
                } else {
                    $stats['still_pending']++;
                }
            } catch (\Throwable $e) {
                Log::error("Error processing pending deposit #{$deposit->id}: " . $e->getMessage());
                $stats['still_pending']++;
            }
        }

        return $stats;
    }

    public function expireStaleDeposits(): int
    {
        return CryptoDeposit::whereIn('status', [
            PaymentStatus::PendingTransfer->value,
            PaymentStatus::TxSubmitted->value,
        ])
        ->where('expires_at', '<=', now())
        ->update([
            'status' => PaymentStatus::Expired->value,
            'failure_reason' => 'مهلت انجام واریز به پایان رسید.',
        ]);
    }

    protected function sanitizeAmount(string $amount): string
    {
        $clean = trim($amount);

        if (! is_numeric($clean)) {
            throw new InvalidArgumentException('مبلغ تراکنش باید یک مقدار عددی معتبر باشد.');
        }

        return number_format((float) $clean, self::DECIMAL_SCALE, '.', '');
    }
}

