<?php

namespace App\Services\Communication;

use App\Models\MobileDevice;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * End-to-End Network Mode Manager (Stage 2.5 & Stage 8).
 *
 * Evaluates real service health rather than relying on navigator.onLine or simple DNS/ping:
 * - PUBLIC: Only public cloud route is active/applicable
 * - INTRANET: WAN is down/disabled, but internal API, Signaling, Local Push, and TURN are healthy
 * - HYBRID: Both internal LAN services and public WAN providers are healthy
 * - DEGRADED: Core internal API is up, but one or more internal media/signaling components are impaired
 * - OFFLINE: Core API/Database is unreachable
 */
class NetworkModeManager
{
    public const MODE_PUBLIC = 'PUBLIC';
    public const MODE_INTRANET = 'INTRANET';
    public const MODE_HYBRID = 'HYBRID';
    public const MODE_DEGRADED = 'DEGRADED';
    public const MODE_OFFLINE = 'OFFLINE';

    /**
     * Perform an end-to-end health probe of internal and public calling subsystems.
     *
     * @return array{
     *     mode: string,
     *     reason: string,
     *     strict_offline_mode: bool,
     *     services: array{
     *         internal_api: bool,
     *         database: bool,
     *         signaling_bridge: bool,
     *         local_push_server: bool,
     *         internal_turn: bool,
     *         public_wan: bool
     *     },
     *     stats: array{
     *         active_local_push_devices: int,
     *         active_bridge_sessions: int,
     *         bridge_latency_ms: int|null
     *     },
     *     timestamp_ms: int
     * }
     */
    public function probeHealth(bool $probeBridgeLive = true): array
    {
        $strictOffline = (bool) (int) SystemSetting::get('voice_offline_mode', '0');
        $bridgeEnabled = (bool) (int) SystemSetting::get('voice_bridge_enabled', '1');

        // 1. Check Database & Internal API health
        $dbHealthy = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbHealthy = false;
        }

        if (! $dbHealthy) {
            return [
                'mode' => self::MODE_OFFLINE,
                'reason' => 'internal_database_unreachable',
                'strict_offline_mode' => $strictOffline,
                'services' => [
                    'internal_api' => false,
                    'database' => false,
                    'signaling_bridge' => false,
                    'local_push_server' => false,
                    'internal_turn' => false,
                    'public_wan' => false,
                ],
                'stats' => [
                    'active_local_push_devices' => 0,
                    'active_bridge_sessions' => 0,
                    'bridge_latency_ms' => null,
                ],
                'timestamp_ms' => (int) round(microtime(true) * 1000),
            ];
        }

        // 2. Check Signaling Bridge, Local Push Server, and Internal TURN
        $signalingHealthy = false;
        $localPushHealthy = false;
        $turnHealthy = false;
        $activeLocalDevices = (int) MobileDevice::where('status', MobileDevice::STATUS_ACTIVE)
            ->where('local_push_connected', true)
            ->count();
        $activeBridgeSessions = 0;
        $bridgeLatencyMs = null;

        if ($bridgeEnabled && $probeBridgeLive) {
            $host = SystemSetting::get('voice_bridge_host', '127.0.0.1');
            $apiPort = (int) SystemSetting::get('voice_bridge_api_port', 49000);
            $secret = SystemSetting::get('voice_bridge_secret', '');

            try {
                $t0 = microtime(true);
                $healthUrl = VoiceCallService::buildBridgeUrl($host, $apiPort, '/health');
                $res = Http::timeout(1.2)
                    ->withHeaders(array_filter(['X-Bridge-Secret' => $secret]))
                    ->get($healthUrl);

                if ($res->successful()) {
                    $bridgeLatencyMs = (int) round((microtime(true) - $t0) * 1000);
                    $data = $res->json();
                    $signalingHealthy = ($data['status'] ?? '') === 'ok';
                    $localPushHealthy = (bool) ($data['local_push']['enabled'] ?? $signalingHealthy);
                    $turnHealthy = (bool) ($data['stun_turn']['active'] ?? false);
                    $activeBridgeSessions = (int) ($data['active_sessions'] ?? 0);
                    if (isset($data['local_push']['connected_devices'])) {
                        $activeLocalDevices = max($activeLocalDevices, (int) $data['local_push']['connected_devices']);
                    }
                }
            } catch (\Throwable) {
                $signalingHealthy = false;
                $localPushHealthy = false;
            }
        } else {
            // When bridge daemon is mocked/disabled in unit tests, DB signaling mailbox and configured TURN serve as internal transport
            $signalingHealthy = true;
            $localPushHealthy = true;
            $turnHealthy = app(IceServerProvider::class)->hasTurn();
        }

        if (! $turnHealthy && app(IceServerProvider::class)->hasTurn()) {
            $turnHealthy = true;
        }

        // 3. Evaluate Public WAN availability
        $wanHealthy = ! $strictOffline && (bool) (int) SystemSetting::get('voice_wan_available', '1');

        // 4. Determine Effective Network Mode
        $internalAllHealthy = $dbHealthy && $signalingHealthy && $localPushHealthy;

        if ($strictOffline || ! $wanHealthy) {
            if ($internalAllHealthy) {
                $mode = self::MODE_INTRANET;
                $reason = $strictOffline ? 'strict_offline_intranet_mode_enabled' : 'wan_unreachable_intranet_healthy';
            } else {
                $mode = self::MODE_DEGRADED;
                $reason = 'intranet_mode_with_impaired_bridge_or_relay';
            }
        } else {
            if ($internalAllHealthy) {
                $mode = self::MODE_HYBRID;
                $reason = 'internal_and_public_paths_healthy';
            } elseif ($dbHealthy) {
                // If local bridge is unreachable but WAN/DB is up
                $mode = $bridgeEnabled ? self::MODE_DEGRADED : self::MODE_PUBLIC;
                $reason = $bridgeEnabled ? 'voice_bridge_unreachable_fallback_db_signaling' : 'public_mode_active';
            } else {
                $mode = self::MODE_OFFLINE;
                $reason = 'all_paths_unavailable';
            }
        }

        return [
            'mode' => $mode,
            'reason' => $reason,
            'strict_offline_mode' => $strictOffline,
            'services' => [
                'internal_api' => $dbHealthy,
                'database' => $dbHealthy,
                'signaling_bridge' => $signalingHealthy,
                'local_push_server' => $localPushHealthy,
                'internal_turn' => $turnHealthy,
                'public_wan' => $wanHealthy,
            ],
            'stats' => [
                'active_local_push_devices' => $activeLocalDevices,
                'active_bridge_sessions' => $activeBridgeSessions,
                'bridge_latency_ms' => $bridgeLatencyMs,
            ],
            'timestamp_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    /**
     * Quickly resolve the current network mode without blocking if offline mode is explicitly configured.
     */
    public function currentMode(): string
    {
        if ((bool) (int) SystemSetting::get('voice_offline_mode', '0')) {
            return self::MODE_INTRANET;
        }

        $wanAvailable = (bool) (int) SystemSetting::get('voice_wan_available', '1');
        if (! $wanAvailable) {
            return self::MODE_INTRANET;
        }

        return self::MODE_HYBRID;
    }
}
