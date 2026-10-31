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
        // 1. Wallets Table
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 18, 4)->default(0);
            $table->string('currency', 10)->default('COIN');
            $table->timestamps();
        });

        // 2. Financial Ledger: Wallet Transactions
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_before', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->string('reference_id', 64)->unique();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // 3. System Settings (Rates, Number Price, etc.)
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->text('value');
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // Seed default initial values according to specifications:
        // 1 TRX = 1 COIN, Initial Number Price = 1 COIN
        DB::table('system_settings')->insert([
            [
                'key' => 'trx_to_coin_rate',
                'value' => '1.0000',
                'description' => 'نرخ تبدیل ترون (TRX) به واحد داخلی کوین (COIN)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'number_price_coin',
                'value' => '1.0000',
                'description' => 'قیمت پایه خرید هر خط مجازی به واحد کوین (COIN)',
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
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('system_settings');
    }
};

