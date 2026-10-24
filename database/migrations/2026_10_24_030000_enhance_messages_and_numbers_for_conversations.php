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
        Schema::table('messages', function (Blueprint $table) {
            $table->string('direction', 20)->default('inbound')->after('processing_status')->index();
            $table->string('peer_number', 32)->nullable()->after('direction')->index();
        });

        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('accepts_incoming_sms')->default(true)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn('accepts_incoming_sms');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['direction', 'peer_number']);
        });
    }
};
