<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conference_rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('conference_rooms', 'watermark_enabled')) {
                $table->boolean('watermark_enabled')->default(false)->after('allow_whiteboard');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conference_rooms', function (Blueprint $table) {
            if (Schema::hasColumn('conference_rooms', 'watermark_enabled')) {
                $table->dropColumn('watermark_enabled');
            }
        });
    }
};
