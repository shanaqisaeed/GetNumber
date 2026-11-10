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
            $table->foreignId('phone_prefix_id')
                ->nullable()
                ->after('user_id')
                ->constrained('phone_prefixes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('number_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('phone_prefix_id');
        });
    }
};

