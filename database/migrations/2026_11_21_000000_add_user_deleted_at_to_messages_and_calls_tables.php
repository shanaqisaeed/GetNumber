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
        if (Schema::hasTable('messages') && ! Schema::hasColumn('messages', 'user_deleted_at')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->timestamp('user_deleted_at')->nullable()->index()->after('peer_number');
            });
        }

        if (Schema::hasTable('voice_calls') && ! Schema::hasColumn('voice_calls', 'user_deleted_at')) {
            Schema::table('voice_calls', function (Blueprint $table) {
                $table->timestamp('user_deleted_at')->nullable()->index()->after('metadata');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messages') && Schema::hasColumn('messages', 'user_deleted_at')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('user_deleted_at');
            });
        }

        if (Schema::hasTable('voice_calls') && Schema::hasColumn('voice_calls', 'user_deleted_at')) {
            Schema::table('voice_calls', function (Blueprint $table) {
                $table->dropColumn('user_deleted_at');
            });
        }
    }
};
