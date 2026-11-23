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
    public const DEFAULT_STUN_URLS = '';

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
     * Determine if offline / intranet mode is active.
     */
    public function isOfflineMode(): bool
    {
        return (bool) (int) SystemSetting::get('voice_offline_mode', '0');
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
        $domainOrIp = trim((string) SystemSetting::get('voice_turn_domain_or_ip', ''));
        $turnPort = (int) SystemSetting::get('voice_turn_port', 3478);
        if ($turnPort <= 0) {
            $turnPort = 3478;
        }

        $selfStun = [];
        if ($domainOrIp !== '') {
            $selfStun[] = "stun:{$domainOrIp}:{$turnPort}";
        }

        if ($this->isOfflineMode()) {
            // In offline/intranet mode, strictly purge ALL external public internet STUN hosts
            $configuredStun = self::parseUrlList((string) SystemSetting::get('voice_stun_urls', ''));
            $localStun = array_filter($configuredStun, function (string $url) {
                return ! self::isPublicCloudIceUrl($url);
            });

            $merged = array_merge($selfStun, $localStun);
            return array_values(array_unique($merged));
        }

        // Online mode: self-hosted STUN first, then configured/default public STUNs
        $configuredStun = self::parseUrlList((string) SystemSetting::get('voice_stun_urls', self::DEFAULT_STUN_URLS));
        $merged = array_merge($selfStun, $configuredStun);

        return array_values(array_unique($merged));
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

        $domainOrIp = trim((string) SystemSetting::get('voice_turn_domain_or_ip', ''));
        if ($domainOrIp === '') {
            return [];
        }

        $port = (int) SystemSetting::get('voice_turn_port', 3478);
        if ($port <= 0) {
            $port = 3478;
        }

        $turnsPort = (int) SystemSetting::get('voice_turns_port', 5349);
        if ($turnsPort <= 0) {
            $turnsPort = 5349;
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

        // Forcing relay without any TURN server would make every call silent.
        if ($policy === 'relay' && ! $this->hasTurn()) {
            return 'all';
        }

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
