<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WallexGateway implements PaymentGatewayInterface
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $depositAddress;

    public function __construct(?PaymentGateway $model = null)
    {
        $config = $model?->config ?? [];

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? env('WALLEX_BASE_URL', 'https://api.wallex.ir')), '/');
        $this->apiKey = $config['api_key'] ?? env('WALLEX_API_KEY');
        $this->depositAddress = $config['deposit_address'] ?? env('WALLEX_DEPOSIT_ADDRESS');
    }

    public function getIdentifier(): string
    {
        return 'wallex';
    }

    public function getName(): string
    {
        return 'صرافی والکس (Wallex Exchange)';
    }

    /**
     * Validate the Wallex API Key via authenticated profile probe.
     */
    public function validateCredentials(array $config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? $this->apiKey ?? ''));
        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl), '/');

        if (empty($apiKey)) {
            return [
                'valid' => false,
                'message' => 'کلید API صرافی والکس وارد نشده است.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
            ])->timeout(8)->get("{$baseUrl}/v1/profile");

            if ($response->successful()) {
                return [
                    'valid' => true,
                    'message' => 'ارتباط با والکس برقرار و کلید API معتبر است.',
                    'details' => $response->json('result') ?? [],
                ];
            }

            return [
                'valid' => false,
                'message' => 'کلید API صرافی والکس نامعتبر است (کد وضعیت ' . $response->status() . ').',
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => 'خطا در ارتباط با وب‌سرویس والکس: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Query Wallex for user wallets and resolve TRX deposit address.
     */
    public function syncWallets(array &$config): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? $this->apiKey ?? ''));
        $baseUrl = rtrim((string) ($config['base_url'] ?? $this->baseUrl), '/');

        if (empty($apiKey)) {
            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => 'کلید API والکس جهت همگام‌سازی کیف پول تنظیم نشده است.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
            ])->timeout(10)->get("{$baseUrl}/v1/account/wallets");

            if ($response->successful()) {
                $wallets = (array) ($response->json('result.wallets') ?? []);
                $trxWallet = null;

                foreach ($wallets as $w) {
                    if (strtoupper((string) ($w['currency'] ?? '')) === 'TRX') {
                        $trxWallet = $w;
                        break;
                    }
                }

                if ($trxWallet) {
                    $address = $trxWallet['deposit_address'] ?? $trxWallet['address'] ?? null;
                    $old = $config['deposit_address'] ?? null;
                    $changed = ! empty($old) && ! empty($address) && strcasecmp($old, $address) !== 0;

                    if ($address) {
                        $config['deposit_address'] = $address;
                    }
                    $config['min_deposit'] = '1.0000';

                    return [
                        'success' => ! empty($address),
                        'wallet_id' => $trxWallet['id'] ?? null,
                        'address' => $address,
                        'currency' => 'TRX',
                        'changed' => $changed,
                        'message' => 'کیف پول TRX والکس با موفقیت همگام‌سازی شد.',
                    ];
                }
            }

            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => 'کیف پول ترون در پاسخ وب‌سرویس والکس یافت نشد.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => 'خطا در ارتباط با والکس: ' . $e->getMessage(),
            ];
        }
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        $address = ! empty($this->depositAddress)
            ? $this->depositAddress
            : ('TWallexTrxDepositAddress' . substr(md5((string) $deposit->id), 0, 10));

        return new PaymentAddressResult(
            address: $address,
            currency: 'TRX',
            network: 'TRON',
            tag: null,
            qrCodeUrl: null,
            expiresAt: now()->addHours(2),
            extra: ['source' => 'wallex']
        );
    }

    public function verifyTransaction(CryptoDeposit $deposit, string $txHash): PaymentVerificationResult
    {
        $cleanTxHash = trim($txHash);

        if (empty($this->apiKey)) {
            return PaymentVerificationResult::pending('NoApiKey', 'کلید API والکس پیکربندی نشده است.');
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->timeout(10)->get("{$this->baseUrl}/v1/account/deposits", [
                'currency' => 'TRX',
            ]);

            if ($response->successful()) {
                $deposits = (array) ($response->json('result.deposits') ?? []);

                foreach ($deposits as $item) {
                    $itemTxHash = trim((string) ($item['txHash'] ?? $item['transaction_hash'] ?? ''));
                    if (strcasecmp($itemTxHash, $cleanTxHash) === 0) {
                        $status = (string) ($item['status'] ?? 'pending');
                        $amount = (string) ($item['amount'] ?? '0');
                        $address = (string) ($item['address'] ?? $deposit->deposit_address);

                        if (in_array(strtolower($status), ['done', 'completed', 'success'])) {
                            if (bccomp($amount, (string) $deposit->requested_amount, 4) < 0) {
                                return PaymentVerificationResult::rejected(
                                    "مبلغ واریز ({$amount} TRX) کمتر از مبلغ درخواست ({$deposit->requested_amount} TRX) است.",
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
                        }

                        return PaymentVerificationResult::pending($status, "تراکنش در والکس در وضعیت {$status} است.", $item);
                    }
                }
            }

            return PaymentVerificationResult::pending('NotFound', 'تراکنش هنوز در والکس ثبت نهایی نشده است.');
        } catch (\Throwable $e) {
            Log::error('Wallex verifyTransaction exception: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', 'خطا در استعلام والکس: ' . $e->getMessage());
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}

