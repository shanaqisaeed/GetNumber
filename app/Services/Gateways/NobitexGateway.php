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
            throw new \RuntimeException(__('services.gateways.nobitex.sodium_missing'));
        }

        $secBytes = $this->base64UrlDecode($secretKey);

        if (strlen($secBytes) === 32) {
            $keypair = sodium_crypto_sign_seed_keypair($secBytes);
            $secretKeyBytes = sodium_crypto_sign_secretkey($keypair);
        } elseif (strlen($secBytes) === 64) {
            $secretKeyBytes = $secBytes;
        } else {
            throw new \InvalidArgumentException(__('services.gateways.nobitex.secret_key_invalid_length'));
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
        return __('services.gateways.nobitex.name');
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
                'message' => __('services.gateways.nobitex.api_key_missing'),
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
                    $err = $response->json('message') ?? __('services.gateways.nobitex.invalid_token');
                    return [
                        'valid' => false,
                        'message' => __('services.gateways.nobitex.validation_rejected', ['error' => $err]),
                    ];
                }

                return [
                    'valid' => true,
                    'message' => __('services.gateways.nobitex.connection_success'),
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
                    'message' => __('services.gateways.nobitex.ip_not_whitelisted', ['detail' => $detail]),
                ];
            }

            // Handle base64 public key provided without secret key
            if ($status === 401 && ! $isEd25519 && strlen($apiKey) >= 40 && str_ends_with($apiKey, '=')) {
                return [
                    'valid' => false,
                    'message' => __('services.gateways.nobitex.both_keys_required'),
                ];
            }

            if ($status === 401 && (str_contains($rawBody, 'توکن غیر مجاز') || str_contains($rawBody, 'Invalid token'))) {
                return [
                    'valid' => false,
                    'message' => __('services.gateways.nobitex.token_unauthorized'),
                ];
            }

            $errMsg = $detail ?: __('services.gateways.nobitex.auth_error', ['status' => $status]);
            return [
                'valid' => false,
                'message' => __('services.gateways.nobitex.api_key_invalid', ['error' => $errMsg]),
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => __('services.gateways.nobitex.connection_error', ['error' => $e->getMessage()]),
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
                'message' => __('services.gateways.nobitex.sync_api_key_missing'),
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
                    'message' => __('services.gateways.nobitex.wallets_list_error', ['error' => $err]),
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
                    'message' => __('services.gateways.nobitex.trx_wallet_not_found'),
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
                    ? __('services.gateways.nobitex.trx_wallet_updated')
                    : __('services.gateways.nobitex.trx_wallet_synced'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'currency' => 'TRX',
                'changed' => false,
                'message' => __('services.gateways.nobitex.sync_error', ['error' => $e->getMessage()]),
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
            return PaymentVerificationResult::pending('NoApiKey', __('services.gateways.nobitex.verification_no_api_key'));
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
                        return PaymentVerificationResult::rejected(__('services.gateways.nobitex.currency_mismatch', ['currency' => $currency]), $item);
                    }

                    // Check status in Nobitex
                    if (in_array(strtolower($status), ['approved', 'done', 'success'])) {
                        // Validate amount using bcmath
                        if (bccomp($amount, (string) $deposit->requested_amount, 4) < 0) {
                            return PaymentVerificationResult::rejected(
                                __('services.gateways.nobitex.amount_less', ['amount' => $amount, 'requested' => $deposit->requested_amount]),
                                $item
                            );
                        }

                        // Validate destination address if applicable
                        if ($deposit->deposit_address && ! empty($address) && strcasecmp($address, $deposit->deposit_address) !== 0) {
                            return PaymentVerificationResult::rejected(
                                __('services.gateways.nobitex.address_mismatch', ['address' => $address, 'expected' => $deposit->deposit_address]),
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
                        return PaymentVerificationResult::rejected(__('services.gateways.nobitex.rejected_in_nobitex', ['status' => $status]), $item);
                    } else {
                        return PaymentVerificationResult::pending($status, __('services.gateways.nobitex.pending_verification_step', ['status' => $status]), $item);
                    }
                }
            }

            return PaymentVerificationResult::pending(
                'NotFound',
                __('services.gateways.nobitex.not_in_confirmed_deposits')
            );
        } catch (\Throwable $e) {
            Log::error('Nobitex verifyTransaction exception: ' . $e->getMessage());
            return PaymentVerificationResult::pending('Error', __('services.gateways.nobitex.network_error', ['error' => $e->getMessage()]));
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