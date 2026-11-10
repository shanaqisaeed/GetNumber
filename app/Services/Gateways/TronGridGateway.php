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
        return __('services.gateways.trongrid.name');
    }

    public function validateCredentials(array $config): array
    {
        $address = $config['deposit_address'] ?? null;
        if (empty($address)) {
            return [
                'valid' => false,
                'message' => __('services.gateways.trongrid.no_rx_address'),
            ];
        }

        return [
            'valid' => true,
            'message' => __('services.gateways.trongrid.network_config_valid'),
            'details' => ['address' => $address],
        ];
    }

    public function syncWallets(array &$config): array
    {
        $config['min_deposit'] = $config['min_deposit'] ?? '1.0000';

        return [
            'success' => !empty($config['deposit_address']),
            'wallet_id' => null,
            'address' => $config['deposit_address'] ?? null,
            'currency' => 'TRX',
            'changed' => false,
            'message' => __('services.gateways.trongrid.rx_address_checked'),
        ];
    }

    public const REQUIRED_CONFIRMATIONS = 19;

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        if (empty($this->depositAddress)) {
            throw new \RuntimeException(__('services.gateways.trongrid.no_rx_address_configured_runtime'));
        }

        if (! str_starts_with($this->depositAddress, 'T') || strlen($this->depositAddress) !== 34) {
            throw new \RuntimeException(__('services.gateways.trongrid.wallet_address_invalid'));
        }

        return new PaymentAddressResult(
            address: $this->depositAddress,
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
                    __('services.gateways.trongrid.tx_not_synced')
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
                return PaymentVerificationResult::rejected(__('services.gateways.trongrid.tx_failed_result', ['result' => $receiptResult]), $infoData);
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
                        __('services.gateways.trongrid.amount_less', ['amount' => $trxAmount, 'requested' => $deposit->requested_amount]),
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

            return PaymentVerificationResult::pending('CheckingContract', __('services.gateways.trongrid.contract_checking'), $txData);
        } catch (\Throwable $e) {
            Log::error('TronGrid verifyTransaction error: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', __('services.gateways.trongrid.connection_error', ['error' => $e->getMessage()]));
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}