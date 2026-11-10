<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\Auth\KeycloakAuthService;
use App\Services\Auth\LdapAuthService;
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
        if (!in_array($tab, ['general', 'pricing', 'auth', 'ldap', 'keycloak'])) {
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