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
        // 1. Create phone_prefixes table
        Schema::create('phone_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 12)->unique(); // e.g. '+85', '+98'
            $table->string('clean_prefix', 12)->index(); // e.g. '85', '98'
            $table->string('name', 100); // e.g. 'پیش‌شماره سازمانی راوی نو'
            $table->string('country_code', 10)->nullable(); // e.g. 'INTL', 'IR'
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // 2. Insert the initial default prefix (+85)
        $defaultPrefixId = DB::table('phone_prefixes')->insertGetId([
            'prefix' => '+85',
            'clean_prefix' => '85',
            'name' => 'پیش‌شماره سازمانی پیش‌فرض',
            'country_code' => 'INTL',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Enhance phone_numbers table with dynamic prefix and lifecycle timestamps
        Schema::table('phone_numbers', function (Blueprint $table) use ($defaultPrefixId) {
            $table->foreignId('phone_prefix_id')
                ->nullable()
                ->after('id')
                ->constrained('phone_prefixes')
                ->nullOnDelete();

            $table->timestamp('reserved_until')->nullable()->after('allocated_at');
            $table->timestamp('expires_at')->nullable()->after('reserved_until');

            $table->index(['phone_prefix_id', 'number']);
            $table->index('expires_at');
            $table->index('reserved_until');
        });

        // 4. Associate any existing phone numbers with the default prefix
        DB::table('phone_numbers')->whereNull('phone_prefix_id')->update([
            'phone_prefix_id' => $defaultPrefixId,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropForeign(['phone_prefix_id']);
            $table->dropIndex(['phone_prefix_id', 'number']);
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['reserved_until']);
            $table->dropColumn(['phone_prefix_id', 'reserved_until', 'expires_at']);
        });

        Schema::dropIfExists('phone_prefixes');
    }
};
