<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TronScanGateway implements PaymentGatewayInterface
{
    /**
     * Minimum required confirmations on Tron network to guarantee finality & prevent re-orgs.
     */
    public const REQUIRED_CONFIRMATIONS = 19;

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
        return __('services.gateways.tronscan.name');
    }

    public function validateCredentials(array $config): array
    {
        $address = $config['deposit_address'] ?? null;
        if (empty($address)) {
            return [
                'valid' => false,
                'message' => __('services.gateways.tronscan.no_rx_address'),
            ];
        }

        if (! str_starts_with($address, 'T') || strlen($address) !== 34) {
            return [
                'valid' => false,
                'message' => __('services.gateways.tronscan.rx_address_invalid'),
            ];
        }

        return [
            'valid' => true,
            'message' => __('services.gateways.tronscan.rx_address_verified'),
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
            'message' => __('services.gateways.tronscan.rx_address_checked'),
        ];
    }

    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult
    {
        if (empty($this->depositAddress)) {
            throw new RuntimeException(__('services.gateways.tronscan.no_rx_address_configured'));
        }

        if (! str_starts_with($this->depositAddress, 'T') || strlen($this->depositAddress) !== 34) {
            throw new RuntimeException(__('services.gateways.tronscan.rx_address_invalid_config'));
        }

        return new PaymentAddressResult(
            address: $this->depositAddress,
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

        // 1. Strict Hex Hash Validation (64-character hexadecimal)
        if (! preg_match('/^[a-fA-F0-9]{64}$/', $cleanTxHash)) {
            return PaymentVerificationResult::rejected(
                __('services.gateways.tronscan.tx_hash_invalid')
            );
        }

        // 2. Prevent Replay Attack / Front-Running by checking if this hash was used by any other completed deposit
        $alreadyClaimed = CryptoDeposit::where('tx_hash', $cleanTxHash)
            ->where('id', '!=', $deposit->id)
            ->whereIn('status', [\App\Enums\PaymentStatus::Completed->value])
            ->exists();

        if ($alreadyClaimed) {
            return PaymentVerificationResult::rejected(
                __('services.gateways.tronscan.tx_hash_already_used')
            );
        }

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
                $confirmations = (int) ($data['confirmations'] ?? 0);

                if (strtoupper($contractRet) === 'FAILED' || strtoupper($contractRet) === 'REVERT') {
                    return PaymentVerificationResult::rejected(__('services.gateways.tronscan.tx_failed'), $data);
                }

                if (strtoupper($contractRet) === 'SUCCESS') {
                    $contractData = $data['contractData'] ?? [];
                    $amountSun = $contractData['amount'] ?? 0;
                    $destAddress = $contractData['to_address'] ?? '';
                    $fromAddress = $contractData['owner_address'] ?? '';

                    $amountTrx = bcdiv((string) $amountSun, '1000000', 4);

                    // 3. Verify Transaction Timestamp to Prevent Front-Running with Historical Transactions
                    // TronScan provides timestamp in milliseconds
                    if (! empty($data['timestamp'])) {
                        $txTime = Carbon::createFromTimestampMs($data['timestamp']);
                        // Transaction cannot have occurred more than 10 minutes before deposit was created
                        $earliestAllowed = $deposit->created_at->subMinutes(10);
                        if ($txTime->lt($earliestAllowed)) {
                            return PaymentVerificationResult::rejected(
                                __('services.gateways.tronscan.tx_before_invoice'),
                                $data
                            );
                        }
                    }

                    // 4. Verify Destination Address matches platform deposit address
                    if ($deposit->deposit_address && ! empty($destAddress) && strcmp($destAddress, $deposit->deposit_address) !== 0) {
                        return PaymentVerificationResult::rejected(
                            __('services.gateways.tronscan.dest_mismatch', ['address' => $destAddress]),
                            $data
                        );
                    }

                    // 5. Verify Amount
                    if (bccomp($amountTrx, (string) $deposit->requested_amount, 4) < 0) {
                        return PaymentVerificationResult::rejected(
                            __('services.gateways.tronscan.amount_less', ['amount' => $amountTrx, 'requested' => $deposit->requested_amount]),
                            $data
                        );
                    }

                    // 6. Verify Finality and Confirmation Threshold (Re-org Protection)
                    if (! $confirmed || $confirmations < self::REQUIRED_CONFIRMATIONS) {
                        return PaymentVerificationResult::pending(
                            'Confirming',
                            __('services.gateways.tronscan.confirming_count', ['current' => $confirmations, 'required' => self::REQUIRED_CONFIRMATIONS]),
                            $data
                        );
                    }

                    return PaymentVerificationResult::verified(
                        txHash: $cleanTxHash,
                        amount: $amountTrx,
                        currency: 'TRX',
                        destinationAddress: $destAddress,
                        network: 'TRON',
                        confirmations: $confirmations,
                        raw: array_merge($data, ['from_address' => $fromAddress])
                    );
                }

                return PaymentVerificationResult::pending('Confirming', __('services.gateways.tronscan.confirming'), $data);
            }

            return PaymentVerificationResult::pending('NotFound', __('services.gateways.tronscan.tx_not_found'));
        } catch (\Throwable $e) {
            Log::error('TronScan verifyTransaction exception: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', __('services.gateways.tronscan.query_error', ['error' => $e->getMessage()]));
        }
    }

    public function getRecentDeposits(?string $currency = null): array
    {
        return [];
    }
}