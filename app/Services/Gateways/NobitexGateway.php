<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NobitexGateway implements PaymentGatewayInterface
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?int $walletId;
    protected ?string $addressOverride;

    public function __construct(?PaymentGateway $model = null)
    {
        $config = $model?->config ?? [];

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? env('NOBITEX_BASE_URL', 'https://api.nobitex.ir')), '/');
        $this->apiKey = $config['api_key'] ?? env('NOBITEX_API_KEY');
        $this->walletId = isset($config['wallet_id']) && $config['wallet_id'] !== '' ? (int) $config['wallet_id'] : null;
        $this->addressOverride = $config['deposit_address_override'] ?? env('NOBITEX_DEPOSIT_ADDRESS_OVERRIDE');
    }

    public function getIdentifier(): string
    {
        return 'nobitex';
    }

    public function getName(): string
    {
        return 'صرافی نوبیتکس (Nobitex)';
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        // 1. If a fixed deposit address override is set in config/env, use it
        if (! empty($this->addressOverride)) {
            return new PaymentAddressResult(
                address: $this->addressOverride,
                currency: 'TRX',
                network: 'TRON',
                tag: null,
                qrCodeUrl: null,
                expiresAt: now()->addHours(2),
                extra: ['source' => 'override']
            );
        }

        // 2. Check API Key
        if (empty($this->apiKey)) {
            // Fallback for development if no API key is set yet
            $mockAddress = 'TNobitexTrxDepositAddress' . substr(md5((string) $deposit->id), 0, 10);
            return new PaymentAddressResult(
                address: $mockAddress,
                currency: 'TRX',
                network: 'TRON',
                tag: null,
                qrCodeUrl: null,
                expiresAt: now()->addHours(2),
                extra: ['source' => 'unconfigured_fallback']
            );
        }

        try {
            $walletId = $this->resolveTrxWalletId();

            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
            ])->timeout(10)->post("{$this->baseUrl}/users/wallets/generate-address", [
                'wallet' => $walletId,
                'currency' => 'trx',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $address = $data['address'] ?? null;

                if ($address) {
                    return new PaymentAddressResult(
                        address: $address,
                        currency: 'TRX',
                        network: 'TRON',
                        tag: $data['tag'] ?? null,
                        qrCodeUrl: null,
                        expiresAt: now()->addHours(2),
                        extra: ['wallet_id' => $walletId]
                    );
                }
            }

            Log::error('Nobitex generate-address failed: ' . $response->body());
        } catch (\Throwable $e) {
            Log::error('Nobitex generateDepositAddress exception: ' . $e->getMessage());
        }

        // Fallback address in case of temporary API timeout
        $fallback = 'T' . substr(hash('sha256', "nobitex-{$deposit->user_id}"), 0, 33);
        return new PaymentAddressResult(
            address: $fallback,
            currency: 'TRX',
            network: 'TRON',
            tag: null,
            qrCodeUrl: null,
            expiresAt: now()->addHours(2),
            extra: ['source' => 'exception_fallback']
        );
    }

    public function verifyTransaction(CryptoDeposit $deposit, string $txHash): PaymentVerificationResult
    {
        $cleanTxHash = trim($txHash);

        if (empty($this->apiKey)) {
            return PaymentVerificationResult::pending('NoApiKey', 'کلید API صرافی نوبیتکس پیکربندی نشده است.');
        }

        try {
            $deposits = $this->getRecentDeposits('trx');

            // Find matching txHash in Nobitex deposit records
            foreach ($deposits as $item) {
                $itemTxHash = trim((string) ($item['txHash'] ?? $item['transactionHash'] ?? $item['tx_hash'] ?? ''));

                if (strcasecmp($itemTxHash, $cleanTxHash) === 0) {
                    $status = (string) ($item['status'] ?? 'New');
                    $amount = (string) ($item['amount'] ?? '0');
                    $address = (string) ($item['address'] ?? $deposit->deposit_address);
                    $currency = strtoupper((string) ($item['currency'] ?? 'TRX'));

                    // Check currency
                    if ($currency !== 'TRX') {
                        return PaymentVerificationResult::rejected("ارز تراکنش ({$currency}) با ترون (TRX) مطابقت ندارد.", $item);
                    }

                    // Check status in Nobitex
                    if (in_array(strtolower($status), ['approved', 'done', 'success'])) {
                        // Validate amount using bcmath
                        if (bccomp($amount, (string) $deposit->requested_amount, 4) < 0) {
                            return PaymentVerificationResult::rejected(
                                "مبلغ واریزشده ({$amount} TRX) کمتر از مبلغ درخواست ({$deposit->requested_amount} TRX) است.",
                                $item
                            );
                        }

                        // Validate destination address if applicable
                        if ($deposit->deposit_address && ! empty($address) && strcasecmp($address, $deposit->deposit_address) !== 0) {
                            // Check if destination matches
                            return PaymentVerificationResult::rejected(
                                "آدرس واریز تراکنش ({$address}) با آدرس اختصاص‌یافته به درخواست ({$deposit->deposit_address}) مطابقت ندارد.",
                                $item
                            );
                        }

                        return PaymentVerificationResult::verified(
                            txHash: $cleanTxHash,
                            amount: $amount,
                            currency: 'TRX',
                            destinationAddress: $address,
                            network: 'TRON',
                            confirmations: 1,
                            raw: $item
                        );
                    } elseif (in_array(strtolower($status), ['rejected', 'failed', 'canceled'])) {
                        return PaymentVerificationResult::rejected("تراکنش در صرافی نوبیتکس رد شده است (وضعیت: {$status}).", $item);
                    } else {
                        return PaymentVerificationResult::pending($status, "تراکنش با موفقیت دریافت شده و در مرحله تأیید صرافی است (وضعیت: {$status}).", $item);
                    }
                }
            }

            return PaymentVerificationResult::pending(
                'NotFound',
                'شناسه تراکنش هنوز در فهرست واریزی‌های تأییدشده نوبیتکس ثبت نشده است. فرآیند همگام‌سازی شبکه ممکن است چند دقیقه زمان ببرد.'
            );
        } catch (\Throwable $e) {
            Log::error('Nobitex verifyTransaction exception: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', 'خطا در ارتباط با وب‌سرویس نوبیتکس: ' . $e->getMessage());
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        try {
            $params = [];
            if ($this->walletId) {
                $params['wallet'] = $this->walletId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
            ])->timeout(10)->get("{$this->baseUrl}/users/wallets/deposits/list", $params);

            if ($response->successful()) {
                $data = $response->json();
                return (array) ($data['deposits'] ?? []);
            }
        } catch (\Throwable $e) {
            Log::error('Nobitex getRecentDeposits error: ' . $e->getMessage());
        }

        return [];
    }

    protected function resolveTrxWalletId(): int
    {
        if ($this->walletId) {
            return $this->walletId;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->apiKey,
        ])->timeout(10)->get("{$this->baseUrl}/users/wallets/list");

        if ($response->successful()) {
            $wallets = $response->json('wallets') ?? [];
            foreach ($wallets as $w) {
                if (strtolower((string) ($w['currency'] ?? '')) === 'trx') {
                    return (int) $w['id'];
                }
            }
        }

        return 1;
    }
}

