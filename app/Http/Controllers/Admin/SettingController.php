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
     * Display the General Settings view.
     */
    public function general(): Response
    {
        return Inertia::render('Admin/Settings/General', [
            'settings' => [
                'app_name' => SystemSetting::get('app_name', 'سامانه جامع مدیریت شماره‌ها و ارتباطات'),
                'app_description' => SystemSetting::get('app_description', 'هسته مرکزی مدیریت خطوط مجازی، درگاه پیامک و احراز هویت سازمانی'),
                'event_notify_login_failed' => (bool) (int) SystemSetting::get('event_notify_login_failed', '1'),
                'event_notify_user_registered' => (bool) (int) SystemSetting::get('event_notify_user_registered', '1'),
                'event_notify_security_alert' => (bool) (int) SystemSetting::get('event_notify_security_alert', '1'),
            ],
        ]);
    }

    /**
     * Update General Settings.
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:100'],
            'app_description' => ['nullable', 'string', 'max:255'],
            'event_notify_login_failed' => ['boolean'],
            'event_notify_user_registered' => ['boolean'],
            'event_notify_security_alert' => ['boolean'],
        ], [
            'app_name.required' => 'ورود عنوان سامانه الزامی است.',
        ]);

        SystemSetting::set('app_name', $validated['app_name'], 'عنوان اصلی و نام رسمی سامانه');
        SystemSetting::set('app_description', $validated['app_description'] ?? '', 'شرح مختصر سامانه در صفحات عمومی');
        SystemSetting::set('event_notify_login_failed', $request->boolean('event_notify_login_failed') ? '1' : '0', 'ثبت و گزارش رویداد تلاش‌های ناموفق ورود در لاگ امنیتی');
        SystemSetting::set('event_notify_user_registered', $request->boolean('event_notify_user_registered') ? '1' : '0', 'ثبت رویداد ثبت‌نام کاربران جدید در گزارش‌های سامانه');
        SystemSetting::set('event_notify_security_alert', $request->boolean('event_notify_security_alert') ? '1' : '0', 'ارسال هشدار و ثبت رویداد مخاطرات امنیتی');

        $this->logSettingChange($request, 'تنظیمات عمومی سامانه با موفقیت ذخیره گردید.');

        return back()->with('success', 'تنظیمات عمومی سامانه با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Display the Auth Providers Settings view.
     */
    public function auth(): Response
    {
        return Inertia::render('Admin/Settings/Auth', [
            'settings' => [
                'auth_username_enabled' => (bool) (int) SystemSetting::get('auth_username_enabled', '1'),
                'auth_email_enabled' => (bool) (int) SystemSetting::get('auth_email_enabled', '1'),
                'auth_ldap_enabled' => (bool) (int) SystemSetting::get('auth_ldap_enabled', '0'),
                'auth_keycloak_enabled' => (bool) (int) SystemSetting::get('auth_keycloak_enabled', '0'),
                'allow_user_registration' => (bool) (int) SystemSetting::get('allow_user_registration', '1'),
            ],
            'ldapConfigured' => !empty(SystemSetting::get('ldap_host')),
            'keycloakConfigured' => !empty(SystemSetting::get('keycloak_client_id')),
        ]);
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
                'auth_username_enabled' => 'حداقل یکی از روش‌های احراز هویت باید در سامانه فعال باقی بماند تا امکان ورود مسدود نشود.',
            ]);
        }

        SystemSetting::set('auth_username_enabled', $username ? '1' : '0', 'امکان ورود با شناسه کاربری و گذرواژه');
        SystemSetting::set('auth_email_enabled', $email ? '1' : '0', 'امکان ورود با پست الکترونیک (ایمیل) و گذرواژه');
        SystemSetting::set('auth_ldap_enabled', $ldap ? '1' : '0', 'فعال بودن ورود سازمانی از طریق سرویس دایرکتوری LDAP');
        SystemSetting::set('auth_keycloak_enabled', $keycloak ? '1' : '0', 'فعال بودن ورود یکپارچه SSO از طریق سرور Keycloak');
        SystemSetting::set('allow_user_registration', $allowReg ? '1' : '0', 'امکان ثبت‌نام مستقیم کاربر جدید در سامانه');

        $this->logSettingChange($request, 'پیکربندی وضعیت فعال‌بودن روش‌های احراز هویت تغییر یافت.');

        return back()->with('success', 'وضعیت فعال‌بودن روش‌های احراز هویت با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Display LDAP Dedicated Settings view.
     */
    public function ldap(LdapAuthService $ldapService): Response
    {
        return Inertia::render('Admin/Settings/Ldap', [
            'settings' => $ldapService->getConfig(),
            'ldapExtensionLoaded' => extension_loaded('ldap'),
            'isEnabled' => (bool) (int) SystemSetting::get('auth_ldap_enabled', '0'),
        ]);
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
            'host.required' => 'ورود آدرس هاست LDAP الزامی است.',
            'port.required' => 'ورود پورت اتصال الزامی است.',
            'base_dn.required' => 'ورود شاخه ریشه Base DN الزامی است.',
        ]);

        SystemSetting::set('ldap_host', $validated['host'], 'آدرس سرور دایرکتوری LDAP');
        SystemSetting::set('ldap_port', (string) $validated['port'], 'پورت اتصال به سرور LDAP');
        SystemSetting::set('ldap_encryption', $validated['encryption'], 'نوع رمزنگاری ارتباط با سرور LDAP');
        SystemSetting::set('ldap_base_dn', $validated['base_dn'], 'شاخه ریشه جستجو در دایرکتوری (Base DN)');
        SystemSetting::set('ldap_bind_dn', $validated['bind_dn'] ?? '', 'شناسه کاربری سرویس جهت اتصال (Bind DN)');
        if ($request->filled('bind_password')) {
            SystemSetting::set('ldap_bind_password', $validated['bind_password'], 'رمز عبور کاربر سرویس LDAP');
        }
        SystemSetting::set('ldap_search_filter', $validated['search_filter'], 'فیلتر جستجوی کاربر در LDAP');
        SystemSetting::set('ldap_uid_attribute', $validated['uid_attribute'], 'ویژگی شناسه کاربری در LDAP');
        SystemSetting::set('ldap_email_attribute', $validated['email_attribute'], 'ویژگی ایمیل کاربر در LDAP');
        SystemSetting::set('ldap_name_attribute', $validated['name_attribute'], 'ویژگی نام کامل کاربر در LDAP');
        SystemSetting::set('ldap_auto_create_user', $request->boolean('auto_create_user') ? '1' : '0', 'ایجاد خودکار حساب کاربری در اولین ورود موفق LDAP');

        $this->logSettingChange($request, 'تنظیمات سرور سازمانی LDAP به‌روزرسانی شد.');

        return back()->with('success', 'تنظیمات اتصال به سرویس LDAP با موفقیت ذخیره گردید.');
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
     * Display Keycloak Dedicated Settings view.
     */
    public function keycloak(KeycloakAuthService $keycloakService): Response
    {
        return Inertia::render('Admin/Settings/Keycloak', [
            'settings' => $keycloakService->getConfig(),
            'callbackUrl' => route('auth.keycloak.callback'),
            'isEnabled' => (bool) (int) SystemSetting::get('auth_keycloak_enabled', '0'),
        ]);
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
            'base_url.required' => 'ورود آدرس پایه سرور Keycloak الزامی است.',
            'base_url.url' => 'آدرس سرور Keycloak باید یک نشانی اینترنتی معتبر (URL) باشد.',
            'realm.required' => 'ورود نام قلمرو (Realm) الزامی است.',
            'client_id.required' => 'ورود شناسه کلاینت (Client ID) الزامی است.',
        ]);

        SystemSetting::set('keycloak_base_url', rtrim($validated['base_url'], '/'), 'آدرس پایه سرور Keycloak');
        SystemSetting::set('keycloak_realm', $validated['realm'], 'نام قلمرو (Realm) در Keycloak');
        SystemSetting::set('keycloak_client_id', $validated['client_id'], 'شناسه کلاینت ثبت شده در Keycloak (Client ID)');
        if ($request->filled('client_secret')) {
            SystemSetting::set('keycloak_client_secret', $validated['client_secret'], 'کلید محرمانه کلاینت در Keycloak');
        }
        SystemSetting::set('keycloak_auto_create_user', $request->boolean('auto_create_user') ? '1' : '0', 'ایجاد خودکار حساب کاربری در اولین ورود موفق Keycloak');

        $this->logSettingChange($request, 'تنظیمات سرور یکپارچه هویت Keycloak به‌روزرسانی شد.');

        return back()->with('success', 'تنظیمات احراز هویت یکپارچه Keycloak با موفقیت ذخیره گردید.');
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

