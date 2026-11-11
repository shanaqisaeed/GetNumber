<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_prefixes', function (Blueprint $table) {
            if (! Schema::hasColumn('phone_prefixes', 'sms_price_per_segment')) {
                $table->decimal('sms_price_per_segment', 18, 4)
                    ->default('0.0500')
                    ->after('price_golden_rond')
                    ->comment('Outbound SMS price per segment for this prefix in COIN/TRX');
            }
            if (! Schema::hasColumn('phone_prefixes', 'inbound_sms_price')) {
                $table->decimal('inbound_sms_price', 18, 4)
                    ->default('0.0000')
                    ->after('sms_price_per_segment')
                    ->comment('Inbound SMS price per segment for this prefix in COIN/TRX');
            }
        });

        // Seed realistic prefix-specific SMS tariffs if prefixes exist
        if (Schema::hasTable('phone_prefixes')) {
            DB::table('phone_prefixes')->where('clean_prefix', '85')->update([
                'sms_price_per_segment' => '0.0500',
                'inbound_sms_price' => '0.0000',
            ]);
            DB::table('phone_prefixes')->where('clean_prefix', '98')->update([
                'sms_price_per_segment' => '0.0400',
                'inbound_sms_price' => '0.0000',
            ]);
            DB::table('phone_prefixes')->where('clean_prefix', '90')->update([
                'sms_price_per_segment' => '0.0650',
                'inbound_sms_price' => '0.0000',
            ]);
            DB::table('phone_prefixes')->where('clean_prefix', '971')->update([
                'sms_price_per_segment' => '0.0800',
                'inbound_sms_price' => '0.0000',
            ]);
            DB::table('phone_prefixes')->where('clean_prefix', '44')->update([
                'sms_price_per_segment' => '0.0750',
                'inbound_sms_price' => '0.0000',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('phone_prefixes', function (Blueprint $table) {
            if (Schema::hasColumn('phone_prefixes', 'inbound_sms_price')) {
                $table->dropColumn('inbound_sms_price');
            }
            if (Schema::hasColumn('phone_prefixes', 'sms_price_per_segment')) {
                $table->dropColumn('sms_price_per_segment');
            }
        });
    }
};

