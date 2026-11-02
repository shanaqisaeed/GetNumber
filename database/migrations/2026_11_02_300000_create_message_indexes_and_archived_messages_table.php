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
        // 1. High-Performance Composite Indexes on `messages` table
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['phone_number_id', 'peer_number', 'id'], 'idx_messages_phone_peer_id');
            $table->index(['phone_number_id', 'direction', 'processing_status'], 'idx_messages_phone_dir_status');
            $table->index(['phone_number_id', 'created_at'], 'idx_messages_phone_created');
        });

        // 2. Composite Index on `phone_numbers` table
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'idx_phone_numbers_user_status');
        });

        // 3. Cold Storage / Data Retention Table: `archived_messages`
        Schema::create('archived_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // Preserves original message ID
            $table->unsignedBigInteger('phone_number_id')->index();
            $table->string('destination_number')->index();
            $table->string('sender', 100)->index();
            $table->string('peer_number', 32)->nullable()->index();
            $table->text('body');
            $table->string('direction', 20)->default('inbound')->index();
            $table->string('processing_status', 20)->default('received')->index();
            $table->timestamp('source_time')->nullable();
            $table->timestamp('server_received_at')->index();
            $table->string('source_system_id', 100)->nullable();
            $table->string('client_message_id', 100)->nullable();
            $table->timestamp('original_created_at')->nullable()->index();
            $table->timestamp('original_updated_at')->nullable();
            $table->timestamp('archived_at')->useCurrent()->index();

            $table->index(['phone_number_id', 'peer_number', 'id'], 'idx_archived_phone_peer_id');
            $table->index(['phone_number_id', 'original_created_at'], 'idx_archived_phone_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archived_messages');

        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropIndex('idx_phone_numbers_user_status');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_phone_peer_id');
            $table->dropIndex('idx_messages_phone_dir_status');
            $table->dropIndex('idx_messages_phone_created');
        });
    }
};

