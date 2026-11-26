<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\Auth\KeycloakAuthService;
use App\Services\Auth\LdapAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    /**
     * Display the Unified System Settings view with all tabs.
     */
    public function general(
        Request $request,
        LdapAuthService $ldapService,
        KeycloakAuthService $keycloakService
    ): Response {
        $tab = $request->query('tab', 'general');
        if (!in_array($tab, ['general', 'pricing', 'auth', 'ldap', 'keycloak', 'smtp', 'bridge'])) {
            $tab = 'general';
        }

        return Inertia::render('Admin/Settings/General', [
            'initialTab' => $tab,
            'generalSettings' => [
                'app_name' => SystemSetting::get('app_name', __('controllers.admin.settings.default_app_name')),
                'app_description' => SystemSetting::get('app_description', __('controllers.admin.settings.default_app_description')),
                'default_locale' => SystemSetting::getDefaultLocale(),
                'max_lines_per_user' => (int) SystemSetting::get('max_lines_per_user', '10'),
                'event_notify_login_failed' => (bool) (int) SystemSetting::get('event_notify_login_failed', '1'),
                'event_notify_user_registered' => (bool) (int) SystemSetting::get('event_notify_user_registered', '1'),
                'event_notify_security_alert' => (bool) (int) SystemSetting::get('event_notify_security_alert', '1'),
            ],
            'orgPricing' => [
                'org_price_4_regular' => SystemSetting::getOrgNumberPrice(4, false),
                'org_price_4_rond' => SystemSetting::getOrgNumberPrice(4, true),
                'org_price_5_regular' => SystemSetting::getOrgNumberPrice(5, false),
                'org_price_5_rond' => SystemSetting::getOrgNumberPrice(5, true),
                'org_price_6_regular' => SystemSetting::getOrgNumberPrice(6, false),
                'org_price_6_rond' => SystemSetting::getOrgNumberPrice(6, true),
                'org_price_7_regular' => SystemSetting::getOrgNumberPrice(7, false),
                'org_price_7_rond' => SystemSetting::getOrgNumberPrice(7, true),
                'org_price_8_regular' => SystemSetting::getOrgNumberPrice(8, false),
                'org_price_8_rond' => SystemSetting::getOrgNumberPrice(8, true),
            ],
            'authSettings' => [
                'auth_username_enabled' => (bool) (int) SystemSetting::get('auth_username_enabled', '1'),
                'auth_email_enabled' => (bool) (int) SystemSetting::get('auth_email_enabled', '1'),
                'auth_ldap_enabled' => (bool) (int) SystemSetting::get('auth_ldap_enabled', '0'),
                'auth_keycloak_enabled' => (bool) (int) SystemSetting::get('auth_keycloak_enabled', '0'),
                'allow_user_registration' => (bool) (int) SystemSetting::get('allow_user_registration', '1'),
            ],
            'ldapSettings' => [
                'config' => $ldapService->getConfig(),
                'extensionLoaded' => extension_loaded('ldap'),
                'isEnabled' => (bool) (int) SystemSetting::get('auth_ldap_enabled', '0'),
            ],
            'keycloakSettings' => [
                'config' => $keycloakService->getConfig(),
                'callbackUrl' => route('auth.keycloak.callback'),
                'isEnabled' => (bool) (int) SystemSetting::get('auth_keycloak_enabled', '0'),
            ],
            'smtpSettings' => [
                'mail_mailer' => SystemSetting::get('mail_mailer', 'smtp'),
                'mail_host' => SystemSetting::get('mail_host', '127.0.0.1'),
                'mail_port' => (int) SystemSetting::get('mail_port', 587),
                'mail_username' => SystemSetting::get('mail_username', ''),
                'mail_password' => SystemSetting::get('mail_password', ''),
                'mail_encryption' => SystemSetting::get('mail_encryption', 'tls'),
                'mail_from_address' => SystemSetting::get('mail_from_address', 'noreply@ravy-no.local'),
                'mail_from_name' => SystemSetting::get('mail_from_name', 'RavyNo'),
                'require_email_verification' => (bool) (int) SystemSetting::get('require_email_verification', '1'),
            ],
            'voiceBridgeSettings' => [
                'host' => SystemSetting::get('voice_bridge_host', '127.0.0.1'),
                'api_port' => (int) SystemSetting::get('voice_bridge_api_port', 49000),
                'ws_port' => (int) SystemSetting::get('voice_bridge_ws_port', 49001),
                'ws_public_url' => SystemSetting::get('voice_bridge_ws_public_url', ''),
                'secret' => SystemSetting::get('voice_bridge_secret', ''),
                'enabled' => (bool) (int) SystemSetting::get('voice_bridge_enabled', '1'),
                'turn_domain_or_ip' => SystemSetting::get('voice_turn_domain_or_ip', ''),
                'turn_port' => (int) SystemSetting::get('voice_turn_port', 3479),
                'turns_port' => (int) SystemSetting::get('voice_turns_port', 5350),
                'turns_enabled' => (bool) (int) SystemSetting::get('voice_turns_enabled', '0'),
                'turn_realm' => SystemSetting::get('voice_turn_realm', 'ravy-no'),
                'offline_mode' => (bool) (int) SystemSetting::get('voice_offline_mode', '0'),
                'stun_urls' => SystemSetting::get('voice_stun_urls', \App\Services\Communication\IceServerProvider::DEFAULT_STUN_URLS),
                'turn_urls' => SystemSetting::get('voice_turn_urls', ''),
                'turn_username' => SystemSetting::get('voice_turn_username', ''),
                'turn_credential' => SystemSetting::get('voice_turn_credential', ''),
                'turn_secret' => SystemSetting::get('voice_turn_secret', ''),
                'ice_transport_policy' => SystemSetting::get('voice_ice_transport_policy', 'all'),
                'intranet_ssids' => SystemSetting::get('voice_intranet_ssids', ''),
                'local_push_ws_url' => SystemSetting::get('voice_local_push_ws_url', ''),
                'ring_timeout_seconds' => (int) SystemSetting::get('voice_ring_timeout_seconds', 45),
                'sfu_enabled' => (bool) (int) SystemSetting::get('voice_sfu_enabled', '1'),
                'sfu_ws_path' => SystemSetting::get('voice_sfu_ws_path', '/ws/sfu'),
                'sfu_ws_public_url' => SystemSetting::get('voice_sfu_ws_public_url', ''),
                'sfu_min_port' => (int) SystemSetting::get('voice_sfu_min_port', 49152),
                'sfu_max_port' => (int) SystemSetting::get('voice_sfu_max_port', 49250),
            ],
        ]);
    }

    /**
     * Update General Settings & Line Quota & Events.
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:100'],
            'app_description' => ['nullable', 'string', 'max:255'],
            'default_locale' => ['nullable', 'string', 'in:fa,en'],
            'max_lines_per_user' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'event_notify_login_failed' => ['boolean'],
            'event_notify_user_registered' => ['boolean'],
            'event_notify_security_alert' => ['boolean'],
        ], [
            'app_name.required' => __('controllers.admin.settings.val_app_name_required'),
            'default_locale.in' => __('controllers.admin.settings.val_default_locale_in'),
            'max_lines_per_user.min' => __('controllers.admin.settings.val_max_lines_min'),
            'max_lines_per_user.max' => __('controllers.admin.settings.val_max_lines_max'),
        ]);

        $maxLines = $validated['max_lines_per_user'] ?? (int) SystemSetting::get('max_lines_per_user', '10');
        $defaultLocale = !empty($validated['default_locale']) ? $validated['default_locale'] : SystemSetting::getDefaultLocale();

        SystemSetting::set('app_name', $validated['app_name'], __('controllers.admin.settings.desc_app_name'));
        SystemSetting::set('app_description', $validated['app_description'] ?? '', __('controllers.admin.settings.desc_app_description'));
        SystemSetting::set('default_locale', $defaultLocale, __('controllers.admin.settings.desc_default_locale'));
        SystemSetting::set('max_lines_per_user', (string) $maxLines, __('controllers.admin.settings.desc_max_lines'));
        SystemSetting::set('event_notify_login_failed', $request->boolean('event_notify_login_failed') ? '1' : '0', __('controllers.admin.settings.desc_event_login_failed'));
        SystemSetting::set('event_notify_user_registered', $request->boolean('event_notify_user_registered') ? '1' : '0', __('controllers.admin.settings.desc_event_user_registered'));
        SystemSetting::set('event_notify_security_alert', $request->boolean('event_notify_security_alert') ? '1' : '0', __('controllers.admin.settings.desc_event_security_alert'));

        $this->logSettingChange($request, __('controllers.admin.settings.audit_general_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'general'])->with('success', __('controllers.admin.settings.general_updated'));
    }

    /**
     * Update Organizational 4-8 Digit Pricing Matrix.
     */
    public function updateOrgPricing(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'org_price_4_regular' => ['required', 'numeric', 'min:0'],
            'org_price_4_rond' => ['required', 'numeric', 'min:0'],
            'org_price_5_regular' => ['required', 'numeric', 'min:0'],
            'org_price_5_rond' => ['required', 'numeric', 'min:0'],
            'org_price_6_regular' => ['required', 'numeric', 'min:0'],
            'org_price_6_rond' => ['required', 'numeric', 'min:0'],
            'org_price_7_regular' => ['required', 'numeric', 'min:0'],
            'org_price_7_rond' => ['required', 'numeric', 'min:0'],
            'org_price_8_regular' => ['required', 'numeric', 'min:0'],
            'org_price_8_rond' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated as $key => $val) {
            SystemSetting::set($key, (string) $val);
        }

        $this->logSettingChange($request, __('controllers.admin.settings.audit_pricing_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'pricing'])->with('success', __('controllers.admin.settings.pricing_updated'));
    }

    /**
     * Redirect auth route to unified settings page.
     */
    public function auth(): RedirectResponse
    {
        return redirect()->route('admin.settings.general', ['tab' => 'auth']);
    }

    /**
     * Update Auth Providers Toggles.
     */
    public function updateAuth(Request $request): RedirectResponse
    {
        $username = $request->boolean('auth_username_enabled');
        $email = $request->boolean('auth_email_enabled');
        $ldap = $request->boolean('auth_ldap_enabled');
        $keycloak = $request->boolean('auth_keycloak_enabled');
        $allowReg = $request->boolean('allow_user_registration');

        if (!$username && !$email && !$ldap && !$keycloak) {
            return back()->withErrors([
                'auth_username_enabled' => __('controllers.admin.settings.auth_methods_all_disabled'),
            ]);
        }

        SystemSetting::set('auth_username_enabled', $username ? '1' : '0', __('controllers.admin.settings.desc_auth_username'));
        SystemSetting::set('auth_email_enabled', $email ? '1' : '0', __('controllers.admin.settings.desc_auth_email'));
        SystemSetting::set('auth_ldap_enabled', $ldap ? '1' : '0', __('controllers.admin.settings.desc_auth_ldap'));
        SystemSetting::set('auth_keycloak_enabled', $keycloak ? '1' : '0', __('controllers.admin.settings.desc_auth_keycloak'));
        SystemSetting::set('allow_user_registration', $allowReg ? '1' : '0', __('controllers.admin.settings.desc_allow_registration'));

        $this->logSettingChange($request, __('controllers.admin.settings.audit_auth_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'auth'])->with('success', __('controllers.admin.settings.auth_updated'));
    }

    /**
     * Redirect LDAP route to unified settings page.
     */
    public function ldap(): RedirectResponse
    {
        return redirect()->route('admin.settings.general', ['tab' => 'ldap']);
    }

    /**
     * Update LDAP Dedicated Settings.
     */
    public function updateLdap(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'string', 'in:none,tls,ssl'],
            'base_dn' => ['required', 'string', 'max:255'],
            'bind_dn' => ['nullable', 'string', 'max:255'],
            'bind_password' => ['nullable', 'string', 'max:255'],
            'search_filter' => ['required', 'string', 'max:255'],
            'uid_attribute' => ['required', 'string', 'max:50'],
            'email_attribute' => ['required', 'string', 'max:50'],
            'name_attribute' => ['required', 'string', 'max:50'],
            'auto_create_user' => ['boolean'],
        ], [
            'host.required' => __('controllers.admin.settings.val_ldap_host_required'),
            'port.required' => __('controllers.admin.settings.val_ldap_port_required'),
            'base_dn.required' => __('controllers.admin.settings.val_ldap_base_dn_required'),
        ]);

        SystemSetting::set('ldap_host', $validated['host'], __('controllers.admin.settings.desc_ldap_host'));
        SystemSetting::set('ldap_port', (string) $validated['port'], __('controllers.admin.settings.desc_ldap_port'));
        SystemSetting::set('ldap_encryption', $validated['encryption'], __('controllers.admin.settings.desc_ldap_encryption'));
        SystemSetting::set('ldap_base_dn', $validated['base_dn'], __('controllers.admin.settings.desc_ldap_base_dn'));
        SystemSetting::set('ldap_bind_dn', $validated['bind_dn'] ?? '', __('controllers.admin.settings.desc_ldap_bind_dn'));
        if ($request->filled('bind_password')) {
            SystemSetting::set('ldap_bind_password', $validated['bind_password'], __('controllers.admin.settings.desc_ldap_bind_password'));
        }
        SystemSetting::set('ldap_search_filter', $validated['search_filter'], __('controllers.admin.settings.desc_ldap_search_filter'));
        SystemSetting::set('ldap_uid_attribute', $validated['uid_attribute'], __('controllers.admin.settings.desc_ldap_uid_attribute'));
        SystemSetting::set('ldap_email_attribute', $validated['email_attribute'], __('controllers.admin.settings.desc_ldap_email_attribute'));
        SystemSetting::set('ldap_name_attribute', $validated['name_attribute'], __('controllers.admin.settings.desc_ldap_name_attribute'));
        SystemSetting::set('ldap_auto_create_user', $request->boolean('auto_create_user') ? '1' : '0', __('controllers.admin.settings.desc_ldap_auto_create'));

        $this->logSettingChange($request, __('controllers.admin.settings.audit_ldap_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'ldap'])->with('success', __('controllers.admin.settings.ldap_updated'));
    }

    /**
     * Test connection to LDAP server.
     */
    public function testLdap(LdapAuthService $ldapService): JsonResponse
    {
        $result = $ldapService->testConnection();
        return response()->json($result);
    }

    /**
     * Redirect Keycloak route to unified settings page.
     */
    public function keycloak(): RedirectResponse
    {
        return redirect()->route('admin.settings.general', ['tab' => 'keycloak']);
    }

    /**
     * Update Keycloak Dedicated Settings.
     */
    public function updateKeycloak(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'realm' => ['required', 'string', 'max:100'],
            'client_id' => ['required', 'string', 'max:100'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'auto_create_user' => ['boolean'],
        ], [
            'base_url.required' => __('controllers.admin.settings.val_keycloak_url_required'),
            'base_url.url' => __('controllers.admin.settings.val_keycloak_url_invalid'),
            'realm.required' => __('controllers.admin.settings.val_keycloak_realm_required'),
            'client_id.required' => __('controllers.admin.settings.val_keycloak_client_id_required'),
        ]);

        SystemSetting::set('keycloak_base_url', rtrim($validated['base_url'], '/'), __('controllers.admin.settings.desc_keycloak_base_url'));
        SystemSetting::set('keycloak_realm', $validated['realm'], __('controllers.admin.settings.desc_keycloak_realm'));
        SystemSetting::set('keycloak_client_id', $validated['client_id'], __('controllers.admin.settings.desc_keycloak_client_id'));
        if ($request->filled('client_secret')) {
            SystemSetting::set('keycloak_client_secret', $validated['client_secret'], __('controllers.admin.settings.desc_keycloak_client_secret'));
        }
        SystemSetting::set('keycloak_auto_create_user', $request->boolean('auto_create_user') ? '1' : '0', __('controllers.admin.settings.desc_keycloak_auto_create'));

        $this->logSettingChange($request, __('controllers.admin.settings.audit_keycloak_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'keycloak'])->with('success', __('controllers.admin.settings.keycloak_updated'));
    }

    /**
     * Test connection to Keycloak server.
     */
    public function testKeycloak(KeycloakAuthService $keycloakService): JsonResponse
    {
        $result = $keycloakService->testConnection();
        return response()->json($result);
    }

    /**
     * Update SMTP Mail Settings.
     */
    public function updateSmtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['required', 'string', 'in:tls,ssl,none'],
            'mail_from_address' => ['required', 'email', 'max:255'],
            'mail_from_name' => ['required', 'string', 'max:255'],
            'require_email_verification' => ['boolean'],
        ]);

        SystemSetting::set('mail_mailer', 'smtp');
        SystemSetting::set('mail_host', $validated['mail_host'], __('controllers.admin.settings.desc_mail_host'));
        SystemSetting::set('mail_port', (string) $validated['mail_port'], __('controllers.admin.settings.desc_mail_port'));
        SystemSetting::set('mail_encryption', $validated['mail_encryption'], __('controllers.admin.settings.desc_mail_encryption'));
        SystemSetting::set('mail_username', $validated['mail_username'] ?? '', __('controllers.admin.settings.desc_mail_username'));
        if ($request->filled('mail_password')) {
            SystemSetting::set('mail_password', $validated['mail_password'], __('controllers.admin.settings.desc_mail_password'));
        }
        SystemSetting::set('mail_from_address', $validated['mail_from_address'], __('controllers.admin.settings.desc_mail_from_address'));
        SystemSetting::set('mail_from_name', $validated['mail_from_name'], __('controllers.admin.settings.desc_mail_from_name'));
        SystemSetting::set('require_email_verification', $request->boolean('require_email_verification') ? '1' : '0', __('controllers.admin.settings.desc_require_email_verification'));

        \App\Services\Mail\MailConfigService::applySettings();

        $this->logSettingChange($request, __('controllers.admin.settings.audit_smtp_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'smtp'])->with('success', __('controllers.admin.settings.smtp_updated'));
    }

    /**
     * Test connection to SMTP server.
     */
    public function testSmtp(Request $request): JsonResponse
    {
        $recipient = $request->input('recipient', $request->user()?->email);
        $result = \App\Services\Mail\MailConfigService::testConnection($recipient);
        return response()->json($result);
    }

    /**
     * Update Voice Bridge high ports & host settings.
     */
    public function updateVoiceBridge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:100'],
            'api_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ws_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ws_public_url' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'turn_domain_or_ip' => ['nullable', 'string', 'max:255'],
            'turn_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'turns_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'turns_enabled' => ['boolean'],
            'turn_realm' => ['nullable', 'string', 'max:100'],
            'offline_mode' => ['boolean'],
            'stun_urls' => ['nullable', 'string', 'max:1000'],
            'turn_urls' => ['nullable', 'string', 'max:1000'],
            'turn_username' => ['nullable', 'string', 'max:255'],
            'turn_credential' => ['nullable', 'string', 'max:255'],
            'turn_secret' => ['nullable', 'string', 'max:255'],
            'ice_transport_policy' => ['nullable', 'string', 'in:all,relay'],
            'intranet_ssids' => ['nullable', 'string', 'max:1000'],
            'local_push_ws_url' => ['nullable', 'string', 'max:255'],
            'ring_timeout_seconds' => ['nullable', 'integer', 'min:15', 'max:180'],
            'sfu_enabled' => ['boolean'],
            'sfu_ws_path' => ['nullable', 'string', 'max:100'],
            'sfu_ws_public_url' => ['nullable', 'string', 'max:255'],
            'sfu_min_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'sfu_max_port' => ['nullable', 'integer', 'min:1024', 'max:65535'],
        ], [
            'host.required' => __('controllers.admin.settings.val_voice_host_required'),
            'api_port.required' => __('controllers.admin.settings.val_voice_api_port_required'),
            'ws_port.required' => __('controllers.admin.settings.val_voice_ws_port_required'),
        ]);

        SystemSetting::set('voice_bridge_host', $validated['host'], __('controllers.admin.settings.desc_voice_bridge_host'));
        SystemSetting::set('voice_bridge_api_port', (string) $validated['api_port'], __('controllers.admin.settings.desc_voice_bridge_api_port'));
        SystemSetting::set('voice_bridge_ws_port', (string) $validated['ws_port'], __('controllers.admin.settings.desc_voice_bridge_ws_port'));
        SystemSetting::set('voice_bridge_ws_public_url', trim((string) ($validated['ws_public_url'] ?? '')), __('controllers.admin.settings.desc_voice_bridge_ws_public_url'));
        SystemSetting::set('voice_bridge_secret', (string) ($validated['secret'] ?? ''), __('controllers.admin.settings.desc_voice_bridge_secret'));
        SystemSetting::set('voice_bridge_enabled', $request->boolean('enabled') ? '1' : '0', __('controllers.admin.settings.desc_voice_bridge_enabled'));

        if ($request->has('turn_domain_or_ip')) {
            SystemSetting::set('voice_turn_domain_or_ip', trim((string) ($validated['turn_domain_or_ip'] ?? '')), __('controllers.admin.settings.desc_voice_turn_domain'));
        }
        if ($request->has('turn_port')) {
            SystemSetting::set('voice_turn_port', (string) ($validated['turn_port'] ?? 3479), __('controllers.admin.settings.desc_voice_turn_port'));
        }
        if ($request->has('turns_port')) {
            SystemSetting::set('voice_turns_port', (string) ($validated['turns_port'] ?? 5350), __('controllers.admin.settings.desc_voice_turns_port'));
        }
        if ($request->has('turns_enabled')) {
            SystemSetting::set('voice_turns_enabled', $request->boolean('turns_enabled') ? '1' : '0', __('controllers.admin.settings.desc_voice_turns_enabled'));
        }
        if ($request->has('turn_realm')) {
            SystemSetting::set('voice_turn_realm', trim((string) ($validated['turn_realm'] ?? 'ravy-no')), 'TURN server authentication realm');
        }
        if ($request->has('offline_mode')) {
            SystemSetting::set('voice_offline_mode', $request->boolean('offline_mode') ? '1' : '0', __('controllers.admin.settings.desc_voice_offline_mode'));
        }

        if ($request->has('stun_urls')) {
            SystemSetting::set('voice_stun_urls', trim((string) ($validated['stun_urls'] ?? '')), __('controllers.admin.settings.desc_voice_stun_urls'));
        }
        if ($request->has('turn_urls')) {
            SystemSetting::set('voice_turn_urls', trim((string) ($validated['turn_urls'] ?? '')), __('controllers.admin.settings.desc_voice_turn_urls'));
        }
        if ($request->has('turn_username')) {
            SystemSetting::set('voice_turn_username', trim((string) ($validated['turn_username'] ?? '')), __('controllers.admin.settings.desc_voice_turn_username'));
        }
        if ($request->has('turn_credential')) {
            SystemSetting::set('voice_turn_credential', (string) ($validated['turn_credential'] ?? ''), __('controllers.admin.settings.desc_voice_turn_credential'));
        }
        if ($request->has('turn_secret')) {
            SystemSetting::set('voice_turn_secret', trim((string) ($validated['turn_secret'] ?? '')), __('controllers.admin.settings.desc_voice_turn_secret'));
        }
        if ($request->has('ice_transport_policy')) {
            SystemSetting::set('voice_ice_transport_policy', (string) ($validated['ice_transport_policy'] ?? 'all'), __('controllers.admin.settings.desc_voice_ice_policy'));
        }
        if ($request->has('intranet_ssids')) {
            SystemSetting::set('voice_intranet_ssids', trim((string) ($validated['intranet_ssids'] ?? '')), __('controllers.admin.settings.desc_voice_intranet_ssids'));
        }
        if ($request->has('local_push_ws_url')) {
            SystemSetting::set('voice_local_push_ws_url', trim((string) ($validated['local_push_ws_url'] ?? '')), __('controllers.admin.settings.desc_voice_local_push_ws_url'));
        }
        if ($request->has('ring_timeout_seconds')) {
            SystemSetting::set('voice_ring_timeout_seconds', (string) ($validated['ring_timeout_seconds'] ?? 45), __('controllers.admin.settings.desc_voice_ring_timeout_seconds'));
        }
        if ($request->has('sfu_enabled')) {
            SystemSetting::set('voice_sfu_enabled', $request->boolean('sfu_enabled') ? '1' : '0', 'Enable embedded SFU conference engine');
        }
        if ($request->has('sfu_ws_path')) {
            $rawSfuPath = trim((string) ($validated['sfu_ws_path'] ?? '/ws/sfu'));
            $normSfuPath = '/' . ltrim($rawSfuPath ?: 'ws/sfu', '/');
            SystemSetting::set('voice_sfu_ws_path', $normSfuPath, 'SFU WebSocket endpoint path');
        }
        if ($request->has('sfu_ws_public_url')) {
            SystemSetting::set('voice_sfu_ws_public_url', trim((string) ($validated['sfu_ws_public_url'] ?? '')), 'Public WSS URL for SFU conference');
        }
        if ($request->has('sfu_min_port')) {
            SystemSetting::set('voice_sfu_min_port', (string) ($validated['sfu_min_port'] ?? 49152), 'SFU UDP media relay min port');
        }
        if ($request->has('sfu_max_port')) {
            SystemSetting::set('voice_sfu_max_port', (string) ($validated['sfu_max_port'] ?? 49250), 'SFU UDP media relay max port');
        }

        $this->logSettingChange($request, __('controllers.admin.settings.audit_voice_bridge_updated'));

        return redirect()->route('admin.settings.general', ['tab' => 'bridge'])->with('success', __('controllers.admin.settings.voice_bridge_updated'));
    }

    /**
     * Test live connectivity to the Voice Bridge daemon via Asymmetric Handshake & Diagnostics.
     */
    public function testVoiceBridge(Request $request): JsonResponse
    {
        $host = trim((string) $request->input('host', SystemSetting::get('voice_bridge_host', '127.0.0.1')));
        $port = (int) $request->input('api_port', SystemSetting::get('voice_bridge_api_port', 49000));
        $secret = trim((string) $request->input('secret', SystemSetting::get('voice_bridge_secret', '')));

        try {
            $startTime = microtime(true);
            $callbackUrl = route('api.voice.bridge_event');
            $handshakeUrl = \App\Services\Communication\VoiceCallService::buildBridgeUrl($host, $port, '/api/handshake');
            $healthUrl = \App\Services\Communication\VoiceCallService::buildBridgeUrl($host, $port, '/health');

            \Illuminate\Support\Facades\Log::info("[VoiceBridge] Testing two-way handshake with daemon at {$handshakeUrl} (Callback: {$callbackUrl})");

            // Clear previous cached diagnostics
            \Illuminate\Support\Facades\Cache::forget('voice_bridge_latest_diagnostics');

            // Send handshake request to Go daemon
            $handshakePayload = [
                'host' => $host,
                'token' => $secret,
                'secret' => $secret,
                'callback_url' => $callbackUrl,
                'ports_pool' => [
                    'min' => (int) SystemSetting::get('voice_sfu_min_port', 49001),
                    'max' => (int) SystemSetting::get('voice_sfu_max_port', 49300),
                ],
            ];

            $response = Http::withHeaders(array_filter([
                'X-Voice-Bridge-Secret' => $secret,
                'X-Bridge-Secret' => $secret,
            ]))
                ->timeout(4.0)
                ->post($handshakeUrl, $handshakePayload);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                // Allow a brief moment (300ms) for the async callback from Go to reach Laravel's webhook
                usleep(300000);

                $diagnostics = \Illuminate\Support\Facades\Cache::get('voice_bridge_latest_diagnostics');

                // If callback not landed yet, fetch /health as supplementary telemetry
                $healthData = [];
                try {
                    $healthResp = Http::withHeaders(array_filter(['X-Bridge-Secret' => $secret]))->timeout(2.0)->get($healthUrl);
                    if ($healthResp->successful()) {
                        $healthData = $healthResp->json() ?: [];
                    }
                } catch (\Throwable $e) {
                    // Ignore secondary health fetch error
                }

                $mergedData = is_array($diagnostics) ? $diagnostics : $healthData;
                $status = $mergedData['status'] ?? 'ok';

                $msg = __('controllers.admin.settings.voice_bridge_test_ok', ['latency' => $latencyMs]);
                if (! empty($secret)) {
                    $msg .= ' ' . __('controllers.admin.settings.voice_bridge_secret_ok');
                }
                $msg .= ' ' . __('controllers.admin.settings.voice_bridge_handshake_ok');

                return response()->json([
                    'success' => true,
                    'asymmetric_handshake' => true,
                    'status' => $status,
                    'data' => $mergedData,
                    'redis' => $mergedData['redis'] ?? ($healthData['redis'] ?? null),
                    'udp_pool' => $mergedData['udp_pool'] ?? ($healthData['udp_pool'] ?? null),
                    'stun_turn' => $mergedData['stun_turn'] ?? ($healthData['stun_turn'] ?? null),
                    'sfu' => $mergedData['sfu'] ?? ($healthData['sfu'] ?? null),
                    'latency_ms' => $latencyMs,
                    'auth_valid' => true,
                    'auth_required' => ! empty($secret),
                    'message' => $msg,
                ]);
            }

            // Fallback: If daemon is an older build without /api/handshake, probe /health directly
            $fallbackResp = Http::withHeaders(array_filter(['X-Bridge-Secret' => $secret]))
                ->timeout(3.0)
                ->get($healthUrl);

            if ($fallbackResp->successful()) {
                $data = $fallbackResp->json() ?: [];
                return response()->json([
                    'success' => true,
                    'asymmetric_handshake' => false,
                    'data' => $data,
                    'redis' => $data['redis'] ?? null,
                    'udp_pool' => $data['udp_pool'] ?? null,
                    'latency_ms' => (int) round((microtime(true) - $startTime) * 1000),
                    'message' => __('controllers.admin.settings.voice_bridge_test_ok', ['latency' => $latencyMs]),
                ]);
            }

            \Illuminate\Support\Facades\Log::warning("[VoiceBridge] Daemon HTTP status {$response->status()} at {$handshakeUrl}");
            return response()->json([
                'success' => false,
                'message' => __('controllers.admin.settings.voice_bridge_bad_status', ['status' => $response->status()]),
            ], 422);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[VoiceBridge] Connection error to {$host}:{$port} - " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => __('controllers.admin.settings.voice_bridge_unreachable', ['error' => $e->getMessage()]),
            ], 422);
        }
    }

    /**
     * Auto-sync Voice Bridge, STUN, TURN, and SFU settings from live daemon.
     */
    public function syncVoiceBridge(Request $request): JsonResponse
    {
        $host = trim((string) $request->input('host', SystemSetting::get('voice_bridge_host', '127.0.0.1')));
        $port = (int) $request->input('api_port', SystemSetting::get('voice_bridge_api_port', 49000));
        $secret = trim((string) $request->input('secret', SystemSetting::get('voice_bridge_secret', '')));

        if (empty($host) || $port <= 0) {
            return response()->json([
                'success' => false,
                'message' => __('controllers.admin.settings.voice_bridge_sync_invalid_params'),
            ], 422);
        }

        try {
            $callbackUrl = route('api.voice.bridge_event');
            $handshakeUrl = \App\Services\Communication\VoiceCallService::buildBridgeUrl($host, $port, '/api/handshake');
            $healthUrl = \App\Services\Communication\VoiceCallService::buildBridgeUrl($host, $port, '/health');

            \Illuminate\Support\Facades\Log::info("[VoiceBridge] Two-way syncing settings via {$handshakeUrl} (Callback: {$callbackUrl})");

            // 1. Send Handshake
            $handshakePayload = [
                'host' => $host,
                'token' => $secret,
                'secret' => $secret,
                'callback_url' => $callbackUrl,
                'ports_pool' => [
                    'min' => (int) SystemSetting::get('voice_sfu_min_port', 49001),
                    'max' => (int) SystemSetting::get('voice_sfu_max_port', 49300),
                ],
            ];

            try {
                Http::withHeaders(array_filter([
                    'X-Voice-Bridge-Secret' => $secret,
                    'X-Bridge-Secret' => $secret,
                ]))->timeout(3.0)->post($handshakeUrl, $handshakePayload);
            } catch (\Throwable $e) {
                // Non-fatal if /api/handshake times out, continue to /health probe
            }

            // Wait 250ms for asynchronous feedback webhook
            usleep(250000);

            // Fetch live data from /health
            $response = Http::withHeaders(array_filter(['X-Bridge-Secret' => $secret]))
                ->timeout(4.0)
                ->get($healthUrl);

            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::warning("[VoiceBridge] Sync HTTP status {$response->status()} from {$healthUrl}");
                return response()->json([
                    'success' => false,
                    'message' => __('controllers.admin.settings.voice_bridge_bad_status', ['status' => $response->status()]),
                ], 422);
            }

            $data = $response->json();
            if (! is_array($data)) {
                return response()->json([
                    'success' => false,
                    'message' => __('controllers.admin.settings.voice_bridge_invalid_json'),
                ], 422);
            }

            // Also check if detailed diagnostics landed via callback
            $diagnostics = \Illuminate\Support\Facades\Cache::get('voice_bridge_latest_diagnostics');
            if (is_array($diagnostics)) {
                $data = array_merge($data, $diagnostics);
            }

            // 1. Resolve effective host and relay IP
            $serverReportedHost = trim((string) ($data['bridge_host'] ?? ''));
            $relayIp = trim((string) ($data['stun_turn']['relay_ip'] ?? ''));
            $effectiveHost = ($serverReportedHost !== '' && $serverReportedHost !== '0.0.0.0')
                ? $serverReportedHost
                : (($relayIp !== '' && $relayIp !== '0.0.0.0') ? $relayIp : $host);

            $apiPort = (int) ($data['api_port'] ?? $port);
            $wsPort = (int) ($data['ws_port'] ?? 49001);

            // 2. TURN / STUN details
            $turnInfo = is_array($data['stun_turn'] ?? null) ? $data['stun_turn'] : [];
            $turnPort = (int) ($turnInfo['port'] ?? 3479);
            $turnsPort = (int) ($turnInfo['turns_port'] ?? 5350);
            $hasTurns = ! empty($turnInfo['has_tls']) || $turnsPort > 0;
            $turnRealm = trim((string) ($turnInfo['realm'] ?? 'ravy-no'));
            $turnDomainOrIp = ($relayIp !== '' && $relayIp !== '0.0.0.0') ? $relayIp : $effectiveHost;

            // 3. SFU details & 300 UDP Port Pool
            $sfuInfo = is_array($data['sfu'] ?? null) ? $data['sfu'] : [];
            $udpPoolInfo = is_array($data['udp_pool'] ?? null) ? $data['udp_pool'] : [];
            $sfuActive = ! empty($sfuInfo['enabled']);
            $sfuWsPath = trim((string) ($sfuInfo['ws_path'] ?? '/ws/sfu'));
            if ($sfuWsPath === '') {
                $sfuWsPath = '/ws/sfu';
            }
            $sfuWsPath = '/' . ltrim($sfuWsPath, '/');

            $sfuMinPort = 49001;
            $sfuMaxPort = 49300;
            if (! empty($udpPoolInfo['min_port'])) {
                $sfuMinPort = (int) $udpPoolInfo['min_port'];
                $sfuMaxPort = (int) ($udpPoolInfo['max_port'] ?? ($sfuMinPort + 299));
            } elseif (! empty($sfuInfo['udp_port_range']) && is_string($sfuInfo['udp_port_range'])) {
                $ports = explode('-', $sfuInfo['udp_port_range']);
                if (count($ports) === 2) {
                    $sfuMinPort = (int) $ports[0];
                    $sfuMaxPort = (int) $ports[1];
                }
            }

            // 4. Construct dedicated STUN and TURN URLs strictly from server parameters
            $stunUrls = "stun:{$turnDomainOrIp}:{$turnPort}";
            $turnUrlLines = [
                "turn:{$turnDomainOrIp}:{$turnPort}?transport=udp",
                "turn:{$turnDomainOrIp}:{$turnPort}?transport=tcp",
            ];
            if ($hasTurns && $turnsPort > 0) {
                $turnUrlLines[] = "turns:{$turnDomainOrIp}:{$turnsPort}?transport=tcp";
            }
            $turnUrls = implode("\n", $turnUrlLines);

            // 5. Persist synchronized configuration to SystemSettings
            SystemSetting::set('voice_bridge_host', $effectiveHost, __('controllers.admin.settings.desc_voice_bridge_host'));
            SystemSetting::set('voice_bridge_api_port', (string) $apiPort, __('controllers.admin.settings.desc_voice_bridge_api_port'));
            SystemSetting::set('voice_bridge_ws_port', (string) $wsPort, __('controllers.admin.settings.desc_voice_bridge_ws_port'));
            SystemSetting::set('voice_bridge_ws_public_url', "ws://{$effectiveHost}:{$wsPort}/ws/signaling", __('controllers.admin.settings.desc_voice_bridge_ws_public_url'));
            SystemSetting::set('voice_bridge_enabled', '1', __('controllers.admin.settings.desc_voice_bridge_enabled'));
            SystemSetting::set('voice_bridge_status', 'online', __('controllers.admin.settings.desc_voice_bridge_status'));
            SystemSetting::set('voice_bridge_last_synced_at', now()->toIso8601String(), __('controllers.admin.settings.desc_voice_bridge_last_synced_at'));

            if ($secret !== '') {
                SystemSetting::set('voice_bridge_secret', $secret, __('controllers.admin.settings.desc_voice_bridge_secret'));
                SystemSetting::set('voice_turn_secret', $secret, __('controllers.admin.settings.desc_voice_turn_secret'));
            }

            SystemSetting::set('voice_turn_domain_or_ip', $turnDomainOrIp, __('controllers.admin.settings.desc_voice_turn_domain'));
            SystemSetting::set('voice_turn_port', (string) $turnPort, __('controllers.admin.settings.desc_voice_turn_port'));
            SystemSetting::set('voice_turns_port', (string) $turnsPort, __('controllers.admin.settings.desc_voice_turns_port'));
            SystemSetting::set('voice_turns_enabled', $hasTurns ? '1' : '0', __('controllers.admin.settings.desc_voice_turns_enabled'));
            SystemSetting::set('voice_turn_realm', $turnRealm, __('controllers.admin.settings.desc_voice_turn_realm'));
            SystemSetting::set('voice_stun_urls', $stunUrls, __('controllers.admin.settings.desc_voice_stun_urls'));
            SystemSetting::set('voice_turn_urls', $turnUrls, __('controllers.admin.settings.desc_voice_turn_urls'));

            SystemSetting::set('voice_sfu_enabled', $sfuActive ? '1' : '0', __('controllers.admin.settings.desc_voice_sfu_enabled'));
            SystemSetting::set('voice_sfu_ws_path', $sfuWsPath, __('controllers.admin.settings.desc_voice_sfu_ws_path'));
            SystemSetting::set('voice_sfu_ws_public_url', "ws://{$effectiveHost}:{$wsPort}{$sfuWsPath}", __('controllers.admin.settings.desc_voice_sfu_ws_public_url'));
            SystemSetting::set('voice_sfu_min_port', (string) $sfuMinPort, __('controllers.admin.settings.desc_voice_sfu_min_port'));
            SystemSetting::set('voice_sfu_max_port', (string) $sfuMaxPort, __('controllers.admin.settings.desc_voice_sfu_max_port'));

            $this->logSettingChange($request, __('controllers.admin.settings.audit_voice_bridge_synced'));

            return response()->json([
                'success' => true,
                'message' => __('controllers.admin.settings.voice_bridge_sync_success'),
                'synced' => [
                    'host' => $effectiveHost,
                    'api_port' => $apiPort,
                    'ws_port' => $wsPort,
                    'ws_public_url' => "ws://{$effectiveHost}:{$wsPort}/ws/signaling",
                    'secret' => $secret,
                    'turn_domain_or_ip' => $turnDomainOrIp,
                    'turn_port' => $turnPort,
                    'turns_port' => $turnsPort,
                    'turns_enabled' => $hasTurns,
                    'turn_realm' => $turnRealm,
                    'stun_urls' => $stunUrls,
                    'turn_urls' => $turnUrls,
                    'sfu_enabled' => $sfuActive,
                    'sfu_ws_path' => $sfuWsPath,
                    'sfu_ws_public_url' => "ws://{$effectiveHost}:{$wsPort}{$sfuWsPath}",
                    'sfu_min_port' => $sfuMinPort,
                    'sfu_max_port' => $sfuMaxPort,
                    'redis' => $data['redis'] ?? null,
                    'udp_pool' => $data['udp_pool'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[VoiceBridge] Sync error from {$host}:{$port} - " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => __('controllers.admin.settings.voice_bridge_unreachable', ['error' => $e->getMessage()]),
            ], 422);
        }
    }

    /**
     * Log administrative setting changes in audit log.
     */
    protected function logSettingChange(Request $request, string $description): void
    {
        try {
            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'admin_settings_updated',
                'entity_type' => SystemSetting::class,
                'entity_id' => null,
                'description' => $description,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {
        }
    }
}