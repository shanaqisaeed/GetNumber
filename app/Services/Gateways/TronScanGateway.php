<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TronScanGateway implements PaymentGatewayInterface
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $depositAddress;

    public function __construct(?PaymentGateway $model = null)
    {
        $config = $model?->config ?? [];

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? env('TRONSCAN_BASE_URL', 'https://apilist.tronscanapi.com')), '/');
        $this->apiKey = $config['api_key'] ?? env('TRONSCAN_API_KEY');
        $this->depositAddress = $config['deposit_address'] ?? env('TRON_DEPOSIT_ADDRESS');
    }

    public function getIdentifier(): string
    {
        return 'tron_scan';
    }

    public function getName(): string
    {
        return 'کاوشگر ترون اسکن و ترون ویو (TronScan & TronView Direct)';
    }

    public function validateCredentials(array $config): array
    {
        $address = $config['deposit_address'] ?? null;
        if (empty($address)) {
            return [
                'valid' => false,
                'message' => 'آدرس والت دریافت ترون (TRX) وارد نشده است.',
            ];
        }

        if (! str_starts_with($address, 'T') || strlen($address) !== 34) {
            return [
                'valid' => false,
                'message' => 'فرمت آدرس والت ترون معتبر نیست (باید با حرف T آغاز شده و ۳۴ کاراکتر باشد).',
            ];
        }

        return [
            'valid' => true,
            'message' => 'آدرس والت دریافت در بلاکچین ترون معتبر است.',
            'details' => ['address' => $address],
        ];
    }

    public function syncWallets(array &$config): array
    {
        $config['min_deposit'] = $config['min_deposit'] ?? '1.0000';

        return [
            'success' => ! empty($config['deposit_address']),
            'wallet_id' => null,
            'address' => $config['deposit_address'] ?? null,
            'currency' => 'TRX',
            'changed' => false,
            'message' => 'آدرس والت بلاکچین ترون بررسی شد.',
        ];
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        $address = ! empty($this->depositAddress)
            ? $this->depositAddress
            : ('T' . substr(hash('sha256', "tronview-{$deposit->user_id}-{$deposit->id}"), 0, 33));

        return new PaymentAddressResult(
            address: $address,
            currency: 'TRX',
            network: 'TRON',
            tag: null,
            qrCodeUrl: null,
            expiresAt: now()->addHours(2),
            extra: ['source' => 'tron_scan']
        );
    }

    public function verifyTransaction(CryptoDeposit $deposit, string $txHash): PaymentVerificationResult
    {
        $cleanTxHash = trim($txHash);

        try {
            $headers = [];
            if (! empty($this->apiKey)) {
                $headers['TRON-PRO-API-KEY'] = $this->apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get("{$this->baseUrl}/api/transaction-info", [
                    'hash' => $cleanTxHash,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check contractRet
                $contractRet = $data['contractRet'] ?? '';
                $confirmed = (bool) ($data['confirmed'] ?? false);

                if (strtoupper($contractRet) === 'SUCCESS' && $confirmed) {
                    $contractData = $data['contractData'] ?? [];
                    $amountSun = $contractData['amount'] ?? 0;
                    $destAddress = $contractData['to_address'] ?? '';

                    $amountTrx = bcdiv((string) $amountSun, '1000000', 4);

                    if (bccomp($amountTrx, (string) $deposit->requested_amount, 4) < 0) {
                        return PaymentVerificationResult::rejected(
                            "مبلغ واریزشده ({$amountTrx} TRX) کمتر از مبلغ درخواست ({$deposit->requested_amount} TRX) است.",
                            $data
                        );
                    }

                    if ($deposit->deposit_address && ! empty($destAddress) && strcasecmp($destAddress, $deposit->deposit_address) !== 0) {
                        return PaymentVerificationResult::rejected(
                            "آدرس مقصد تراکنش ({$destAddress}) با آدرس تعیین‌شده مطابقت ندارد.",
                            $data
                        );
                    }

                    return PaymentVerificationResult::verified(
                        txHash: $cleanTxHash,
                        amount: $amountTrx,
                        currency: 'TRX',
                        destinationAddress: $destAddress,
                        network: 'TRON',
                        confirmations: (int) ($data['confirmations'] ?? 1),
                        raw: $data
                    );
                }

                if (strtoupper($contractRet) === 'FAILED' || strtoupper($contractRet) === 'REVERT') {
                    return PaymentVerificationResult::rejected('تراکنش در بلاکچین ترون با شکست مواجه شده است.', $data);
                }

                return PaymentVerificationResult::pending('Confirming', 'تراکنش در حال تأیید روی نودهای شبکه ترون است.', $data);
            }

            return PaymentVerificationResult::pending('NotFound', 'تراکنش هنوز در کاوشگر بلاکچین ترون قابل مشاهده نیست.');
        } catch (\Throwable $e) {
            Log::error('TronScan verifyTransaction exception: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', 'خطا در استعلام از کاوشگر ترون: ' . $e->getMessage());
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}

