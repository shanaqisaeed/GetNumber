<?php

namespace App\Services\Communication\Push;

use App\Contracts\Communication\CallPushProviderInterface;
use App\DTOs\CallPushPayload;
use App\DTOs\PushDeliveryResult;
use App\Models\MobileDevice;
use App\Models\SystemSetting;
use App\Services\Communication\VoiceCallService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Android Native Local Push Provider (Intranet & Hybrid LAN Mode).
 * Delivers incoming call invites over the persistent native WebSocket connection
 * managed by the Go Voice Bridge Local Push Server without requiring FCM or Google Play Services.
 */
class AndroidLocalPushProvider implements CallPushProviderInterface
{
    public function providerKey(): string
    {
        return 'android_local';
    }

    public function supports(MobileDevice $device, string $networkMode): bool
    {
        return $device->platform === MobileDevice::PLATFORM_ANDROID
            && $device->isActive()
            && $networkMode !== 'OFFLINE';
    }

    public function isHealthy(MobileDevice $device): bool
    {
        return (bool) $device->local_push_connected;
    }

    public function sendCallInvite(MobileDevice $device, CallPushPayload $payload): PushDeliveryResult
    {
        return $this->dispatchOverLocalBridge($device, $payload->toArray(), true);
    }

    public function sendCallControl(MobileDevice $device, string $callUuid, string $eventType, array $extra = []): PushDeliveryResult
    {
        $data = array_merge([
            'schema_version' => 1,
            'event_type' => $eventType,
            'call_uuid' => $callUuid,
            'issued_at' => time(),
        ], $extra);

        return $this->dispatchOverLocalBridge($device, $data, false);
    }

    /**
     * @param array<string, mixed> $payloadData
     */
    protected function dispatchOverLocalBridge(MobileDevice $device, array $payloadData, bool $requireAck): PushDeliveryResult
    {
        $msgId = 'lpush_' . Str::lower(Str::random(16));
        $payloadData['msg_id'] = $msgId;

        if (! (bool) SystemSetting::get('voice_bridge_enabled', false)) {
            if ($device->local_push_connected) {
                return new PushDeliveryResult(
                    delivered: true,
                    provider: $this->providerKey(),
                    deviceUuid: $device->device_uuid,
                    acknowledged: true,
                    messageId: $msgId,
                );
            }

            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                acknowledged: false,
                errorReason: 'voice_bridge_disabled',
            );
        }

        try {
            $host = SystemSetting::get('voice_bridge_host', '127.0.0.1');
            $apiPort = (int) SystemSetting::get('voice_bridge_api_port', 49000);
            $secret = SystemSetting::get('voice_bridge_secret', '');
            $url = VoiceCallService::buildBridgeUrl($host, $apiPort, '/api/local-push/dispatch');

            $response = Http::timeout(1.8)
                ->withHeaders(array_filter(['X-Bridge-Secret' => $secret]))
                ->post($url, [
                    'device_uuid' => $device->device_uuid,
                    'user_id' => $device->user_id,
                    'platform' => 'android',
                    'msg_id' => $msgId,
                    'require_ack' => $requireAck,
                    'ack_timeout_ms' => 1400,
                    'payload' => $payloadData,
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $delivered = (bool) ($body['delivered'] ?? false);
                $acked = (bool) ($body['acknowledged'] ?? $delivered);

                if (! $delivered) {
                    $device->update(['local_push_connected' => false]);
                }

                return new PushDeliveryResult(
                    delivered: $delivered,
                    provider: $this->providerKey(),
                    deviceUuid: $device->device_uuid,
                    acknowledged: $acked,
                    messageId: $msgId,
                    errorReason: $delivered ? null : ($body['error'] ?? 'local_socket_not_connected'),
                );
            }

            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                acknowledged: false,
                errorReason: 'bridge_http_' . $response->status(),
            );
        } catch (\Throwable $e) {
            return new PushDeliveryResult(
                delivered: false,
                provider: $this->providerKey(),
                deviceUuid: $device->device_uuid,
                acknowledged: false,
                errorReason: 'bridge_unreachable: ' . $e->getMessage(),
            );
        }
    }
}
