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
        if (empty($this->depositAddress)) {
            throw new RuntimeException('آدرس والت دریافت ترون (TRX) در تنظیمات درگاه ترون اسکن تنظیم نشده است. امکان تولید آدرس ساختگی وجود ندارد.');
        }

        if (! str_starts_with($this->depositAddress, 'T') || strlen($this->depositAddress) !== 34) {
            throw new RuntimeException('آدرس والت دریافت ترون در تنظیمات معتبر نیست (باید با حرف T آغاز شده و ۳۴ کاراکتر باشد).');
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
                'فرمت کد رهگیری تراکنش ترون نامعتبر است (باید دقیقاً ۶۴ نویسه هگزادسیمال باشد).'
            );
        }

        // 2. Prevent Replay Attack / Front-Running by checking if this hash was used by any other completed deposit
        $alreadyClaimed = CryptoDeposit::where('tx_hash', $cleanTxHash)
            ->where('id', '!=', $deposit->id)
            ->whereIn('status', [\App\Enums\PaymentStatus::Completed->value])
            ->exists();

        if ($alreadyClaimed) {
            return PaymentVerificationResult::rejected(
                'این شناسه تراکنش (TXID) پیش از این برای سفارش دیگری تأیید و مصرف شده است (جلوگیری از دوباره‌خرجی).'
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
                    return PaymentVerificationResult::rejected('تراکنش در بلاکچین ترون با شکست مواجه شده است.', $data);
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
                                'تراکنش ارائه شده قبل از صدور فاکتور واریز انجام شده است و امکان پذیرش تراکنش‌های گذشته وجود ندارد.',
                                $data
                            );
                        }
                    }

                    // 4. Verify Destination Address matches platform deposit address
                    if ($deposit->deposit_address && ! empty($destAddress) && strcmp($destAddress, $deposit->deposit_address) !== 0) {
                        return PaymentVerificationResult::rejected(
                            "آدرس مقصد تراکنش ({$destAddress}) با آدرس تعیین‌شده مطابقت ندارد.",
                            $data
                        );
                    }

                    // 5. Verify Amount
                    if (bccomp($amountTrx, (string) $deposit->requested_amount, 4) < 0) {
                        return PaymentVerificationResult::rejected(
                            "مبلغ واریزشده ({$amountTrx} TRX) کمتر از مبلغ درخواست ({$deposit->requested_amount} TRX) است.",
                            $data
                        );
                    }

                    // 6. Verify Finality and Confirmation Threshold (Re-org Protection)
                    if (! $confirmed || $confirmations < self::REQUIRED_CONFIRMATIONS) {
                        return PaymentVerificationResult::pending(
                            'Confirming',
                            "تراکنش با موفقیت در بلاک قرار گرفته و در حال تکمیل تأییدیه‌های قطعی شبکه است ({$confirmations}/" . self::REQUIRED_CONFIRMATIONS . " تأیید).",
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
