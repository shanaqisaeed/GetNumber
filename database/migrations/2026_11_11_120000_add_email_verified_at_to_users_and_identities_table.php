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
        // 1. Add email_verified_at column to users table if not exists
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
        });

        // 2. Add verified_at to user_identities table if not exists
        Schema::table('user_identities', function (Blueprint $table) {
            if (!Schema::hasColumn('user_identities', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('provider_email');
            }
        });

        // 3. Mark existing admin or active users as verified so existing accounts are not locked out
        DB::table('users')->whereNotNull('email')->whereNull('email_verified_at')->update([
            'email_verified_at' => now(),
        ]);

        DB::table('user_identities')->where('provider', 'email')->whereNull('verified_at')->update([
            'verified_at' => now(),
        ]);

        // 4. Seed system settings for SMTP configuration & verification requirement
        $smtpSettings = [
            ['key' => 'mail_mailer', 'value' => 'smtp', 'description' => 'درایور ارسال ایمیل سامانه (پیش‌فرض smtp)'],
            ['key' => 'mail_host', 'value' => '127.0.0.1', 'description' => 'آدرس سرور SMTP'],
            ['key' => 'mail_port', 'value' => '587', 'description' => 'پورت اتصال به سرور SMTP'],
            ['key' => 'mail_username', 'value' => '', 'description' => 'نام کاربری یا ایمیل احراز هویت سرور SMTP'],
            ['key' => 'mail_password', 'value' => '', 'description' => 'رمز عبور احراز هویت سرور SMTP'],
            ['key' => 'mail_encryption', 'value' => 'tls', 'description' => 'نوع رمزنگاری ارتباط SMTP (tls, ssl, none)'],
            ['key' => 'mail_from_address', 'value' => 'noreply@getnumber.local', 'description' => 'آدرس فرستنده ایمیل‌های ارسالی سامانه'],
            ['key' => 'mail_from_name', 'value' => 'سامانه مدیریت شماره‌ها GetNumber', 'description' => 'نام نمایشی فرستنده در ایمیل‌های ارسالی'],
            ['key' => 'require_email_verification', 'value' => '1', 'description' => 'الزام تایید آدرس ایمیل برای دسترسی به بخش‌های سامانه'],
        ];

        foreach ($smtpSettings as $setting) {
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
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }
        });

        Schema::table('user_identities', function (Blueprint $table) {
            if (Schema::hasColumn('user_identities', 'verified_at')) {
                $table->dropColumn('verified_at');
            }
        });
    }
};

