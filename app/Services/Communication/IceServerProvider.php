<?php

namespace App\Services\Communication;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Builds the WebRTC ICE server list (STUN + TURN) handed to browsers.
 *
 * IMPORTANT: The Go Voice Bridge only relays *signaling* (offer/answer/ICE).
 * Audio (SRTP over UDP) flows peer-to-peer between browsers, or through a
 * TURN relay when a direct path is impossible (CGNAT / symmetric NAT /
 * restrictive firewalls - very common on mobile networks). Without a working
 * TURN server, calls ring and "connect" but no audio is ever exchanged.
 *
 * Supported TURN auth modes:
 *  - coturn REST API (`use-auth-secret` + `static-auth-secret`): set `voice_turn_secret`
 *    and short-lived credentials are generated per user.
 *  - static long-term credentials: set `voice_turn_username` + `voice_turn_credential`.
 */
class IceServerProvider
{
    public const DEFAULT_STUN_URLS = "stun:stun.l.google.com:19302\nstun:stun.cloudflare.com:3478";

    /** Lifetime of generated TURN REST credentials (seconds). */
    private const TURN_CREDENTIAL_TTL = 6 * 3600;

    /**
     * @return array<int, array{urls: array<int, string>, username?: string, credential?: string}>
     */
    public function iceServersFor(?User $user = null): array
    {
        $servers = [];

        $stunUrls = self::parseUrlList((string) SystemSetting::get('voice_stun_urls', self::DEFAULT_STUN_URLS));
        if ($stunUrls !== []) {
            $servers[] = ['urls' => $stunUrls];
        }

        $turnUrls = self::parseUrlList((string) SystemSetting::get('voice_turn_urls', ''));
        if ($turnUrls !== []) {
            $turnSecret = (string) SystemSetting::get('voice_turn_secret', '');

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
        if ($policy === 'relay' && self::parseUrlList((string) SystemSetting::get('voice_turn_urls', '')) === []) {
            return 'all';
        }

        return in_array($policy, ['all', 'relay'], true) ? $policy : 'all';
    }

    public function hasTurn(): bool
    {
        return self::parseUrlList((string) SystemSetting::get('voice_turn_urls', '')) !== [];
    }

    /**
     * Full RTCConfiguration-compatible payload for the browser.
     *
     * @return array{iceServers: array, iceTransportPolicy: string, hasTurn: bool}
     */
    public function rtcConfigFor(?User $user = null): array
    {
        return [
            'iceServers' => $this->iceServersFor($user),
            'iceTransportPolicy' => $this->transportPolicy(),
            'hasTurn' => $this->hasTurn(),
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

