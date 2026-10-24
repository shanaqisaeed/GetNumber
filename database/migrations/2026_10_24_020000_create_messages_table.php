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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
            $table->string('destination_number')->index();
            $table->string('sender', 100)->index();
            $table->text('body');
            $table->timestamp('source_time')->nullable();
            $table->timestamp('server_received_at')->index();
            $table->string('source_system_id', 100)->nullable();
            $table->string('client_message_id', 100)->nullable();
            $table->string('processing_status', 20)->default('received')->index();
            $table->timestamps();

            // Composite unique index for idempotency
            $table->unique(['source_system_id', 'client_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
