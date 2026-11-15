<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only WebRTC signaling mailbox.
 *
 * Replaces the previous Cache read-modify-write queue, which silently lost
 * SDP offers/answers and ICE candidates when several signals were posted
 * concurrently (onicecandidate fires many requests in parallel).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_call_signals')) {
            Schema::create('voice_call_signals', function (Blueprint $table) {
                $table->id();
                // The call leg that must RECEIVE this signal.
                $table->foreignId('voice_call_id')->constrained('voice_calls')->cascadeOnDelete();
                // The call leg that SENT this signal.
                $table->unsignedBigInteger('sender_call_id')->nullable();
                $table->string('type', 32);
                $table->longText('payload');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['voice_call_id', 'id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_call_signals');
    }
};

