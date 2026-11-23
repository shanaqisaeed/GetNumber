<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_devices')) {
            Schema::create('mobile_devices', function (Blueprint $table) {
                $table->id();
                $table->string('device_uuid', 64)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('platform', 16)->index(); // ios, android, web
                $table->string('app_version', 32)->default('1.0.0');
                $table->string('os_version', 32)->nullable();
                $table->string('device_model', 64)->nullable();
                $table->string('push_provider', 32)->default('local'); // ios_local, ios_apns_voip, android_local, android_fcm
                $table->text('voip_push_token_encrypted')->nullable();
                $table->string('voip_push_token_hash', 64)->nullable()->index();
                $table->text('notification_push_token_encrypted')->nullable();
                $table->string('notification_push_token_hash', 64)->nullable()->index();
                $table->string('local_push_connection_id', 64)->nullable()->index();
                $table->boolean('local_push_connected')->default(false);
                $table->string('network_mode', 16)->default('UNKNOWN'); // PUBLIC, INTRANET, HYBRID, DEGRADED, OFFLINE
                $table->string('last_known_ip', 45)->nullable();
                $table->string('last_known_ssid', 64)->nullable();
                $table->string('status', 16)->default('active')->index(); // active, inactive, revoked, expired
                $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('revoked_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['user_id', 'platform', 'status']);
            });
        }

        if (! Schema::hasTable('call_lifecycle_events')) {
            Schema::create('call_lifecycle_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 64)->unique();
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->string('event_type', 48)->index();
                $table->string('call_uuid', 64)->index();
                $table->unsignedBigInteger('call_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('previous_state', 32)->nullable();
                $table->string('current_state', 32)->nullable();
                $table->string('actor_device_uuid', 64)->nullable();
                $table->string('correlation_id', 64)->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (Schema::hasTable('voice_calls')) {
            Schema::table('voice_calls', function (Blueprint $table) {
                if (! Schema::hasColumn('voice_calls', 'call_uuid')) {
                    $table->string('call_uuid', 64)->nullable()->after('id')->index();
                }
                if (! Schema::hasColumn('voice_calls', 'has_video')) {
                    $table->boolean('has_video')->default(false)->after('status');
                }
                if (! Schema::hasColumn('voice_calls', 'accepted_device_id')) {
                    $table->string('accepted_device_id', 64)->nullable()->after('has_video');
                }
                if (! Schema::hasColumn('voice_calls', 'end_reason')) {
                    $table->string('end_reason', 64)->nullable()->after('accepted_device_id');
                }
                if (! Schema::hasColumn('voice_calls', 'ring_timeout_at')) {
                    $table->timestamp('ring_timeout_at')->nullable()->after('started_at');
                }
                if (! Schema::hasColumn('voice_calls', 'last_pulse_at')) {
                    $table->timestamp('last_pulse_at')->nullable()->after('answered_at');
                }
                if (! Schema::hasColumn('voice_calls', 'correlation_id')) {
                    $table->string('correlation_id', 64)->nullable()->after('metadata')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('voice_calls')) {
            Schema::table('voice_calls', function (Blueprint $table) {
                $cols = [];
                foreach (['call_uuid', 'has_video', 'accepted_device_id', 'end_reason', 'ring_timeout_at', 'last_pulse_at', 'correlation_id'] as $col) {
                    if (Schema::hasColumn('voice_calls', $col)) {
                        $cols[] = $col;
                    }
                }
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        Schema::dropIfExists('call_lifecycle_events');
        Schema::dropIfExists('mobile_devices');
    }
};
