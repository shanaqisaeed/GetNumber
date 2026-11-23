<?php

namespace App\Services\Communication\Push;

use App\Contracts\Communication\CallPushProviderInterface;
use App\DTOs\CallPushPayload;
use App\DTOs\PushDeliveryResult;
use App\Models\MobileDevice;
use App\Models\SystemSetting;
use App\Services\Communication\DeviceRegistryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Apple Push Notification service (APNs) PushKit VoIP Provider (Public Mode).
 *
 * Strictly adheres to Apple PushKit rules:
 * - Only used for VoIP incoming call invitations and call cancellation/control.
 * - Uses HTTP/2 with ES256 JWT (.p8) authentication.
 * - Automatically invalidates tokens on HTTP 410 (Unregistered) or BadDeviceToken.
 */
class IosApnsVoipProvider implements CallPushProviderInterface
{
    public function __construct(
        protected DeviceRegistryService $deviceRegistry
    ) {}

    public function providerKey(): string
    {
        return 'ios_apns_voip';
    }

    public function supports(MobileDevice $device, string $networkMode): bool
    {
        // Strictly disabled in INTRANET or OFFLINE mode
        if (in_array($networkMode, ['INTRANET', 'OFFLINE'], true)) {
            return false;
        }

        return $device->platform === MobileDevice::PLATFORM_IOS
            && $device->isActive()
            && ! empty($device->voip_push_token_hash);
    }

    public function isHealthy(MobileDevice $device): bool
    {
        return ! empty($device->voip_push_token_hash) && $this->isConfigured();
    }

    public function isConfigured(): bool
    {
        $keyId = trim((string) SystemSetting::get('apns_key_id', config('services.apns.key_id', '')));
        $teamId = trim((string) SystemSetting::get('apns_team_id', config('services.apns.team_id', '')));
        $privateKey = $this->resolvePrivateKeyPem();

        return $keyId !== '' && $teamId !== '' && $privateKey !== '';
    }

    public function sendCallInvite(MobileDevice $device, CallPushPayload $payload): PushDeliveryResult
    {
        return $this->sendVoipPush($device, $payload->toArray(), $payload->callUuid, $payload->expiresAt);
    }

    public function sendCallControl(MobileDevice $device, string $callUuid, string $eventType, array $extra = []): PushDeliveryResult
    {
        $data = array_merge([
            'schema_version' => 1,
            'event_type' => $eventType,
            'call_uuid' => $callUuid,
            'issued_at' => time(),
            'expires_at' => time() + 30,
        ], $extra);

        return $this->sendVoipPush($device, $data, $callUuid, time() + 30);
    }

    /**
     * @param array<string, mixed> $bodyPayload
     */
    protected function sendVoipPush(MobileDevice $device, array $bodyPayload, string $collapseId, int $expiresAt): PushDeliveryResult
    {
        $voipToken = $device->getDecryptedVoipPushToken();
        if (! $voipToken) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'missing_voip_token',
            );
        }

        if (! $this->isConfigured()) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'apns_credentials_not_configured',
            );
        }

        $jwt = $this->buildApnsJwt();
        if (! $jwt) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'apns_jwt_signing_failed',
            );
        }

        $bundleId = trim((string) SystemSetting::get('ios_bundle_id', 'com.ravyno.softphone'));
        $topic = str_ends_with($bundleId, '.voip') ? $bundleId : "{$bundleId}.voip";
        $isSandbox = (bool) (int) SystemSetting::get('apns_sandbox', '1');
        $host = $isSandbox ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
        $endpoint = "{$host}/3/device/{$voipToken}";

        try {
            $response = Http::timeout(3.0)
                ->withOptions([
                    'version' => 2.0,
                ])
                ->withHeaders([
                    'authorization' => "bearer {$jwt}",
                    'apns-push-type' => 'voip',
                    'apns-topic' => $topic,
                    'apns-priority' => '10',
                    'apns-expiration' => (string) ($expiresAt > 0 ? $expiresAt : (time() + 45)),
                    'apns-collapse-id' => $collapseId,
                ])
                ->post($endpoint, [
                    'aps' => [
                        'content-available' => 1,
                    ],
                    'call_data' => $bodyPayload,
                ]);

            if ($response->status() === 200) {
                $apnsId = $response->header('apns-id');
                return new PushDeliveryResult(
                    delivered: true,
                    provider: $this->providerKey(),
                    deviceUuid: $device->device_uuid,
                    acknowledged: false,
                    messageId: $apnsId ?: null,
                );
            }

            $errReason = (string) ($response->json('reason') ?? ('http_' . $response->status()));
            $tokenInvalidated = false;

            if (in_array($response->status(), [400, 410], true) && in_array($errReason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                $this->deviceRegistry->invalidatePushToken('apns_voip', $device->voip_push_token_hash ?: $voipToken, $errReason);
                $tokenInvalidated = true;
            }

            Log::warning('[APNs-VoIP] Push delivery failed', [
                'device_uuid' => $device->device_uuid,
                'status' => $response->status(),
                'reason' => $errReason,
                'token_fingerprint' => $device->redactedTokenFingerprint(),
            ]);

            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                tokenInvalidated: $tokenInvalidated,
                errorReason: $errReason,
            );
        } catch (\Throwable $e) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                errorReason: 'apns_network_error: ' . $e->getMessage(),
            );
        }
    }

    protected function resolvePrivateKeyPem(): string
    {
        $inlineKey = trim((string) SystemSetting::get('apns_private_key', ''));
        if ($inlineKey !== '') {
            return $inlineKey;
        }

        $keyPath = trim((string) SystemSetting::get('apns_private_key_path', config('services.apns.private_key_path', '')));
        if ($keyPath !== '' && is_file($keyPath) && is_readable($keyPath)) {
            return (string) file_get_contents($keyPath);
        }

        return '';
    }

    protected function buildApnsJwt(): ?string
    {
        $keyId = trim((string) SystemSetting::get('apns_key_id', config('services.apns.key_id', '')));
        $teamId = trim((string) SystemSetting::get('apns_team_id', config('services.apns.team_id', '')));
        $pem = $this->resolvePrivateKeyPem();

        if ($keyId === '' || $teamId === '' || $pem === '') {
            return null;
        }

        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $claims = $this->base64UrlEncode(json_encode(['iss' => $teamId, 'iat' => time()]));
        $signingInput = "{$header}.{$claims}";

        $pkey = @openssl_pkey_get_private($pem);
        if (! $pkey) {
            return null;
        }

        $signature = '';
        if (! @openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return "{$signingInput}." . $this->base64UrlEncode($signature);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
