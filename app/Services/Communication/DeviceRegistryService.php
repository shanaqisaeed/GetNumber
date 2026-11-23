<?php

namespace App\Services\Communication;

use App\Contracts\ApiTokenServiceInterface;
use App\Models\MobileDevice;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeviceRegistryService
{
    public function __construct(
        protected ApiTokenServiceInterface $apiTokenService
    ) {}

    /**
     * Register or update a mobile/web device installation and its push tokens.
     *
     * @param User $user
     * @param array<string, mixed> $data
     * @param string|null $ipAddress
     * @return array{
     *     device: MobileDevice,
     *     device_auth_token: string|null,
     *     local_push_ws_url: string,
     *     local_push_ticket: string,
     *     local_push_expires_at: int,
     *     allowed_intranet_ssids: array<int, string>
     * }
     */
    public function registerOrUpdateDevice(User $user, array $data, ?string $ipAddress = null): array
    {
        $deviceUuid = trim((string) $data['device_uuid']);
        $platform = strtolower(trim((string) ($data['platform'] ?? MobileDevice::PLATFORM_ANDROID)));
        if (! in_array($platform, [MobileDevice::PLATFORM_IOS, MobileDevice::PLATFORM_ANDROID, MobileDevice::PLATFORM_WEB], true)) {
            $platform = MobileDevice::PLATFORM_ANDROID;
        }

        // Enforce single user ownership per device_uuid: if device switched accounts, revoke old binding
        MobileDevice::where('device_uuid', $deviceUuid)
            ->where('user_id', '!=', $user->id)
            ->delete();

        // Also ensure no other device holds the exact same VoIP or Notification token
        if (! empty($data['voip_push_token'])) {
            $vHash = hash('sha256', trim((string) $data['voip_push_token']));
            MobileDevice::where('voip_push_token_hash', $vHash)
                ->where('device_uuid', '!=', $deviceUuid)
                ->update([
                    'voip_push_token_encrypted' => null,
                    'voip_push_token_hash' => null,
                ]);
        }

        if (! empty($data['notification_push_token'])) {
            $nHash = hash('sha256', trim((string) $data['notification_push_token']));
            MobileDevice::where('notification_push_token_hash', $nHash)
                ->where('device_uuid', '!=', $deviceUuid)
                ->update([
                    'notification_push_token_encrypted' => null,
                    'notification_push_token_hash' => null,
                ]);
        }

        $device = MobileDevice::firstOrNew([
            'device_uuid' => $deviceUuid,
        ]);

        $device->user_id = $user->id;
        $device->platform = $platform;
        $device->app_version = trim((string) ($data['app_version'] ?? $device->app_version ?: '1.0.0'));
        $device->os_version = isset($data['os_version']) ? trim((string) $data['os_version']) : $device->os_version;
        $device->device_model = isset($data['device_model']) ? trim((string) $data['device_model']) : $device->device_model;

        if (array_key_exists('voip_push_token', $data)) {
            $device->setVoipPushToken($data['voip_push_token']);
        }
        if (array_key_exists('notification_push_token', $data)) {
            $device->setNotificationPushToken($data['notification_push_token']);
        }

        if (isset($data['local_push_connected'])) {
            $device->local_push_connected = (bool) $data['local_push_connected'];
        }
        if (isset($data['network_mode'])) {
            $device->network_mode = strtoupper(trim((string) $data['network_mode']));
        }
        if (isset($data['ssid'])) {
            $device->last_known_ssid = trim((string) $data['ssid']);
        }
        if ($ipAddress) {
            $device->last_known_ip = $ipAddress;
        }

        // Determine preferred push provider
        if (! empty($data['push_provider'])) {
            $device->push_provider = trim((string) $data['push_provider']);
        } else {
            $device->push_provider = match ($platform) {
                MobileDevice::PLATFORM_IOS => $device->local_push_connected
                    ? 'ios_local'
                    : (! empty($device->voip_push_token_hash) ? 'ios_apns_voip' : 'ios_local'),
                MobileDevice::PLATFORM_ANDROID => $device->local_push_connected
                    ? 'android_local'
                    : (! empty($device->notification_push_token_hash) ? 'android_fcm' : 'android_local'),
                default => 'web_push',
            };
        }

        $device->status = MobileDevice::STATUS_ACTIVE;
        $device->revoked_at = null;
        $device->last_seen_at = now();

        // Issue or rotate a scoped device bearer token for lock-screen native actions
        $plainAuthToken = null;
        $issueNewToken = (bool) ($data['issue_device_token'] ?? true);
        if ($issueNewToken || ! $device->api_token_id) {
            if ($device->apiToken) {
                $rotated = $this->apiTokenService->rotateToken($device->apiToken);
                $plainAuthToken = $rotated['plain_text_token'];
            } else {
                $created = $this->apiTokenService->createToken(
                    user: $user,
                    name: "MobileDevice:{$platform}:{$deviceUuid}",
                    abilities: ['mobile:calling'],
                    expiresAt: now()->addDays(90)
                );
                $device->api_token_id = $created['token']->id;
                $plainAuthToken = $created['plain_text_token'];
            }
        }

        $device->save();

        $ticketData = $this->generateLocalPushTicket($device->device_uuid, $user->id);

        return [
            'device' => $device,
            'device_auth_token' => $plainAuthToken,
            'local_push_ws_url' => $this->resolveLocalPushWsUrl(),
            'local_push_ticket' => $ticketData['ticket'],
            'local_push_expires_at' => $ticketData['expires_at'],
            'allowed_intranet_ssids' => $this->resolveAllowedIntranetSsids(),
        ];
    }

    /**
     * Revoke a device installation and invalidate all its tokens.
     */
    public function revokeDevice(User $user, string $deviceUuid): bool
    {
        $device = MobileDevice::where('user_id', $user->id)
            ->where('device_uuid', trim($deviceUuid))
            ->first();

        if (! $device) {
            return false;
        }

        if ($device->apiToken) {
            $this->apiTokenService->revokeToken($device->apiToken);
        }

        $device->update([
            'status' => MobileDevice::STATUS_REVOKED,
            'voip_push_token_encrypted' => null,
            'voip_push_token_hash' => null,
            'notification_push_token_encrypted' => null,
            'notification_push_token_hash' => null,
            'local_push_connected' => false,
            'local_push_connection_id' => null,
            'revoked_at' => now(),
        ]);

        // Notify Go Local Push Server to disconnect any active socket for this device
        $this->notifyBridgeDeviceRevoked($device->device_uuid);

        return true;
    }

    /**
     * Automatically invalidate an expired or unregistered APNs/FCM token without exposing it in logs.
     */
    public function invalidatePushToken(string $tokenType, string $rawTokenOrHash, string $reason = 'unregistered'): int
    {
        $clean = trim($rawTokenOrHash);
        if ($clean === '') {
            return 0;
        }

        $hash = (strlen($clean) === 64 && ctype_xdigit($clean)) ? strtolower($clean) : hash('sha256', $clean);
        $shortHash = substr($hash, 0, 10);

        if ($tokenType === 'apns_voip') {
            $count = MobileDevice::where('voip_push_token_hash', $hash)->update([
                'voip_push_token_encrypted' => null,
                'voip_push_token_hash' => null,
            ]);
        } else {
            $count = MobileDevice::where('notification_push_token_hash', $hash)->update([
                'notification_push_token_encrypted' => null,
                'notification_push_token_hash' => null,
            ]);
        }

        if ($count > 0) {
            Log::info("[DeviceRegistry] Invalidated {$tokenType} token (fingerprint: sha256:{$shortHash}..., reason: {$reason})");
        }

        return $count;
    }

    /**
     * Record a heartbeat from a mobile device.
     */
    public function recordHeartbeat(User $user, string $deviceUuid, array $telemetry, ?string $ipAddress = null): ?MobileDevice
    {
        $device = MobileDevice::where('user_id', $user->id)
            ->where('device_uuid', trim($deviceUuid))
            ->where('status', MobileDevice::STATUS_ACTIVE)
            ->first();

        if (! $device) {
            return null;
        }

        $updates = [
            'last_seen_at' => now(),
        ];

        if ($ipAddress) {
            $updates['last_known_ip'] = $ipAddress;
        }
        if (isset($telemetry['network_mode'])) {
            $updates['network_mode'] = strtoupper(trim((string) $telemetry['network_mode']));
        }
        if (isset($telemetry['local_push_connected'])) {
            $updates['local_push_connected'] = (bool) $telemetry['local_push_connected'];
        }
        if (isset($telemetry['ssid'])) {
            $updates['last_known_ssid'] = trim((string) $telemetry['ssid']);
        }

        $device->update($updates);

        return $device->fresh();
    }

    /**
     * Update local push connection state when Go Voice Bridge notifies connect/disconnect.
     */
    public function updateLocalConnectionState(string $deviceUuid, bool $connected, ?string $connectionId = null, ?string $ip = null): void
    {
        $updates = [
            'local_push_connected' => $connected,
            'local_push_connection_id' => $connected ? $connectionId : null,
            'last_seen_at' => now(),
        ];
        if ($ip) {
            $updates['last_known_ip'] = $ip;
        }

        MobileDevice::where('device_uuid', trim($deviceUuid))
            ->where('status', MobileDevice::STATUS_ACTIVE)
            ->update($updates);
    }

    /**
     * Get all active devices for a user.
     *
     * @return Collection<int, MobileDevice>
     */
    public function getActiveDevicesForUser(int $userId): Collection
    {
        return MobileDevice::where('user_id', $userId)
            ->active()
            ->orderByDesc('local_push_connected')
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Generate a short-lived HMAC-SHA256 ticket for authenticating with the Go Local Push Server.
     * Format: base64url("device_uuid|user_id|expires_at|hmac_hex")
     *
     * @return array{ticket: string, expires_at: int}
     */
    public function generateLocalPushTicket(string $deviceUuid, int $userId, int $ttlSeconds = 86400): array
    {
        $expiresAt = time() + $ttlSeconds;
        $secret = $this->resolveBridgeSecret();
        $payload = "{$deviceUuid}|{$userId}|{$expiresAt}";
        $signature = hash_hmac('sha256', $payload, $secret);
        $raw = "{$payload}|{$signature}";
        $ticket = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return [
            'ticket' => $ticket,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify a local push ticket.
     *
     * @return array{device_uuid: string, user_id: int, expires_at: int}|null
     */
    public function verifyLocalPushTicket(string $ticket): ?array
    {
        $padded = strtr($ticket, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($padded, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }

        [$deviceUuid, $userIdStr, $expiresAtStr, $providedSig] = $parts;
        $expiresAt = (int) $expiresAtStr;
        if ($expiresAt < time()) {
            return null;
        }

        $secret = $this->resolveBridgeSecret();
        $expectedSig = hash_hmac('sha256', "{$deviceUuid}|{$userIdStr}|{$expiresAtStr}", $secret);
        if (! hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        return [
            'device_uuid' => $deviceUuid,
            'user_id' => (int) $userIdStr,
            'expires_at' => $expiresAt,
        ];
    }

    public function resolveLocalPushWsUrl(): string
    {
        $explicitUrl = trim((string) SystemSetting::get('voice_local_push_ws_url', ''));
        if ($explicitUrl !== '') {
            return $explicitUrl;
        }

        $publicWs = trim((string) SystemSetting::get('voice_bridge_ws_public_url', ''));
        if ($publicWs !== '') {
            return preg_replace('#/ws/signaling$#', '/ws/local-push', $publicWs) ?: $publicWs;
        }

        $host = trim((string) SystemSetting::get('voice_bridge_host', '127.0.0.1'));
        $wsPort = (int) SystemSetting::get('voice_bridge_ws_port', 49001);

        return "ws://{$host}:{$wsPort}/ws/local-push";
    }

    /**
     * @return array<int, string>
     */
    public function resolveAllowedIntranetSsids(): array
    {
        $raw = trim((string) SystemSetting::get('voice_intranet_ssids', ''));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    protected function resolveBridgeSecret(): string
    {
        $secret = trim((string) SystemSetting::get('voice_bridge_secret', ''));
        if ($secret === '') {
            $secret = (string) config('app.key', 'getnumber_default_secret');
        }

        return $secret;
    }

    protected function notifyBridgeDeviceRevoked(string $deviceUuid): void
    {
        if (! (bool) SystemSetting::get('voice_bridge_enabled', false)) {
            return;
        }

        try {
            $host = SystemSetting::get('voice_bridge_host', '127.0.0.1');
            $apiPort = (int) SystemSetting::get('voice_bridge_api_port', 49000);
            $secret = SystemSetting::get('voice_bridge_secret', '');
            $url = VoiceCallService::buildBridgeUrl($host, $apiPort, '/api/local-push/disconnect');

            Http::timeout(1.0)
                ->withHeaders(array_filter(['X-Bridge-Secret' => $secret]))
                ->post($url, ['device_uuid' => $deviceUuid]);
        } catch (\Throwable) {
            // Non-blocking
        }
    }
}
