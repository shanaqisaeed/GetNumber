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
        Schema::table('user_identities', function (Blueprint $table) {
            if (!Schema::hasColumn('user_identities', 'password')) {
                $table->string('password')->nullable()->after('provider_id');
            }
        });

        // Migrate existing passwords from users table to their username and email identities
        $users = DB::table('users')->whereNotNull('password')->get();
        foreach ($users as $user) {
            DB::table('user_identities')
                ->where('user_id', $user->id)
                ->whereIn('provider', ['username', 'email'])
                ->whereNull('password')
                ->update(['password' => $user->password]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_identities', function (Blueprint $table) {
            if (Schema::hasColumn('user_identities', 'password')) {
                $table->dropColumn('password');
            }
        });
    }
};
