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
        Schema::create('system_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('error_hash', 40)->index();
            $table->string('source', 32)->default('backend'); // backend, frontend, queue
            $table->string('level', 16)->default('error'); // info, warning, error, critical
            $table->string('exception_class', 128)->nullable();
            $table->text('message');
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->longText('stack_trace')->nullable();
            $table->text('url')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->unsignedInteger('occurrences_count')->default(1);
            $table->timestamp('last_seen_at')->useCurrent()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source', 'last_seen_at']);
            $table->index(['level', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_error_logs');
    }
};

