<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TronGridGateway implements PaymentGatewayInterface
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $depositAddress;

    public function __construct(?PaymentGateway $model = null)
    {
        $config = $model?->config ?? [];

        $this->baseUrl = rtrim((string) ($config['base_url'] ?? env('TRONGRID_BASE_URL', 'https://api.trongrid.io')), '/');
        $this->apiKey = $config['api_key'] ?? env('TRONGRID_API_KEY');
        $this->depositAddress = $config['deposit_address'] ?? env('TRON_DEPOSIT_ADDRESS');
    }

    public function getIdentifier(): string
    {
        return 'tron_grid';
    }

    public function getName(): string
    {
        return 'شبکه مستقیم ترون گرید (TronGrid Direct)';
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        $address = ! empty($this->depositAddress)
            ? $this->depositAddress
            : ('T' . substr(hash('sha256', "trongrid-{$deposit->user_id}-{$deposit->id}"), 0, 33));

        return new PaymentAddressResult(
            address: $address,
            currency: 'TRX',
            network: 'TRON',
            tag: null,
            qrCodeUrl: null,
            expiresAt: now()->addHours(2),
            extra: ['gateway' => 'tron_grid']
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

            // 1. Fetch transaction data
            $txResponse = Http::withHeaders($headers)
                ->timeout(10)
                ->post("{$this->baseUrl}/wallet/gettransactionbyid", [
                    'value' => $cleanTxHash,
                ]);

            if (! $txResponse->successful() || empty($txResponse->json('txID'))) {
                return PaymentVerificationResult::pending(
                    'NotFound',
                    'تراکنش هنوز در بلاکچین ترون ثبت یا همگام‌سازی نشده است.'
                );
            }

            $txData = $txResponse->json();

            // 2. Fetch transaction receipt info
            $infoResponse = Http::withHeaders($headers)
                ->timeout(10)
                ->post("{$this->baseUrl}/wallet/gettransactioninfobyid", [
                    'value' => $cleanTxHash,
                ]);

            $infoData = $infoResponse->successful() ? $infoResponse->json() : [];

            // Verify status: if receipt exists, check result
            $receiptResult = $infoData['receipt']['result'] ?? null;
            if ($receiptResult !== null && strtoupper((string) $receiptResult) !== 'SUCCESS') {
                return PaymentVerificationResult::rejected("تراکنش در شبکه ترون با خطا مواجه شد ({$receiptResult}).", $infoData);
            }

            // Extract transfer contract
            $contract = $txData['raw_data']['contract'][0] ?? null;
            $contractType = $contract['type'] ?? null;

            if ($contractType === 'TransferContract') {
                $parameter = $contract['parameter']['value'] ?? [];
                $sunAmount = (string) ($parameter['amount'] ?? '0');
                $trxAmount = bcdiv($sunAmount, '1000000', 4); // 1 TRX = 1,000,000 SUN

                // Validate amount
                if (bccomp($trxAmount, (string) $deposit->requested_amount, 4) < 0) {
                    return PaymentVerificationResult::rejected(
                        "مبلغ واریز شده ({$trxAmount} TRX) کمتر از مبلغ درخواست ({$deposit->requested_amount} TRX) است.",
                        $txData
                    );
                }

                return PaymentVerificationResult::verified(
                    txHash: $cleanTxHash,
                    amount: $trxAmount,
                    currency: 'TRX',
                    destinationAddress: $deposit->deposit_address,
                    network: 'TRON',
                    confirmations: 1,
                    raw: array_merge($txData, ['info' => $infoData])
                );
            }

            return PaymentVerificationResult::pending('CheckingContract', 'نوع قرارداد تراکنش ترون در حال ارزیابی است.', $txData);
        } catch (\Throwable $e) {
            Log::error('TronGrid verifyTransaction error: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', 'خطا در ارتباط با شبکه ترون: ' . $e->getMessage());
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}

