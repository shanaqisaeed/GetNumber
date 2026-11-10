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
        // 1. Message Batches (Bulk SMS Campaigns Management)
        Schema::create('message_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
            $table->string('sender', 32);
            $table->text('body');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 32)->default('pending')->index(); // pending, processing, completed, partially_failed, failed, cancelled
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['phone_number_id', 'created_at']);
        });

        // 2. Message Batch Items (Campaign Destination Items with Resume & Idempotency)
        Schema::create('message_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_batch_id')->constrained('message_batches')->cascadeOnDelete();
            $table->string('destination', 32)->index();
            $table->string('status', 32)->default('pending')->index(); // pending, processing, sent, failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('error_reason', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['message_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_batch_items');
        Schema::dropIfExists('message_batches');
    }
};

