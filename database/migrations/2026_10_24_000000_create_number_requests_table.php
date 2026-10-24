<?php

use App\Enums\NumberRequestStatus;
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
        Schema::create('number_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('requested_length'); // 4 to 8
            $table->string('service_title', 100);
            $table->text('description');
            $table->string('preferred_number', 32)->nullable();
            $table->string('status', 32)->default(NumberRequestStatus::Pending->value);
            $table->text('rejection_reason')->nullable();
            $table->foreignId('allocated_number_id')->nullable()->constrained('phone_numbers')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('number_requests');
    }
};
