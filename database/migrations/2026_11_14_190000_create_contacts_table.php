<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('phone_number', 64);
                $table->string('avatar_color', 32)->default('indigo');
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['user_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

