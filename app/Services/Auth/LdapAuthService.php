<?php

namespace App\Services\Auth;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

class LdapAuthService
{
    /**
     * Get LDAP configuration values from SystemSetting.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'host' => (string) SystemSetting::get('ldap_host', '127.0.0.1'),
            'port' => (int) SystemSetting::get('ldap_port', 389),
            'encryption' => (string) SystemSetting::get('ldap_encryption', 'none'),
            'base_dn' => (string) SystemSetting::get('ldap_base_dn', 'dc=organization,dc=local'),
            'bind_dn' => (string) SystemSetting::get('ldap_bind_dn', ''),
            'bind_password' => (string) SystemSetting::get('ldap_bind_password', ''),
            'search_filter' => (string) SystemSetting::get('ldap_search_filter', '(&(objectClass=person)(|(uid={login})(mail={login})(sAMAccountName={login})))'),
            'uid_attribute' => (string) SystemSetting::get('ldap_uid_attribute', 'uid'),
            'email_attribute' => (string) SystemSetting::get('ldap_email_attribute', 'mail'),
            'name_attribute' => (string) SystemSetting::get('ldap_name_attribute', 'cn'),
            'auto_create_user' => (bool) (int) SystemSetting::get('ldap_auto_create_user', '1'),
        ];
    }

    /**
     * Test connection to the LDAP directory.
     *
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    public function testConnection(): array
    {
        if (!extension_loaded('ldap')) {
            return [
                'success' => false,
                'message' => 'اکستنشن PHP LDAP بر روی سرور نصب یا فعال نشده است.',
            ];
        }

        $config = $this->getConfig();

        if (empty($config['host'])) {
            return [
                'success' => false,
                'message' => 'آدرس سرور LDAP (Host) مشخص نشده است.',
            ];
        }

        try {
            $uri = $config['encryption'] === 'ssl'
                ? "ldaps://{$config['host']}:{$config['port']}"
                : "ldap://{$config['host']}:{$config['port']}";

            $connection = @ldap_connect($uri);
            if (!$connection) {
                return [
                    'success' => false,
                    'message' => "برقراری ارتباط با سرور LDAP در آدرس {$uri} با شکست مواجه شد.",
                ];
            }

            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 4);

            if ($config['encryption'] === 'tls') {
                if (!@ldap_start_tls($connection)) {
                    return [
                        'success' => false,
                        'message' => 'شروع اتصال امن StartTLS با سرور LDAP ناموفق بود: ' . ldap_error($connection),
                    ];
                }
            }

            // Test Bind
            $bindResult = false;
            if (!empty($config['bind_dn'])) {
                $bindResult = @ldap_bind($connection, $config['bind_dn'], $config['bind_password']);
            } else {
                $bindResult = @ldap_bind($connection);
            }

            if (!$bindResult) {
                return [
                    'success' => false,
                    'message' => 'احراز هویت کاربر سرویس (Bind DN) ناموفق بود: ' . ldap_error($connection),
                ];
            }

            return [
                'success' => true,
                'message' => 'اتصال و احراز هویت اولیه با سرور LDAP با موفقیت برقرار شد.',
                'details' => [
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'base_dn' => $config['base_dn'],
                    'protocol_version' => 3,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'خطای غیرمنتظره در ارتباط با LDAP: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Authenticate an incoming user against LDAP directory.
     *
     * @return array{uid: string, email: ?string, name: string, dn: string, attributes: array<string, mixed>}|false
     */
    public function authenticate(string $username, string $password): array|false
    {
        if (empty($username) || empty($password)) {
            return false;
        }

        if (!extension_loaded('ldap')) {
            Log::warning('LDAP extension missing while attempting LDAP authentication.');
            return false;
        }

        $config = $this->getConfig();
        $uri = $config['encryption'] === 'ssl'
            ? "ldaps://{$config['host']}:{$config['port']}"
            : "ldap://{$config['host']}:{$config['port']}";

        $connection = @ldap_connect($uri);
        if (!$connection) {
            return false;
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 4);

        if ($config['encryption'] === 'tls') {
            if (!@ldap_start_tls($connection)) {
                return false;
            }
        }

        // 1. Initial Bind (service account or anonymous)
        if (!empty($config['bind_dn'])) {
            if (!@ldap_bind($connection, $config['bind_dn'], $config['bind_password'])) {
                return false;
            }
        } else {
            if (!@ldap_bind($connection)) {
                return false;
            }
        }

        // 2. Sanitize and search for user DN
        $sanitizedLogin = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $filter = str_replace('{login}', $sanitizedLogin, $config['search_filter']);

        $search = @ldap_search(
            $connection,
            $config['base_dn'],
            $filter,
            [
                $config['uid_attribute'],
                $config['email_attribute'],
                $config['name_attribute'],
            ]
        );

        if (!$search) {
            return false;
        }

        $entries = @ldap_get_entries($connection, $search);
        if (!$entries || $entries['count'] === 0) {
            return false;
        }

        $userEntry = $entries[0];
        $userDn = $userEntry['dn'];

        // 3. User Password Verification Bind
        if (!@ldap_bind($connection, $userDn, $password)) {
            return false;
        }

        // 4. Extract Attributes
        $uid = $userEntry[strtolower($config['uid_attribute'])][0] ?? $username;
        $email = $userEntry[strtolower($config['email_attribute'])][0] ?? null;
        $name = $userEntry[strtolower($config['name_attribute'])][0] ?? $uid;

        return [
            'uid' => (string) $uid,
            'email' => $email ? (string) $email : null,
            'name' => (string) $name,
            'dn' => (string) $userDn,
            'attributes' => [
                'raw_uid' => $uid,
                'raw_mail' => $email,
            ],
        ];
    }
}

