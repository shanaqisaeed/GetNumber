<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Unifies all legacy and pending queued jobs into the single 'default' queue.
     */
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->where('queue', '!=', 'default')
                ->update(['queue' => 'default']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: keep unified queue
    }
};
