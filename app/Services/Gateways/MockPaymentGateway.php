<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;

class MockPaymentGateway implements PaymentGatewayInterface
{
    protected array $config;

    public function __construct(?PaymentGateway $model = null)
    {
        $this->config = $model?->config ?? [];
    }

    public function getIdentifier(): string
    {
        return 'mock';
    }

    public function getName(): string
    {
        return 'درگاه شبیه‌ساز تست (Sandbox / Mock)';
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        $address = (string) ($this->config['deposit_address'] ?? 'TTestSandboxTronAddress9999988888XX');

        return new PaymentAddressResult(
            address: $address,
            currency: 'TRX',
            network: 'TRON',
            tag: null,
            qrCodeUrl: null,
            expiresAt: now()->addHours(2),
            extra: ['mock' => true]
        );
    }

    public function verifyTransaction(CryptoDeposit $deposit, string $txHash): PaymentVerificationResult
    {
        $cleanTxHash = trim($txHash);

        // Simulation scenarios based on hash patterns
        if (str_starts_with($cleanTxHash, 'fail_') || str_starts_with($cleanTxHash, 'reject_')) {
            return PaymentVerificationResult::rejected(
                'تراکنش توسط شبیه‌ساز شبکه به عنوان تراکنش نامعتبر رد شد.',
                ['simulated' => true]
            );
        }

        if (str_starts_with($cleanTxHash, 'pending_')) {
            return PaymentVerificationResult::pending(
                'Confirming',
                'تراکنش در صف انتظار تأیید بلاک‌های شبکه ترون قرار دارد.',
                ['simulated' => true]
            );
        }

        if (str_starts_with($cleanTxHash, 'underpaid_')) {
            return PaymentVerificationResult::rejected(
                'مبلغ واریزشده (0.1000 TRX) کمتر از مبلغ درخواست است.',
                ['simulated' => true, 'actual_amount' => '0.1000']
            );
        }

        if (str_starts_with($cleanTxHash, 'wrong_dest_')) {
            return PaymentVerificationResult::rejected(
                'آدرس واریز تراکنش با آدرس اختصاص‌یافته مطابقت ندارد.',
                ['simulated' => true]
            );
        }

        // Default successful verification
        return PaymentVerificationResult::verified(
            txHash: $cleanTxHash,
            amount: (string) $deposit->requested_amount,
            currency: 'TRX',
            destinationAddress: $deposit->deposit_address,
            network: 'TRON',
            confirmations: 19,
            raw: ['simulated' => true, 'timestamp' => now()->toIso8601String()]
        );
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}

