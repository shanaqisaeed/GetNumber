<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PaymentGateway;
use App\Services\Gateways\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNobitexWalletCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:nobitex:sync-wallet';

    /**
     * The console command description.
     */
    protected $description = 'بررسی وضعیت اعتبار توکن نوبیتکس و همگام‌سازی روزانه آدرس کیف‌پول TRX در پایگاه‌داده';

    /**
     * Execute the console command.
     */
    public function handle(PaymentGatewayManager $gatewayManager): int
    {
        $this->info('در حال استعلام و همگام‌سازی کیف‌پول نوبیتکس...');

        /** @var PaymentGateway|null $gateway */
        $gateway = PaymentGateway::where('driver', 'nobitex')->first();

        if (! $gateway) {
            $this->warn('درگاه نوبیتکس در جدول payment_gateways یافت نشد.');
            return Command::FAILURE;
        }

        $config = $gateway->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if (empty($apiKey)) {
            $this->warn('توکن یا کلید API نوبیتکس هنوز توسط مدیر سامانه تنظیم نشده است.');
            return Command::SUCCESS;
        }

        try {
            $adapter = $gatewayManager->forModel($gateway);

            // Step 1: Validate credentials
            $valResult = $adapter->validateCredentials($config);
            if (! ($valResult['valid'] ?? false)) {
                $errMsg = $valResult['message'] ?? 'توکن نامعتبر است.';
                $this->error("اعتبارسنجی توکن نوبیتکس رد شد: {$errMsg}");
                Log::channel('single')->error('Nobitex token validation failed in cron', ['error' => $errMsg]);
                return Command::FAILURE;
            }

            // Step 2: Sync TRX wallet deposit address
            $syncResult = $adapter->syncWallets($config);

            if (! ($syncResult['success'] ?? false)) {
                $this->error('خطا در همگام‌سازی آدرس TRX: ' . ($syncResult['message'] ?? 'آدرس دریافت نشد.'));
                return Command::FAILURE;
            }

            $oldAddress = $gateway->config['deposit_address'] ?? null;
            $newAddress = $syncResult['address'] ?? null;
            $changed = $syncResult['changed'] ?? false;

            // Save updated config
            $gateway->update(['config' => $config]);

            if ($changed) {
                $this->warn("آدرس کیف پول TRX تغییر کرده بود و به‌روزرسانی شد: {$oldAddress} -> {$newAddress}");
                Log::channel('single')->warning("Nobitex TRX wallet address updated: {$oldAddress} -> {$newAddress}");

                AuditLog::create([
                    'user_id' => null,
                    'action' => 'cron.nobitex.wallet_updated',
                    'entity_type' => PaymentGateway::class,
                    'entity_id' => $gateway->id,
                    'payload' => [
                        'old_address' => $oldAddress,
                        'new_address' => $newAddress,
                    ],
                    'ip_address' => '127.0.0.1',
                ]);
            } else {
                $this->info("کیف‌پول نوبیتکس TRX معتبر و همگام است. آدرس جاری: {$newAddress}");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('خطا در اجرای فرآیند همگام‌سازی نوبیتکس: ' . $e->getMessage());
            Log::channel('single')->error('Exception during Nobitex sync', ['exception' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}

