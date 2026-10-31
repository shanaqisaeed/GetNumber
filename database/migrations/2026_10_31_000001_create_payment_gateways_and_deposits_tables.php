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
        // 1. Payment Gateways Registry
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver')->unique(); // nobitex, tron_grid, mock
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->json('supported_currencies')->nullable(); // e.g. ["TRX", "USDT"]
            $table->json('supported_networks')->nullable(); // e.g. ["TRON", "TRC20"]
            $table->json('config')->nullable(); // api_key, base_url, wallet_id, min_deposit, etc.
            $table->timestamps();
        });

        // 2. Crypto Deposit Requests & Transactions
        Schema::create('crypto_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_gateway_id')->nullable()->constrained('payment_gateways')->nullOnDelete();
            $table->string('gateway_driver', 50)->index();
            $table->string('tracking_code', 64)->unique();
            $table->string('currency', 16)->default('TRX');
            $table->string('network', 32)->default('TRON');
            $table->decimal('requested_amount', 18, 4);
            $table->decimal('coin_amount', 18, 4);
            $table->decimal('trx_to_coin_rate', 18, 4)->default('1.0000');
            $table->string('deposit_address', 128);
            $table->string('deposit_tag', 64)->nullable();
            $table->string('tx_hash', 128)->nullable()->unique();
            $table->decimal('actual_amount', 18, 4)->nullable();
            $table->string('status', 32)->default('pending_transfer')->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_check_at']);
        });

        // Seed default gateways
        DB::table('payment_gateways')->insert([
            [
                'name' => 'صرافی نوبیتکس (Nobitex)',
                'driver' => 'nobitex',
                'is_active' => true,
                'is_default' => true,
                'supported_currencies' => json_encode(['TRX']),
                'supported_networks' => json_encode(['TRON', 'TRC20']),
                'config' => json_encode([
                    'base_url' => 'https://api.nobitex.ir',
                    'api_key' => env('NOBITEX_API_KEY', ''),
                    'wallet_id' => env('NOBITEX_WALLET_ID', null),
                    'min_deposit' => '1.0000',
                    'deposit_address_override' => env('NOBITEX_DEPOSIT_ADDRESS_OVERRIDE', ''),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'شبکه مستقیم ترون گرید (TronGrid Direct)',
                'driver' => 'tron_grid',
                'is_active' => true,
                'is_default' => false,
                'supported_currencies' => json_encode(['TRX']),
                'supported_networks' => json_encode(['TRON', 'TRC20']),
                'config' => json_encode([
                    'base_url' => 'https://api.trongrid.io',
                    'api_key' => env('TRONGRID_API_KEY', ''),
                    'deposit_address' => env('TRON_DEPOSIT_ADDRESS', ''),
                    'min_deposit' => '1.0000',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'درگاه شبیه‌ساز تست (Sandbox / Mock)',
                'driver' => 'mock',
                'is_active' => true,
                'is_default' => false,
                'supported_currencies' => json_encode(['TRX', 'USDT']),
                'supported_networks' => json_encode(['TRON', 'TRC20']),
                'config' => json_encode([
                    'auto_confirm' => false,
                    'deposit_address' => 'TTestSandboxTronAddress9999988888XX',
                    'min_deposit' => '0.5000',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_deposits');
        Schema::dropIfExists('payment_gateways');
    }
};

