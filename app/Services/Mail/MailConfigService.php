<?php

namespace App\Services\Mail;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class MailConfigService
{
    /**
     * Dynamically apply SMTP settings from SystemSetting to Laravel runtime configuration.
     */
    public static function applySettings(): void
    {
        try {
            $mailer = SystemSetting::get('mail_mailer', 'smtp');
            $host = SystemSetting::get('mail_host');
            $port = (int) SystemSetting::get('mail_port', 587);
            $username = SystemSetting::get('mail_username');
            $password = SystemSetting::get('mail_password');
            $encryption = SystemSetting::get('mail_encryption', 'tls');
            $fromAddress = SystemSetting::get('mail_from_address', config('mail.from.address'));
            $fromName = SystemSetting::get('mail_from_name', config('mail.from.name'));

            if (!empty($host)) {
                Config::set('mail.default', $mailer);
                Config::set('mail.mailers.smtp.transport', 'smtp');
                Config::set('mail.mailers.smtp.host', $host);
                Config::set('mail.mailers.smtp.port', $port);
                Config::set('mail.mailers.smtp.encryption', $encryption !== 'none' ? $encryption : null);
                Config::set('mail.mailers.smtp.username', !empty($username) ? $username : null);
                Config::set('mail.mailers.smtp.password', !empty($password) ? $password : null);
                Config::set('mail.mailers.smtp.timeout', 15);
            }

            if (!empty($fromAddress)) {
                Config::set('mail.from.address', $fromAddress);
                Config::set('mail.from.name', $fromName ?: config('app.name'));
            }
        } catch (\Throwable) {
            // Fallback gracefully during early boot or migration runs
        }
    }

    /**
     * Test SMTP connection and optionally send a test email.
     */
    public static function testConnection(?string $recipient = null): array
    {
        self::applySettings();

        $host = Config::get('mail.mailers.smtp.host');
        $port = Config::get('mail.mailers.smtp.port', 587);
        $username = Config::get('mail.mailers.smtp.username');
        $password = Config::get('mail.mailers.smtp.password');
        $encryption = Config::get('mail.mailers.smtp.encryption');

        try {
            // First perform low-level socket probe to verify reachability
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 5);
            if (!$socket) {
                return [
                    'success' => false,
                    'error' => "Cannot connect to {$host}:{$port} ({$errstr} - code {$errno})",
                ];
            }
            fclose($socket);

            // If recipient provided, attempt sending a test message
            if (!empty($recipient)) {
                Mail::raw(__('controllers.admin.settings.smtp_test_body', ['time' => now()->toDateTimeString()]), function ($message) use ($recipient) {
                    $message->to($recipient)
                        ->subject(__('controllers.admin.settings.smtp_test_subject'));
                });
            }

            return [
                'success' => true,
                'message' => __('controllers.admin.settings.smtp_test_ok'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

