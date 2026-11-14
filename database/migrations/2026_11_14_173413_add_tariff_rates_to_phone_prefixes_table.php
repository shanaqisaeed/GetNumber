<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('phone_prefixes', function (Blueprint $table) {
            $table->decimal('sms_intra_prefix_rate', 10, 4)->default(0.0500)->after('sms_price_per_segment');
            $table->decimal('sms_inter_prefix_rate', 10, 4)->default(0.1200)->after('sms_intra_prefix_rate');
            $table->decimal('voice_intra_prefix_rate', 10, 4)->default(0.1000)->after('sms_inter_prefix_rate');
            $table->decimal('voice_inter_prefix_rate', 10, 4)->default(0.2500)->after('voice_intra_prefix_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('phone_prefixes', function (Blueprint $table) {
            $table->dropColumn([
                'sms_intra_prefix_rate',
                'sms_inter_prefix_rate',
                'voice_intra_prefix_rate',
                'voice_inter_prefix_rate',
            ]);
        });
    }
};
