<?php

namespace App\Services\Auth;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeycloakAuthService
{
    /**
     * Get Keycloak configuration from SystemSetting.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $baseUrl = rtrim((string) SystemSetting::get('keycloak_base_url', 'https://keycloak.example.com'), '/');
        $realm = (string) SystemSetting::get('keycloak_realm', 'master');

        return [
            'base_url' => $baseUrl,
            'realm' => $realm,
            'client_id' => (string) SystemSetting::get('keycloak_client_id', 'getnumber-client'),
            'client_secret' => (string) SystemSetting::get('keycloak_client_secret', ''),
            'auto_create_user' => (bool) (int) SystemSetting::get('keycloak_auto_create_user', '1'),
            'redirect_uri' => route('auth.keycloak.callback'),
            'discovery_url' => "{$baseUrl}/realms/{$realm}/.well-known/openid-configuration",
            'auth_url' => "{$baseUrl}/realms/{$realm}/protocol/openid-connect/auth",
            'token_url' => "{$baseUrl}/realms/{$realm}/protocol/openid-connect/token",
            'userinfo_url' => "{$baseUrl}/realms/{$realm}/protocol/openid-connect/userinfo",
        ];
    }

    /**
     * Test connection to Keycloak server OpenID Discovery endpoint.
     *
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $config = $this->getConfig();

        if (empty($config['base_url'])) {
            return [
                'success' => false,
                'message' => 'آدرس سرور Keycloak مشخص نشده است.',
            ];
        }

        try {
            $response = Http::timeout(5)->get($config['discovery_url']);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => "ارتباط با سرور Keycloak در قلمرو (Realm) '{$config['realm']}' با موفقیت تایید شد.",
                    'details' => [
                        'issuer' => $data['issuer'] ?? $config['base_url'],
                        'authorization_endpoint' => $data['authorization_endpoint'] ?? null,
                        'token_endpoint' => $data['token_endpoint'] ?? null,
                        'userinfo_endpoint' => $data['userinfo_endpoint'] ?? null,
                    ],
                ];
            }

            return [
                'success' => false,
                'message' => "پاسخ ناموفق از سرور Keycloak (کد خطا: {$response->status()}). لطفاً قلمرو و آدرس سرور را بررسی فرمایید.",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'عدم دسترسی به سرور Keycloak: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build the OAuth2 / OpenID Connect authorization redirect URL.
     */
    public function getAuthorizationUrl(string $action = 'login'): string
    {
        $config = $this->getConfig();

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => base64_encode(json_encode([
                'action' => $action, // 'login' or 'link'
                'token' => csrf_token(),
            ])),
        ];

        return $config['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens and fetch user profile.
     *
     * @return array{sub: string, username: string, email: ?string, name: string, raw: array<string, mixed>}|false
     */
    public function handleCallback(string $code): array|false
    {
        $config = $this->getConfig();

        try {
            $tokenResponse = Http::asForm()->timeout(10)->post($config['token_url'], [
                'grant_type' => 'authorization_code',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect_uri'],
                'code' => $code,
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('Keycloak token exchange failed', ['body' => $tokenResponse->body()]);
                return false;
            }

            $tokens = $tokenResponse->json();
            $accessToken = $tokens['access_token'] ?? null;

            if (!$accessToken) {
                return false;
            }

            // Fetch user info with access token
            $userinfoResponse = Http::withToken($accessToken)
                ->timeout(10)
                ->get($config['userinfo_url']);

            if (!$userinfoResponse->successful()) {
                Log::error('Keycloak userinfo fetch failed', ['body' => $userinfoResponse->body()]);
                return false;
            }

            $userinfo = $userinfoResponse->json();
            $sub = (string) ($userinfo['sub'] ?? '');
            $username = (string) ($userinfo['preferred_username'] ?? $userinfo['name'] ?? $sub);
            $email = isset($userinfo['email']) ? (string) $userinfo['email'] : null;
            $name = (string) ($userinfo['name'] ?? $username);

            return [
                'sub' => $sub,
                'username' => $username,
                'email' => $email,
                'name' => $name,
                'raw' => $userinfo,
            ];
        } catch (\Throwable $e) {
            Log::error('Keycloak callback exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

