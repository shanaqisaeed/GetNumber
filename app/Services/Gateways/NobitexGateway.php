<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NobitexGateway implements PaymentGatewayInterface
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?string $secretKey;
    protected ?int $walletId;
    protected ?string $depositAddress;
    protected ?string $addressOverride;

    public function __construct(?PaymentGateway $model = null)
    {
        $config = $model?->config ?? [];

        $rawUrl = $config['base_url'] ?? env('NOBITEX_BASE_URL', 'https://apiv2.nobitex.ir');
        $this->baseUrl = $this->normalizeBaseUrl($rawUrl);
        $this->apiKey = $this->normalizeKey($config['api_key'] ?? env('NOBITEX_API_KEY'));
        $this->secretKey = $this->normalizeKey($config['secret_key'] ?? env('NOBITEX_SECRET_KEY'));
        $this->walletId = isset($config['wallet_id']) && $config['wallet_id'] !== '' ? (int) $config['wallet_id'] : null;
        $this->depositAddress = $config['deposit_address'] ?? null;
        $this->addressOverride = $config['deposit_address_override'] ?? env('NOBITEX_DEPOSIT_ADDRESS_OVERRIDE');
    }

    /**
     * Clean and normalize API base URL (ensure apiv2.nobitex.ir is used).
     */
    protected function normalizeBaseUrl(?string $url): string
    {
        $clean = trim((string) ($url ?? ''));
        if (empty($clean) || str_contains($clean, 'api.nobitex.ir')) {
            return 'https://apiv2.nobitex.ir';
        }
        return rtrim($clean, '/');
    }

    /**
     * Clean API Key / Secret Key (strip leading Token or Bearer prefix if user copied it with header).
     */
    protected function normalizeKey(?string $key): string
    {
        $clean = trim((string) ($key ?? ''));
        if (preg_match('/^(token|bearer)\s+(.*)$/i', $clean, $matches)) {
            return trim($matches[2]);
        }
        return $clean;
    }

    /**
     * Check if Ed25519 signature authentication is active.
     */
    public function isEd25519(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->secretKey);
    }

    /**
     * URL-safe Base64 encoding according to RFC 4648 §5.
     */
    public function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
    }

    /**
     * URL-safe Base64 decoding according to RFC 4648 §5.
     */
    public function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Sign payload using Ed25519 and the provided secret key.
     */
    public function signPayload(string $payload, string $secretKey): string
    {
        if (! extension_loaded('sodium')) {
            throw new \RuntimeException('اکستنشن sodium در PHP فعال نیست. لطفاً extension=sodium را در php.ini فعال نمایید.');
        }

        $secBytes = $this->base64UrlDecode($secretKey);

        if (strlen($secBytes) === 32) {
            $keypair = sodium_crypto_sign_seed_keypair($secBytes);
            $secretKeyBytes = sodium_crypto_sign_secretkey($keypair);
        } elseif (strlen($secBytes) === 64) {
            $secretKeyBytes = $secBytes;
        } else {
            throw new \InvalidArgumentException('طول کلید محرمانه (Secret Key) نامعتبر است. کلید Ed25519 باید ۳۲ بایتی (۴۴ کاراکتر Base64) باشد.');
        }

        $signatureBytes = sodium_crypto_sign_detached($payload, $secretKeyBytes);
        return $this->base64UrlEncode($signatureBytes);
    }

    /**
     * Build appropriate headers based on whether Ed25519 (Key + Signature) or legacy Token is used.
     */
    public function getAuthHeaders(
        ?string $apiKey = null,
        ?string $secretKey = null,
        string $method = 'GET',
        string $path = '/',
        string $body = ''
    ): array {
        $key = $this->normalizeKey($apiKey ?? $this->apiKey);
        $sec = $this->normalizeKey($secretKey ?? $this->secretKey);

        if (empty($key)) {
            return ['Accept' => 'application/json'];
        }

        if (! empty($sec)) {
            $timestamp = (string) time();
            $parsed = parse_url($path);
            $endpointPath = ($parsed['path'] ?? '/');
            if (isset($parsed['query']) && $parsed['query'] !== '') {
                $endpointPath .= '?' . $parsed['query'];
            }
            $endpointPath = '/' . ltrim($endpointPath, '/');

            $payload = $timestamp . strtoupper($method) . $endpointPath . $body;
            $signature = $this->signPayload($payload, $sec);

            return [
                'Nobitex-Key' => $key,
                'Nobitex-Timestamp' => $timestamp,
                'Nobitex-Signature' => $signature,
                'User-Agent' => 'TraderBot/GetNumber-1.0',
                'Accept' => 'application/json',
            ];
        }

        return [
            'Authorization' => 'Token ' . $key,
            'User-Agent' => 'TraderBot/GetNumber-1.0',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Execute an authenticated HTTP request to Nobitex API with signature or token.
     */
    protected function request(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $apiKey = null,
        ?string $secretKey = null,
        ?string $baseUrl = null
    ): Response {
        $method = strtoupper($method);
        $baseUrl = $this->normalizeBaseUrl($baseUrl ?? $this->baseUrl);
        $key = $this->normalizeKey($apiKey ?? $this->apiKey);
        $sec = $this->normalizeKey($secretKey ?? $this->secretKey);

        $path = '/' . ltrim($endpoint, '/');
        $url = $baseUrl . $path;
        $bodyString = '';

        if ($method === 'GET') {
            if (! empty($data)) {
                $queryString = http_build_query($data);
                $separator = str_contains($path, '?') ? '&' : '?';
                $path .= $separator . $queryString;
                $url .= $separator . $queryString;
            }
        } else {
            if (! empty($data)) {
                $bodyString = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'TraderBot/GetNumber-1.0',
        ];

        if ($bodyString !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        if (! empty($key) && ! empty($sec)) {
            $timestamp = (string) time();
            $payload = $timestamp . $method . $path . $bodyString;
            $signature = $this->signPayload($payload, $sec);

            $headers['Nobitex-Key'] = $key;
            $headers['Nobitex-Timestamp'] = $timestamp;
            $headers['Nobitex-Signature'] = $signature;
        } elseif (! empty($key)) {
            $headers['Authorization'] = 'Token ' . $key;
        }

        $http = Http::withHeaders($headers)->timeout(15);

        if ($method === 'GET') {
            return $http->get($url);
        } elseif ($method === 'POST') {
            return $http->withBody($bodyString, 'application/json')->post($url);
        } elseif ($method === 'PUT') {
            return $http->withBody($bodyString, 'application/json')->put($url);
        } elseif ($method === 'DELETE') {
            return $http->delete($url);
        }

        return $http->send($method, $url);
    }

    public function getIdentifier(): string
    {
        return 'nobitex';
    }

    public function getName(): string
    {
        return 'صرافی نوبیتکس (Nobitex)';
    }

    /**
     * Validate the Nobitex API Key by sending an authenticated probe.
     */
    public function validateCredentials(array $config): array
    {
        $apiKey = $this->normalizeKey($config['api_key'] ?? $this->apiKey);
        $secretKey = $this->normalizeKey($config['secret_key'] ?? $this->secretKey);
        $baseUrl = $this->normalizeBaseUrl($config['base_url'] ?? $this->baseUrl);

        if (empty($apiKey)) {
            return [
                'valid' => false,
                'message' => 'کلید API صرافی نوبیتکس وارد نشده است.',
            ];
        }

        $isEd25519 = ! empty($secretKey);

        try {
            // If Ed25519 keypair is used, check wallets endpoint; for legacy token, probe profile endpoint
            $endpoint = $isEd25519 ? '/users/wallets/list' : '/users/profile';
            $response = $this->request('GET', $endpoint, [], $apiKey, $secretKey, $baseUrl);

            // Fallback retry with Bearer header if legacy TokenAuth returned 401/403
            if (! $isEd25519 && in_array($response->status(), [401, 403])) {
                $bearerResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'User-Agent' => 'TraderBot/GetNumber-1.0',
                    'Accept' => 'application/json',
                ])->timeout(10)->get("{$baseUrl}/users/profile");

                if ($bearerResponse->successful()) {
                    $response = $bearerResponse;
                }
            }

            if ($response->successful()) {
                $status = $response->json('status');
                if ($status === 'failed') {
                    $err = $response->json('message') ?? 'توکن نامعتبر است';
                    return [
                        'valid' => false,
                        'message' => "اعتبارسنجی در نوبیتکس رد شد: {$err}",
                    ];
                }

                return [
                    'valid' => true,
                    'message' => 'ارتباط با نوبیتکس برقرار و کلیدهای احراز هویت معتبر هستند.',
                    'details' => $response->json('profile') ?? $response->json('wallets') ?? [],
                ];
            }

            $rawBody = $response->body();
            $status = $response->status();
            $detail = $response->json('detail') ?? $response->json('message') ?? $rawBody;

            // Handle IP Whitelist restriction
            if ($status === 401 && (str_contains($rawBody, 'not whitelisted') || str_contains($rawBody, 'whitelist'))) {
                return [
                    'valid' => false,
                    'is_ip_restricted' => true,
                    'message' => "کلیدها در نوبیتکس تأیید شدند، اما IP سیستم در لیست مجاز کلید نیست ({$detail}). لطفاً این IP را در پنل کاربری نوبیتکس (تنظیمات > مدیریت کلیدهای API) به فهرست مجاز (Whitelist) اضافه فرمایید.",
                ];
            }

            // Handle base64 public key provided without secret key
            if ($status === 401 && ! $isEd25519 && strlen($apiKey) >= 40 && str_ends_with($apiKey, '=')) {
                return [
                    'valid' => false,
                    'message' => 'کلید وارد شده یک کلید عمومی Ed25519 (API Key) است. برای اتصال نوبیتکس، وارد کردن هر دو کلید عمومی و کلید محرمانه (Secret Key) الزامی است.',
                ];
            }

            if ($status === 401 && (str_contains($rawBody, 'توکن غیر مجاز') || str_contains($rawBody, 'Invalid token'))) {
                return [
                    'valid' => false,
                    'message' => 'توکن وارد شده در نوبیتکس مجاز شناخته نشد (پیام نوبیتکس: توکن غیر مجاز). لطفاً کلید جدیدی از پنل کاربری نوبیتکس دریافت نمایید.',
                ];
            }

            $errMsg = $detail ?: 'خطای احراز هویت در نوبیتکس (کد HTTP ' . $status . ')';
            return [
                'valid' => false,
                'message' => "کلید API نامعتبر است: {$errMsg}",
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => 'خطا در اتصال به سرور نوبیتکس: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Query Nobitex wallets, locate the TRX wallet, fetch or generate its deposit address, and update config.
     */
    public function syncWallets(array &$config): array
    {
        $apiKey = $this->normalizeKey($config['api_key'] ?? $this->apiKey);
        $secretKey = $this->normalizeKey($config['secret_key'] ?? $this->secretKey);
        $baseUrl = $this->normalizeBaseUrl($config['base_url'] ?? $this->baseUrl);

        if (empty($apiKey)) {
            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => 'کلید API جهت همگام‌سازی کیف پول تنظیم نشده است.',
            ];
        }

        try {
            $response = $this->request('GET', '/users/wallets/list', [], $apiKey, $secretKey, $baseUrl);

            if (! $response->successful()) {
                $err = $response->json('detail') ?? $response->json('message') ?? $response->body();
                return [
                    'success' => false,
                    'currency' => 'TRX',
                    'changed' => false,
                    'message' => 'خطا در دریافت فهرست کیف‌پول‌های نوبیتکس: ' . $err,
                ];
            }

            $wallets = (array) ($response->json('wallets') ?? []);
            $trxWallet = null;

            foreach ($wallets as $w) {
                if (strtolower((string) ($w['currency'] ?? '')) === 'trx') {
                    $trxWallet = $w;
                    break;
                }
            }

            if (! $trxWallet) {
                return [
                    'success' => false,
                    'currency' => 'TRX',
                    'changed' => false,
                    'message' => 'کیف پول ترون (TRX) در حساب نوبیتکس یافت نشد. لطفاً در پنل نوبیتکس کیف پول TRX ایجاد نمایید.',
                ];
            }

            $walletId = (int) $trxWallet['id'];
            $depositAddress = $trxWallet['depositAddress'] ?? $trxWallet['address'] ?? null;

            // If depositAddress is not assigned, request generation
            if (empty($depositAddress)) {
                $genResponse = $this->request('POST', '/users/wallets/generate-address', [
                    'wallet' => $walletId,
                    'currency' => 'trx',
                ], $apiKey, $secretKey, $baseUrl);

                if ($genResponse->successful()) {
                    $depositAddress = $genResponse->json('address') ?? null;
                }
            }

            $oldAddress = $config['deposit_address'] ?? null;
            $changed = ! empty($oldAddress) && ! empty($depositAddress) && strcasecmp($oldAddress, $depositAddress) !== 0;

            // Update configuration
            $config['wallet_id'] = $walletId;
            if (! empty($depositAddress)) {
                $config['deposit_address'] = $depositAddress;
            }
            $config['min_deposit'] = $config['min_deposit'] ?? '1.0000';

            return [
                'success' => ! empty($depositAddress),
                'wallet_id' => $walletId,
                'address' => $depositAddress,
                'currency' => 'TRX',
                'changed' => $changed,
                'message' => $changed
                    ? 'آدرس کیف پول TRX تغییر کرده بود و در پایگاه‌داده به‌روزرسانی شد.'
                    : 'کیف پول TRX با موفقیت تأیید و همگام‌سازی شد.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => 'خطا در فرآیند همگام‌سازی کیف پول نوبیتکس: ' . $e->getMessage(),
            ];
        }
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

        // 2. If synced deposit address from Nobitex exists in database config, use it directly
        if (! empty($this->depositAddress)) {
            return new PaymentAddressResult(
                address: $this->depositAddress,
                currency: 'TRX',
                network: 'TRON',
                tag: null,
                qrCodeUrl: null,
                expiresAt: now()->addHours(2),
                extra: ['source' => 'synced_nobitex_wallet', 'wallet_id' => $this->walletId]
            );
        }

        // 3. Check API Key
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

            $response = $this->request('POST', '/users/wallets/generate-address', [
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

            $response = $this->request('GET', '/users/wallets/deposits/list', $params);

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

        $response = $this->request('GET', '/users/wallets/list');

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
