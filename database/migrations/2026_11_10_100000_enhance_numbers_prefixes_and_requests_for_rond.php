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
        // 1. Enhance phone_prefixes table
        Schema::table('phone_prefixes', function (Blueprint $table) {
            $table->string('country_name', 100)->nullable()->after('clean_prefix');
            $table->string('flag', 32)->nullable()->after('country_code');
            $table->json('sub_prefixes')->nullable()->after('flag');
            $table->decimal('price_regular', 18, 4)->default(1.0000)->after('sub_prefixes');
            $table->decimal('price_semi_rond', 18, 4)->default(5.0000)->after('price_regular');
            $table->decimal('price_golden_rond', 18, 4)->default(20.0000)->after('price_semi_rond');
        });

        // Update existing default prefixes with country info and sub-prefixes
        DB::table('phone_prefixes')->where('clean_prefix', '85')->update([
            'country_name' => 'بین‌المللی (ویژه)',
            'country_code' => 'INTL',
            'flag' => '🌐',
            'sub_prefixes' => json_encode(['850', '851', '855', '858']),
            'price_regular' => 1.0000,
            'price_semi_rond' => 5.0000,
            'price_golden_rond' => 20.0000,
        ]);

        // Update Iran prefix (+98) if present in database
        DB::table('phone_prefixes')->where('clean_prefix', '98')->update([
            'country_name' => 'ایران',
            'country_code' => 'IR',
            'flag' => '🇮🇷',
            'sub_prefixes' => json_encode(['912', '910', '918', '919', '935', '936', '937', '938', '939', '990']),
            'price_regular' => 1.0000,
            'price_semi_rond' => 5.0000,
            'price_golden_rond' => 20.0000,
        ]);

        // 2. Enhance phone_numbers table
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('rond_tier', 20)->default('regular')->index()->after('status'); // regular, semi_rond, golden_rond
            $table->string('rond_pattern', 100)->nullable()->after('rond_tier');
        });

        // 3. Enhance number_requests table
        Schema::table('number_requests', function (Blueprint $table) {
            $table->decimal('held_amount', 18, 4)->default(0)->after('status');
            $table->string('hold_reference_id', 64)->nullable()->index()->after('held_amount');
            $table->boolean('is_rond')->default(false)->after('hold_reference_id');
            $table->string('rond_tier', 20)->default('regular')->after('is_rond');
        });

        // 4. Seed Double-Entry Ledger Account for held funds if not present
        $heldAccountExists = DB::table('ledger_accounts')->where('code', '2020_FUNDS_ON_HOLD')->exists();
        if (! $heldAccountExists) {
            DB::table('ledger_accounts')->insert([
                'code' => '2020_FUNDS_ON_HOLD',
                'name' => 'وجوه امانی و مسدودی درخواست‌های شماره',
                'type' => 'liability',
                'currency' => 'COIN',
                'description' => 'تعهدات موقت پلتفرم بابت مبالغ بلوکه‌شده سفارشات سرشماره‌های سازمانی',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Seed System Settings for 4-8 digit Organizational Number Pricing
        $orgPricing = [
            'org_price_4_regular' => ['value' => '100.0000', 'desc' => 'تعرفه سرشماره ۴ رقمی سازمانی - عادی'],
            'org_price_4_rond' => ['value' => '250.0000', 'desc' => 'تعرفه سرشماره ۴ رقمی سازمانی - رند'],
            'org_price_5_regular' => ['value' => '50.0000', 'desc' => 'تعرفه سرشماره ۵ رقمی سازمانی - عادی'],
            'org_price_5_rond' => ['value' => '120.0000', 'desc' => 'تعرفه سرشماره ۵ رقمی سازمانی - رند'],
            'org_price_6_regular' => ['value' => '20.0000', 'desc' => 'تعرفه سرشماره ۶ رقمی سازمانی - عادی'],
            'org_price_6_rond' => ['value' => '50.0000', 'desc' => 'تعرفه سرشماره ۶ رقمی سازمانی - رند'],
            'org_price_7_regular' => ['value' => '10.0000', 'desc' => 'تعرفه سرشماره ۷ رقمی سازمانی - عادی'],
            'org_price_7_rond' => ['value' => '25.0000', 'desc' => 'تعرفه سرشماره ۷ رقمی سازمانی - رند'],
            'org_price_8_regular' => ['value' => '5.0000', 'desc' => 'تعرفه سرشماره ۸ رقمی سازمانی - عادی'],
            'org_price_8_rond' => ['value' => '12.0000', 'desc' => 'تعرفه سرشماره ۸ رقمی سازمانی - رند'],
        ];

        foreach ($orgPricing as $key => $meta) {
            if (! DB::table('system_settings')->where('key', $key)->exists()) {
                DB::table('system_settings')->insert([
                    'key' => $key,
                    'value' => $meta['value'],
                    'description' => $meta['desc'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('number_requests', function (Blueprint $table) {
            $table->dropColumn(['held_amount', 'hold_reference_id', 'is_rond', 'rond_tier']);
        });

        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropIndex(['rond_tier']);
            $table->dropColumn(['rond_tier', 'rond_pattern']);
        });

        Schema::table('phone_prefixes', function (Blueprint $table) {
            $table->dropColumn([
                'country_name',
                'flag',
                'sub_prefixes',
                'price_regular',
                'price_semi_rond',
                'price_golden_rond',
            ]);
        });

        DB::table('ledger_accounts')->where('code', '2020_FUNDS_ON_HOLD')->delete();
    }
};
