<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conference_rooms')) {
            Schema::create('conference_rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('room_uuid', 64)->unique('conf_rooms_uuid_unq');
                $table->string('title');
                $table->string('pin_code', 16)->nullable();

                // Room policies
                $table->string('admission_policy', 32)->default('approval'); // 'open', 'approval'
                $table->string('initial_audio_state', 32)->default('muted'); // 'open', 'muted'
                $table->boolean('webinar_mode')->default(false);
                $table->string('screen_share_policy', 32)->default('by_permission'); // 'everyone', 'host_only', 'by_permission'
                $table->boolean('mute_others_on_share')->default(true);
                $table->boolean('allow_whiteboard')->default(true);
                $table->boolean('is_locked')->default(false);
                $table->unsignedInteger('max_participants')->default(50);
                $table->boolean('is_active')->default(true);

                // Lifecycle & Scheduling
                $table->timestamp('scheduled_start_at')->nullable();
                $table->timestamp('scheduled_end_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['created_by_user_id', 'is_active'], 'conf_rooms_creator_active_idx');
                $table->index(['organization_id', 'is_active'], 'conf_rooms_org_active_idx');
                $table->index(['expires_at', 'is_active'], 'conf_rooms_expires_active_idx');
            });
        }

        if (! Schema::hasTable('conference_participants')) {
            Schema::create('conference_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conference_room_id')->constrained('conference_rooms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('phone_number', 32);
                $table->string('display_name');
                $table->string('status', 32)->default('waiting_approval'); // 'waiting_approval', 'admitted', 'rejected', 'left', 'kicked'
                $table->string('role', 32)->default('participant'); // 'host', 'co_host', 'participant'
                $table->boolean('is_muted')->default(false);
                $table->boolean('is_camera_on')->default(false);
                $table->boolean('can_share_screen')->default(false);
                $table->boolean('is_sharing_screen')->default(false);
                $table->boolean('hand_raised')->default(false);
                $table->timestamp('hand_raised_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('joined_at')->useCurrent();
                $table->timestamp('left_at')->nullable();
                $table->timestamps();

                $table->index(['conference_room_id', 'status'], 'conf_part_room_status_idx');
                $table->index(['conference_room_id', 'phone_number', 'status'], 'conf_part_room_phone_status_idx');
                $table->index(['conference_room_id', 'user_id', 'status'], 'conf_part_room_user_status_idx');
            });
        } else {
            // Recovery if partial MySQL migration created table before index length error
            Schema::table('conference_participants', function (Blueprint $table) {
                if (! Schema::hasIndex('conference_participants', 'conf_part_room_phone_status_idx')) {
                    $table->index(['conference_room_id', 'phone_number', 'status'], 'conf_part_room_phone_status_idx');
                }
                if (! Schema::hasIndex('conference_participants', 'conf_part_room_user_status_idx')) {
                    $table->index(['conference_room_id', 'user_id', 'status'], 'conf_part_room_user_status_idx');
                }
            });
        }

        if (! Schema::hasTable('conference_messages')) {
            Schema::create('conference_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conference_room_id')->constrained('conference_rooms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('message');
                $table->timestamps();

                $table->index(['conference_room_id', 'created_at'], 'conf_msg_room_created_idx');
                $table->index(['conference_room_id', 'to_user_id'], 'conf_msg_room_to_user_idx');
            });
        }

        if (! Schema::hasTable('conference_polls')) {
            Schema::create('conference_polls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conference_room_id')->constrained('conference_rooms')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('question');
                $table->json('options');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['conference_room_id', 'is_active'], 'conf_polls_room_active_idx');
            });
        }

        if (! Schema::hasTable('conference_poll_votes')) {
            Schema::create('conference_poll_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conference_poll_id')->constrained('conference_polls')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('option_index');
                $table->timestamps();

                $table->unique(['conference_poll_id', 'user_id'], 'conf_poll_votes_poll_user_unq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_poll_votes');
        Schema::dropIfExists('conference_polls');
        Schema::dropIfExists('conference_messages');
        Schema::dropIfExists('conference_participants');
        Schema::dropIfExists('conference_rooms');
    }
};
