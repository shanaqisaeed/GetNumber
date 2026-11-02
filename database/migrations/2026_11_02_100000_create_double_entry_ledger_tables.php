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
        // 1. Chart of Accounts (سرفصل‌های دفاتر کل حسابداری)
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 128);
            $table->string('type', 32)->index(); // asset, liability, equity, revenue, expense
            $table->string('currency', 10)->default('COIN');
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        // 2. Journal Entries (دفتر روزنامه - اسناد مالی)
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 64)->unique();
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('currency', 10)->default('COIN');
            $table->string('description', 255);
            $table->timestamp('posted_at')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index('posted_at');
        });

        // 3. Ledger Entries (سطرهای بدهکار و بستانکار دفتر کل)
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['ledger_account_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['journal_entry_id']);
        });

        // Seed default system ledger accounts
        DB::table('ledger_accounts')->insert([
            [
                'code' => '1010_PLATFORM_TREASURY',
                'name' => 'موجودی خزانه و درگاه‌های پرداخت',
                'type' => 'asset',
                'currency' => 'COIN',
                'description' => 'دارایی‌های نقدی و ارزی ناشی از واریزی‌های کریپتو و صرافی‌ها',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '2010_USER_WALLETS',
                'name' => 'بدهی به کاربران (کیف پول‌ها)',
                'type' => 'liability',
                'currency' => 'COIN',
                'description' => 'تعهدات پلتفرم به کاربران بابت مانده موجودی کیف پول‌ها',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '4010_NUMBER_REVENUE',
                'name' => 'درآمد فروش و واگذاری شماره مجازی',
                'type' => 'revenue',
                'currency' => 'COIN',
                'description' => 'درآمدهای عملیاتی حاصل از کسر اعتبار جهت خرید و تمدید خطوط',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '5010_REFUND_EXPENSE',
                'name' => 'بازگشت وجوه تراکنش‌های لغوشده',
                'type' => 'expense',
                'currency' => 'COIN',
                'description' => 'هزینه‌های استرداد وجه در صورت شکست خرید خط یا لغو سفارش',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '9010_SUSPENSE',
                'name' => 'حساب واسط و تعدیلات مدیریتی',
                'type' => 'equity',
                'currency' => 'COIN',
                'description' => 'حساب واسط جهت ثبت تراکنش‌های شارژ و کسر دستی توسط مدیران',
                'is_system' => true,
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
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};

