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
        Schema::table('number_requests', function (Blueprint $table) {
            $table->boolean('accepts_incoming_sms')->default(true)->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('number_requests', function (Blueprint $table) {
            $table->dropColumn('accepts_incoming_sms');
        });
    }
};
