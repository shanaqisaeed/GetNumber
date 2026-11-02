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
    protected $description = 'Verify Nobitex API token validity and synchronize daily TRX deposit addresses';

    /**
     * Execute the console command.
     */
    public function handle(PaymentGatewayManager $gatewayManager): int
    {
        $this->info('Querying and synchronizing Nobitex gateway wallet...');

        /** @var PaymentGateway|null $gateway */
        $gateway = PaymentGateway::where('driver', 'nobitex')->first();

        if (! $gateway) {
            $this->warn('Nobitex gateway not found in payment_gateways table.');
            return Command::FAILURE;
        }

        $config = $gateway->config ?? [];
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if (empty($apiKey)) {
            $this->warn('Nobitex API token has not been configured yet by system administrator.');
            return Command::SUCCESS;
        }

        try {
            $adapter = $gatewayManager->forModel($gateway);

            // Step 1: Validate credentials
            $valResult = $adapter->validateCredentials($config);
            if (! ($valResult['valid'] ?? false)) {
                $rawMsg = $valResult['message'] ?? 'Invalid token credentials.';
                $cleanMsg = preg_match('/is not whitelisted/i', $rawMsg)
                    ? 'Server IP is not whitelisted in Nobitex API settings.'
                    : (preg_match('/[\x{0600}-\x{06FF}]/u', $rawMsg) ? 'API credentials rejected by Nobitex gateway.' : $rawMsg);
                $this->error("Nobitex token validation failed: {$cleanMsg}");
                Log::channel('single')->error('Nobitex token validation failed in cron', ['error' => $rawMsg]);
                return Command::FAILURE;
            }

            // Step 2: Sync TRX wallet deposit address
            $syncResult = $adapter->syncWallets($config);

            if (! ($syncResult['success'] ?? false)) {
                $rawMsg = $syncResult['message'] ?? 'Address not retrieved.';
                $cleanMsg = preg_match('/[\x{0600}-\x{06FF}]/u', $rawMsg) ? 'Deposit address could not be retrieved from gateway.' : $rawMsg;
                $this->error('Failed to sync TRX deposit address: ' . $cleanMsg);
                return Command::FAILURE;
            }

            $oldAddress = $gateway->config['deposit_address'] ?? null;
            $newAddress = $syncResult['address'] ?? null;
            $changed = $syncResult['changed'] ?? false;

            // Save updated config
            $gateway->update(['config' => $config]);

            if ($changed) {
                $this->warn("Nobitex TRX wallet address has changed and was updated: {$oldAddress} -> {$newAddress}");
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
                $this->info("Nobitex TRX wallet address is valid and synced. Current address: {$newAddress}");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Exception during Nobitex wallet sync execution: ' . $e->getMessage());
            Log::channel('single')->error('Exception during Nobitex sync', ['exception' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
