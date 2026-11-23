<?php

namespace App\Services\Communication\Push;

use App\DTOs\CallPushPayload;
use App\DTOs\PushDeliveryResult;
use App\Models\MobileDevice;
use App\Services\Communication\DeviceRegistryService;
use App\Services\Communication\NetworkModeManager;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Unified Push Gateway Router (Stage 4.2 & Stage 8.1).
 *
 * Rules enforced:
 * 1. Local-First Routing: If a device has an active, healthy Local Push connection
 *    and acknowledges delivery (`ACK`), NO public APNs/FCM push is sent for that device.
 * 2. Intranet Isolation: In `INTRANET` mode (WAN down / offline mode), APNs, FCM,
 *    and cloud Web Push endpoints are strictly bypassed so call setup never blocks on WAN timeouts.
 * 3. Deduplication: For a given `(call_uuid, device_uuid)`, at most one logical invite is dispatched.
 */
class PushGatewayService
{
    public function __construct(
        protected DeviceRegistryService $deviceRegistry,
        protected NetworkModeManager $networkModeManager,
        protected IosLocalPushProvider $iosLocalProvider,
        protected IosApnsVoipProvider $iosApnsProvider,
        protected AndroidLocalPushProvider $androidLocalProvider,
        protected AndroidFcmProvider $androidFcmProvider,
        protected WebPushNotificationService $webPushService,
    ) {}

    /**
     * Dispatch an incoming call invitation to all active devices of the callee user.
     *
     * @return array{
     *     network_mode: string,
     *     total_devices: int,
     *     delivered_count: int,
     *     results: array<int, array<string, mixed>>
     * }
     */
    public function dispatchIncomingCall(int $calleeUserId, CallPushPayload $payload, ?\App\Models\VoiceCall $callModel = null): array
    {
        // Reject expired invitations before dispatching
        if ($payload->expiresAt > 0 && time() > $payload->expiresAt) {
            return [
                'network_mode' => $this->networkModeManager->currentMode(),
                'total_devices' => 0,
                'delivered_count' => 0,
                'results' => [],
            ];
        }

        $networkMode = $this->networkModeManager->currentMode();
        $devices = $this->deviceRegistry->getActiveDevicesForUser($calleeUserId);
        $results = [];
        $deliveredCount = 0;

        foreach ($devices as $device) {
            $dedupKey = "ravy-no:call_invite_sent:{$payload->callUuid}:{$device->device_uuid}";
            if (Cache::has($dedupKey)) {
                continue;
            }

            $res = $this->routeToDevice($device, $payload, $networkMode);
            $results[] = $res->toArray();

            if ($res->delivered) {
                $deliveredCount++;
                // Lock deduplication key for the lifetime of the call invite (60s)
                Cache::put($dedupKey, $res->provider, 60);
            }
        }

        // Only send browser Web Push if NOT in strict INTRANET / OFFLINE mode (since Web Push brokers are cloud endpoints)
        if ($callModel && in_array($networkMode, ['PUBLIC', 'HYBRID'], true)) {
            try {
                $this->webPushService->sendVoiceCallNotification($callModel);
            } catch (\Throwable) {
            }
        }

        return [
            'network_mode' => $networkMode,
            'total_devices' => $devices->count(),
            'delivered_count' => $deliveredCount,
            'results' => $results,
        ];
    }

    /**
     * Route a single device's call invitation with Local-First priority and Public fallback.
     */
    public function routeToDevice(MobileDevice $device, CallPushPayload $payload, ?string $networkMode = null): PushDeliveryResult
    {
        $mode = $networkMode ?? $this->networkModeManager->currentMode();

        if ($device->platform === MobileDevice::PLATFORM_IOS) {
            // 1. Try iOS Local Push Connectivity first if device is connected on LAN
            if ($this->iosLocalProvider->supports($device, $mode) && $this->iosLocalProvider->isHealthy($device)) {
                $localResult = $this->iosLocalProvider->sendCallInvite($device, $payload);
                if ($localResult->delivered && $localResult->acknowledged) {
                    return $localResult;
                }
            }

            // 2. Fallback to APNs PushKit ONLY if WAN is available (PUBLIC or HYBRID)
            if ($this->iosApnsProvider->supports($device, $mode)) {
                return $this->iosApnsProvider->sendCallInvite($device, $payload);
            }

            return new PushDeliveryResult(
                delivered: false,
                provider: 'ios_local',
                deviceUuid: $device->device_uuid,
                errorReason: $mode === 'INTRANET' ? 'ios_local_push_not_connected_in_intranet' : 'no_available_ios_push_route',
            );
        }

        if ($device->platform === MobileDevice::PLATFORM_ANDROID) {
            // 1. Try Android Local Persistent Connection first if device is connected on LAN
            if ($this->androidLocalProvider->supports($device, $mode) && $this->androidLocalProvider->isHealthy($device)) {
                $localResult = $this->androidLocalProvider->sendCallInvite($device, $payload);
                if ($localResult->delivered && $localResult->acknowledged) {
                    return $localResult;
                }
            }

            // 2. Fallback to FCM High-Priority Data Message ONLY if WAN is available (PUBLIC or HYBRID)
            if ($this->androidFcmProvider->supports($device, $mode)) {
                return $this->androidFcmProvider->sendCallInvite($device, $payload);
            }

            return new PushDeliveryResult(
                delivered: false,
                provider: 'android_local',
                deviceUuid: $device->device_uuid,
                errorReason: $mode === 'INTRANET' ? 'android_local_push_not_connected_in_intranet' : 'no_available_android_push_route',
            );
        }

        return new PushDeliveryResult(
            delivered: false,
            provider: 'unsupported',
            deviceUuid: $device->device_uuid,
            errorReason: 'unsupported_platform',
        );
    }

    /**
     * Notify all other ringing devices of a user to stop ringing (e.g., answered on another device, cancelled, or timed out).
     *
     * @return array<int, array<string, mixed>>
     */
    public function dispatchCallControlToUserDevices(
        int $userId,
        string $callUuid,
        string $eventType,
        ?string $excludeDeviceUuid = null,
        array $extra = []
    ): array {
        $networkMode = $this->networkModeManager->currentMode();
        $devices = $this->deviceRegistry->getActiveDevicesForUser($userId);
        $results = [];

        foreach ($devices as $device) {
            if ($excludeDeviceUuid !== null && $device->device_uuid === $excludeDeviceUuid) {
                continue;
            }

            if ($device->platform === MobileDevice::PLATFORM_IOS) {
                if ($this->iosLocalProvider->supports($device, $networkMode) && $this->iosLocalProvider->isHealthy($device)) {
                    $res = $this->iosLocalProvider->sendCallControl($device, $callUuid, $eventType, $extra);
                    if ($res->delivered) {
                        $results[] = $res->toArray();
                        continue;
                    }
                }
                if ($this->iosApnsProvider->supports($device, $networkMode)) {
                    $results[] = $this->iosApnsProvider->sendCallControl($device, $callUuid, $eventType, $extra)->toArray();
                }
            } elseif ($device->platform === MobileDevice::PLATFORM_ANDROID) {
                if ($this->androidLocalProvider->supports($device, $networkMode) && $this->androidLocalProvider->isHealthy($device)) {
                    $res = $this->androidLocalProvider->sendCallControl($device, $callUuid, $eventType, $extra);
                    if ($res->delivered) {
                        $results[] = $res->toArray();
                        continue;
                    }
                }
                if ($this->androidFcmProvider->supports($device, $networkMode)) {
                    $results[] = $this->androidFcmProvider->sendCallControl($device, $callUuid, $eventType, $extra)->toArray();
                }
            }
        }

        return $results;
    }
}
