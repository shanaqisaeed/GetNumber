<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update users table: add email, make username & password nullable for external SSO
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->after('username');
                $table->index('email');
            }
        });

        // 2. Create user_identities table to support multi-provider authentication
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 50); // username, email, ldap, keycloak, etc.
            $table->string('provider_id', 255); // username string, email, LDAP DN/uid, Keycloak sub
            $table->string('provider_email', 255)->nullable();
            $table->json('provider_data')->nullable(); // Extended metadata from LDAP / Keycloak profile
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            $table->unique(['provider', 'provider_id'], 'idx_provider_identity_unique');
            $table->index(['user_id', 'provider'], 'idx_user_provider');
        });

        // 3. Migrate existing users into user_identities table
        $existingUsers = DB::table('users')->get();
        foreach ($existingUsers as $user) {
            if (!empty($user->username)) {
                DB::table('user_identities')->updateOrInsert(
                    [
                        'provider' => 'username',
                        'provider_id' => strtolower($user->username),
                    ],
                    [
                        'user_id' => $user->id,
                        'provider_email' => $user->email ?? null,
                        'provider_data' => json_encode(['migrated' => true]),
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            if (!empty($user->email)) {
                DB::table('user_identities')->updateOrInsert(
                    [
                        'provider' => 'email',
                        'provider_id' => strtolower($user->email),
                    ],
                    [
                        'user_id' => $user->id,
                        'provider_email' => strtolower($user->email),
                        'provider_data' => json_encode(['migrated' => true]),
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 4. Seed central system settings for Auth & General settings if not present
        $defaultSettings = [
            // General Settings
            ['key' => 'app_name', 'value' => 'سامانه جامع مدیریت شماره‌ها و ارتباطات', 'description' => 'عنوان اصلی و نام رسمی سامانه'],
            ['key' => 'app_description', 'value' => 'هسته مرکزی مدیریت خطوط مجازی، درگاه پیامک و احراز هویت سازمانی', 'description' => 'شرح مختصر سامانه در صفحات عمومی'],
            ['key' => 'event_notify_login_failed', 'value' => '1', 'description' => 'ثبت و گزارش رویداد تلاش‌های ناموفق ورود در لاگ امنیتی'],
            ['key' => 'event_notify_user_registered', 'value' => '1', 'description' => 'ثبت رویداد ثبت‌نام کاربران جدید در گزارش‌های سامانه'],
            ['key' => 'event_notify_security_alert', 'value' => '1', 'description' => 'ارسال هشدار و ثبت رویداد مخاطرات امنیتی'],

            // Auth Provider Toggles
            ['key' => 'auth_username_enabled', 'value' => '1', 'description' => 'امکان ورود با شناسه کاربری و گذرواژه'],
            ['key' => 'auth_email_enabled', 'value' => '1', 'description' => 'امکان ورود با پست الکترونیک (ایمیل) و گذرواژه'],
            ['key' => 'auth_ldap_enabled', 'value' => '0', 'description' => 'فعال بودن ورود سازمانی از طریق سرویس دایرکتوری LDAP'],
            ['key' => 'auth_keycloak_enabled', 'value' => '0', 'description' => 'فعال بودن ورود یکپارچه SSO از طریق سرور Keycloak'],
            ['key' => 'allow_user_registration', 'value' => '1', 'description' => 'امکان ثبت‌نام مستقیم کاربر جدید در سامانه'],

            // LDAP Dedicated Settings
            ['key' => 'ldap_host', 'value' => '127.0.0.1', 'description' => 'آدرس سرور دایرکتوری LDAP'],
            ['key' => 'ldap_port', 'value' => '389', 'description' => 'پورت اتصال به سرور LDAP (پیش‌فرض 389 یا 636 برای SSL)'],
            ['key' => 'ldap_encryption', 'value' => 'none', 'description' => 'نوع رمزنگاری ارتباط با سرور LDAP (none, tls, ssl)'],
            ['key' => 'ldap_base_dn', 'value' => 'dc=organization,dc=local', 'description' => 'شاخه ریشه جستجو در دایرکتوری (Base DN)'],
            ['key' => 'ldap_bind_dn', 'value' => 'cn=admin,dc=organization,dc=local', 'description' => 'شناسه کاربری سرویس جهت اتصال (Bind DN)'],
            ['key' => 'ldap_bind_password', 'value' => '', 'description' => 'رمز عبور کاربر سرویس LDAP'],
            ['key' => 'ldap_search_filter', 'value' => '(&(objectClass=person)(|(uid={login})(mail={login})(sAMAccountName={login})))', 'description' => 'فیلتر جستجوی کاربر در LDAP'],
            ['key' => 'ldap_uid_attribute', 'value' => 'uid', 'description' => 'ویژگی شناسه کاربری در LDAP (مانند uid یا sAMAccountName)'],
            ['key' => 'ldap_email_attribute', 'value' => 'mail', 'description' => 'ویژگی ایمیل کاربر در LDAP'],
            ['key' => 'ldap_name_attribute', 'value' => 'cn', 'description' => 'ویژگی نام کامل کاربر در LDAP'],
            ['key' => 'ldap_auto_create_user', 'value' => '1', 'description' => 'ایجاد خودکار حساب کاربری در اولین ورود موفق LDAP'],

            // Keycloak Dedicated Settings
            ['key' => 'keycloak_base_url', 'value' => 'https://keycloak.example.com', 'description' => 'آدرس پایه سرور Keycloak'],
            ['key' => 'keycloak_realm', 'value' => 'master', 'description' => 'نام قلمرو (Realm) در Keycloak'],
            ['key' => 'keycloak_client_id', 'value' => 'getnumber-client', 'description' => 'شناسه کلاینت ثبت شده در Keycloak (Client ID)'],
            ['key' => 'keycloak_client_secret', 'value' => '', 'description' => 'کلید محرمانه کلاینت در Keycloak (Client Secret)'],
            ['key' => 'keycloak_auto_create_user', 'value' => '1', 'description' => 'ایجاد خودکار حساب کاربری در اولین ورود موفق Keycloak'],
        ];

        foreach ($defaultSettings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'description' => $setting['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_identities');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email')) {
                $table->dropIndex(['email']);
                $table->dropColumn('email');
            }
        });
    }
};

