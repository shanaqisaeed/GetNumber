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
        return __('services.gateways.wallex.name');
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
                'message' => __('services.gateways.wallex.no_api_key'),
            ];
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
            ])->timeout(8)->get("{$baseUrl}/v1/profile");

            if ($response->successful()) {
                return [
                    'valid' => true,
                    'message' => __('services.gateways.wallex.connection_success'),
                    'details' => $response->json('result') ?? [],
                ];
            }

            return [
                'valid' => false,
                'message' => __('services.gateways.wallex.api_key_invalid', ['status' => $response->status()]),
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => __('services.gateways.wallex.connection_error', ['error' => $e->getMessage()]),
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
                'message' => __('services.gateways.wallex.sync_no_api_key'),
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
                        'message' => __('services.gateways.wallex.wallet_synced'),
                    ];
                }
            }

            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => __('services.gateways.wallex.wallet_not_found'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => __('services.gateways.wallex.sync_error', ['error' => $e->getMessage()]),
            ];
        }
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        if (empty($this->depositAddress)) {
            throw new \RuntimeException(__('services.gateways.wallex.no_rx_address_configured'));
        }

        return new PaymentAddressResult(
            address: $this->depositAddress,
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
            return PaymentVerificationResult::pending('NoApiKey', __('services.gateways.wallex.verification_no_api_key'));
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
                                    __('services.gateways.wallex.amount_less', ['amount' => $amount, 'requested' => $deposit->requested_amount]),
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

                        return PaymentVerificationResult::pending($status, __('services.gateways.wallex.pending_status', ['status' => $status]), $item);
                    }
                }
            }

            return PaymentVerificationResult::pending('NotFound', __('services.gateways.wallex.not_found'));
        } catch (\Throwable $e) {
            Log::error('Wallex verifyTransaction exception: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', __('services.gateways.wallex.query_error', ['error' => $e->getMessage()]));
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}