<?php

namespace App\Services\Communication;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Builds the WebRTC ICE server list (STUN + TURN) handed to browsers and native WebViews.
 *
 * Supports:
 *  - Self-hosted embedded STUN & TURN relay (in Go Voice Bridge daemon)
 *  - Strict Offline / Intranet Mode (100% independent of external cloud providers / internet)
 *  - Domain & SSL/TLS (TURNS / WSS) deployment
 *  - Ephemeral REST HMAC credentials (draft-uberti-behave-turn-rest-00)
 *  - Static long-term TURN credentials fallback
 */
class IceServerProvider
{
    public const DEFAULT_STUN_URLS = 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302';

    /** Lifetime of generated TURN REST credentials (seconds). */
    private const TURN_CREDENTIAL_TTL = 6 * 3600;

    /**
     * Known public internet STUN/TURN domains that MUST be purged in Intranet / Offline Mode.
     *
     * @var array<int, string>
     */
    private const PUBLIC_CLOUD_ICE_DOMAINS = [
        'google.com',
        'cloudflare.com',
        'sipgate.net',
        'blackberry.com',
        'wire.com',
        'schlund.de',
        'metered.ca',
        'openrelay.metered.ca',
        'ekiga.net',
        'viagenie.ca',
        '3cx.com',
        'xirsys.com',
        'twilio.com',
        'stunprotocol.org',
        'nextcloud.com',
    ];

    /**
     * Determine if offline / intranet isolated mode is active.
     * Rule: If no external public STUN/TURN URLs are configured (or only self-hosted Go daemon is configured),
     * the system automatically operates in 100% isolated Intranet mode.
     */
    public function isOfflineMode(): bool
    {
        // 1. Explicit setting override
        if ((bool) (int) SystemSetting::get('voice_offline_mode', '0')) {
            return true;
        }

        // 2. Automatic air-gapped detection:
        // Check if any public cloud STUN or TURN servers are configured.
        $rawStun = (string) SystemSetting::get('voice_stun_urls', '');
        $rawTurn = (string) SystemSetting::get('voice_turn_urls', '');

        $configuredStun = self::parseUrlList($rawStun);
        $configuredTurn = self::parseUrlList($rawTurn);

        $allUrls = array_merge($configuredStun, $configuredTurn);
        if (empty($allUrls)) {
            return true;
        }

        foreach ($allUrls as $url) {
            if (self::isPublicCloudIceUrl($url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an ICE URL points to an external public cloud provider.
     */
    public static function isPublicCloudIceUrl(string $url): bool
    {
        $lower = strtolower($url);
        foreach (self::PUBLIC_CLOUD_ICE_DOMAINS as $domain) {
            if (str_contains($lower, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve effective STUN server URLs.
     *
     * @return array<int, string>
     */
    public function resolveStunUrls(): array
    {
        $mode = SystemSetting::get('voice_bridge_connection_mode', 'domain');
        $domainOrIp = '';
        if ($mode === 'domain') {
            $domainOrIp = trim((string) SystemSetting::get('voice_bridge_domain', ''));
        }
        if ($domainOrIp === '' || $domainOrIp === '0.0.0.0') {
            $domainOrIp = trim((string) SystemSetting::get('voice_turn_domain_or_ip', ''));
        }
        if ($domainOrIp === '' || $domainOrIp === '0.0.0.0') {
            $domainOrIp = trim((string) SystemSetting::get('voice_bridge_host', ''));
        }
        $stunPort = (int) SystemSetting::get('voice_stun_port', 49002);
        if ($stunPort <= 0) {
            $stunPort = (int) SystemSetting::get('voice_turn_port', 49002);
        }

        $selfStun = [];
        if ($domainOrIp !== '' && $domainOrIp !== '0.0.0.0') {
            $selfStun[] = "stun:{$domainOrIp}:{$stunPort}";
        }

        $rawConfiguredStun = trim((string) SystemSetting::get('voice_stun_urls', ''));
        $configuredStun = self::parseUrlList($rawConfiguredStun);

        if ($this->isOfflineMode()) {
            // In offline/intranet mode, strictly purge ALL external public internet STUN hosts
            $localStun = array_filter($configuredStun, function (string $url) {
                return ! self::isPublicCloudIceUrl($url);
            });

            $merged = array_merge($selfStun, $localStun);
            return array_values(array_unique($merged));
        }

        // Online mode:
        // If custom STUN URLs or a self-hosted domain/IP are explicitly configured in settings,
        // strictly use the user's dedicated infrastructure without injecting public fallbacks.
        if (! empty($selfStun) || ! empty($configuredStun)) {
            $merged = array_merge($selfStun, $configuredStun);
            return array_values(array_unique($merged));
        }

        // Fall back to public STUN only when no custom infrastructure is configured in settings
        return self::parseUrlList(self::DEFAULT_STUN_URLS);
    }

    /**
     * Resolve effective TURN server URLs.
     *
     * @return array<int, string>
     */
    public function resolveTurnUrls(): array
    {
        $explicitUrls = self::parseUrlList((string) SystemSetting::get('voice_turn_urls', ''));
        if ($this->isOfflineMode() && $explicitUrls !== []) {
            $explicitUrls = array_values(array_filter($explicitUrls, fn (string $u) => ! self::isPublicCloudIceUrl($u)));
        }

        if ($explicitUrls !== []) {
            return $explicitUrls;
        }

        $mode = SystemSetting::get('voice_bridge_connection_mode', 'domain');
        $domainOrIp = '';
        if ($mode === 'domain') {
            $domainOrIp = trim((string) SystemSetting::get('voice_bridge_domain', ''));
        }
        if ($domainOrIp === '' || $domainOrIp === '0.0.0.0') {
            $domainOrIp = trim((string) SystemSetting::get('voice_turn_domain_or_ip', ''));
        }
        if ($domainOrIp === '' || $domainOrIp === '0.0.0.0') {
            $domainOrIp = trim((string) SystemSetting::get('voice_bridge_host', ''));
        }
        if ($domainOrIp === '' || $domainOrIp === '0.0.0.0') {
            return [];
        }

        $port = (int) SystemSetting::get('voice_turn_port', 49003);
        if ($port <= 0) {
            $port = 49003;
        }

        $turnsPort = (int) SystemSetting::get('voice_turns_port', 49004);
        if ($turnsPort <= 0) {
            $turnsPort = 49004;
        }

        $hasTurns = (bool) (int) SystemSetting::get('voice_turns_enabled', '0');

        $urls = [
            "turn:{$domainOrIp}:{$port}?transport=udp",
            "turn:{$domainOrIp}:{$port}?transport=tcp",
        ];

        if ($hasTurns) {
            $urls[] = "turns:{$domainOrIp}:{$turnsPort}?transport=tcp";
        }

        return $urls;
    }

    /**
     * @return array<int, array{urls: array<int, string>, username?: string, credential?: string}>
     */
    public function iceServersFor(?User $user = null): array
    {
        $servers = [];

        $stunUrls = $this->resolveStunUrls();
        if ($stunUrls !== []) {
            $servers[] = ['urls' => $stunUrls];
        }

        $turnUrls = $this->resolveTurnUrls();
        if ($turnUrls !== []) {
            // Turn secret defaults to bridge secret if not explicitly configured
            $turnSecret = trim((string) SystemSetting::get('voice_turn_secret', ''));
            if ($turnSecret === '') {
                $turnSecret = trim((string) SystemSetting::get('voice_bridge_secret', ''));
            }

            if ($turnSecret !== '') {
                $expiresAt = time() + self::TURN_CREDENTIAL_TTL;
                $username = $expiresAt . ':' . ($user?->id ?? 'guest');
                $credential = base64_encode(hash_hmac('sha1', $username, $turnSecret, true));
            } else {
                $username = (string) SystemSetting::get('voice_turn_username', '');
                $credential = (string) SystemSetting::get('voice_turn_credential', '');
            }

            $servers[] = array_filter([
                'urls' => $turnUrls,
                'username' => $username,
                'credential' => $credential,
            ], fn ($v) => $v !== '' && $v !== []);
        }

        return $servers;
    }

    public function transportPolicy(): string
    {
        $policy = (string) SystemSetting::get('voice_ice_transport_policy', 'all');

        return in_array($policy, ['all', 'relay'], true) ? $policy : 'all';
    }

    public function hasTurn(): bool
    {
        return $this->resolveTurnUrls() !== [];
    }

    /**
     * Full RTCConfiguration-compatible payload for the browser/WebView.
     *
     * @return array{iceServers: array, iceTransportPolicy: string, hasTurn: bool, isOfflineMode: bool, networkMode: string}
     */
    public function rtcConfigFor(?User $user = null): array
    {
        $offline = $this->isOfflineMode();
        return [
            'iceServers' => $this->iceServersFor($user),
            'iceTransportPolicy' => $this->transportPolicy(),
            'hasTurn' => $this->hasTurn(),
            'isOfflineMode' => $offline,
            'networkMode' => $offline ? NetworkModeManager::MODE_INTRANET : NetworkModeManager::MODE_HYBRID,
        ];
    }

    /**
     * Split a comma / newline separated list and keep only valid stun:/turn:/turns: URLs.
     *
     * @return array<int, string>
     */
    public static function parseUrlList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        $urls = array_filter(array_map('trim', $parts), function (string $url) {
            return $url !== '' && preg_match('/^(stun|stuns|turn|turns):[^\s]+$/i', $url) === 1;
        });

        return array_values(array_unique($urls));
    }
}
