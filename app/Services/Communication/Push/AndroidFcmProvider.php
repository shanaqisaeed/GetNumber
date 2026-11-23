<?php

namespace App\Services\Communication\Push;

use App\Contracts\Communication\CallPushProviderInterface;
use App\DTOs\CallPushPayload;
use App\DTOs\PushDeliveryResult;
use App\Models\MobileDevice;
use App\Models\SystemSetting;
use App\Services\Communication\DeviceRegistryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Firebase Cloud Messaging (FCM HTTP v1) Provider for Android (Public Mode).
 *
 * Strictly sends High-Priority Data-only messages (no `notification` key) so
 * `FirebaseMessagingService.onMessageReceived` wakes up in background/Doze and hands
 * the incoming call to Android Core-Telecom / ConnectionService.
 */
class AndroidFcmProvider implements CallPushProviderInterface
{
    public function __construct(
        protected DeviceRegistryService $deviceRegistry
    ) {}

    public function providerKey(): string
    {
        return 'android_fcm';
    }

    public function supports(MobileDevice $device, string $networkMode): bool
    {
        // Strictly disabled in INTRANET or OFFLINE mode
        if (in_array($networkMode, ['INTRANET', 'OFFLINE'], true)) {
            return false;
        }

        return $device->platform === MobileDevice::PLATFORM_ANDROID
            && $device->isActive()
            && ! empty($device->notification_push_token_hash);
    }

    public function isHealthy(MobileDevice $device): bool
    {
        return ! empty($device->notification_push_token_hash) && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        $projectId = trim((string) SystemSetting::get('fcm_project_id', config('services.fcm.project_id', '')));
        $saJson = $this->resolveServiceAccountArray();

        return $projectId !== '' && ! empty($saJson['client_email']) && ! empty($saJson['private_key']);
    }

    public function sendCallInvite(MobileDevice $device, CallPushPayload $payload): PushDeliveryResult
    {
        return $this->sendFcmDataMessage($device, $payload->toFcmDataMap(), $payload->callUuid, 45);
    }

    public function sendCallControl(MobileDevice $device, string $callUuid, string $eventType, array $extra = []): PushDeliveryResult
    {
        $dataMap = [
            'schema_version' => '1',
            'event_type' => $eventType,
            'call_uuid' => $callUuid,
            'issued_at' => (string) time(),
        ];
        foreach ($extra as $k => $v) {
            $dataMap[(string) $k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        return $this->sendFcmDataMessage($device, $dataMap, $callUuid, 30);
    }

    /**
     * @param array<string, string> $dataMap
     */
    protected function sendFcmDataMessage(MobileDevice $device, array $dataMap, string $callUuid, int $ttlSeconds): PushDeliveryResult
    {
        $fcmToken = $device->getDecryptedNotificationPushToken();
        if (! $fcmToken) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'missing_fcm_token',
            );
        }

        if (! $this->isConfigured()) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'fcm_credentials_not_configured',
            );
        }

        $accessToken = $this->resolveOAuthAccessToken();
        if (! $accessToken) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'fcm_oauth_token_failed',
            );
        }

        $projectId = trim((string) SystemSetting::get('fcm_project_id', config('services.fcm.project_id', '')));
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        try {
            $response = Http::timeout(3.0)
                ->withToken($accessToken)
                ->post($endpoint, [
                    'message' => [
                        'token' => $fcmToken,
                        'android' => [
                            'priority' => 'HIGH',
                            'ttl' => "{$ttlSeconds}s",
                            'collapse_key' => "call_{$callUuid}",
                        ],
                        'data' => $dataMap,
                    ],
                ]);

            if ($response->successful()) {
                return new PushDeliveryResult(
                    delivered: true,
                    provider: $this->providerKey(),
                    deviceUuid: $device->device_uuid,
                    acknowledged: false,
                    messageId: (string) ($response->json('name') ?? ''),
                );
            }

            $body = $response->json();
            $errCode = (string) ($body['error']['details'][0]['errorCode'] ?? $body['error']['status'] ?? ('http_' . $response->status()));
            $tokenInvalidated = false;

            if (in_array($response->status(), [400, 404], true) && in_array($errCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
                $this->deviceRegistry->invalidatePushToken('fcm', $device->notification_push_token_hash ?: $fcmToken, $errCode);
                $tokenInvalidated = true;
            }

            Log::warning('[FCM-v1] Call push failed', [
                'device_uuid' => $device->device_uuid,
                'status' => $response->status(),
                'error_code' => $errCode,
                'token_fingerprint' => $device->redactedTokenFingerprint(),
            ]);

            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                tokenInvalidated: $tokenInvalidated,
                errorReason: $errCode,
            );
        } catch (\Throwable $e) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'fcm_network_error: ' . $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveServiceAccountArray(): array
    {
        $rawJson = trim((string) SystemSetting::get('fcm_service_account_json', ''));
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = trim((string) SystemSetting::get('fcm_service_account_path', config('services.fcm.service_account_path', '')));
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function resolveOAuthAccessToken(): ?string
    {
        // Allow pre-injected test token if set in config
        $staticToken = trim((string) config('services.fcm.test_access_token', ''));
        if ($staticToken !== '') {
            return $staticToken;
        }

        return Cache::remember('getnumber:fcm_oauth_access_token', 3000, function () {
            $sa = $this->resolveServiceAccountArray();
            if (empty($sa['client_email']) || empty($sa['private_key'])) {
                return null;
            }

            $now = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signingInput = "{$header}.{$claims}";
            $pkey = @openssl_pkey_get_private($sa['private_key']);
            if (! $pkey) {
                return null;
            }

            $signature = '';
            if (! @openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            $assertion = "{$signingInput}." . $this->base64UrlEncode($signature);

            try {
                $res = Http::asForm()->timeout(3.0)->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
                if ($res->successful()) {
                    return $res->json('access_token');
                }
            } catch (\Throwable) {
            }

            return null;
        });
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
