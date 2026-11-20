<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteAndTariffTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_call_does_not_create_zero_wallet_transaction(): void
    {
        SystemSetting::set('id_voice_rate_per_minute', '0.0000');
        SystemSetting::set('voice_call_rate_per_minute', '0.0000');

        $user = User::factory()->create();
        $user->wallet()->create([
            'balance' => '10.0000',
            'currency' => 'COIN',
        ]);

        $prefix = PhonePrefix::create([
            'prefix' => '+9821',
            'country_code' => 'IR',
            'name' => 'Tehran',
            'is_active' => true,
        ]);

        $line = PhoneNumber::create([
            'user_id' => $user->id,
            'phone_prefix_id' => $prefix->id,
            'number' => '88880001',
            'status' => PhoneNumberStatus::Allocated,
            'type' => PhoneNumberType::UserStandard,
            'allocated_at' => now(),
        ]);

        // Initiate ID-to-ID free call
        $initRes = $this->actingAs($user)->postJson(route('voice.initiate'), [
            'destination' => '@support_team',
            'phone_number_id' => $line->id,
        ]);
        $initRes->assertOk();
        $callId = $initRes->json('call.id') ?? $initRes->json('call_id');

        // End call with 120 seconds duration
        $endRes = $this->actingAs($user)->postJson(route('voice.end', ['voiceCall' => $callId]), [
            'duration_seconds' => 120,
        ]);
        $endRes->assertOk();

        $call = VoiceCall::find($callId);
        $this->assertEquals(0, bccomp((string) $call->cost, '0.0000', 4));

        // Strictly verify NO transaction was recorded in wallet for this free call
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_id' => $user->wallet->id,
            'reference_type' => VoiceCall::class,
            'reference_id' => $callId,
        ]);

        // Balance remains intact
        $this->assertEquals('10.0000', (string) $user->wallet->fresh()->balance);
    }

    public function test_user_can_soft_delete_calls_single_and_bulk(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $call1 = VoiceCall::create([
            'user_id' => $user->id,
            'source_identifier' => '1001',
            'destination' => '1002',
            'direction' => 'outbound',
            'status' => 'completed',
            'duration_seconds' => 60,
            'cost' => '0.1000',
        ]);

        $call2 = VoiceCall::create([
            'user_id' => $user->id,
            'source_identifier' => '1001',
            'destination' => '1003',
            'direction' => 'outbound',
            'status' => 'completed',
            'duration_seconds' => 30,
            'cost' => '0.0500',
        ]);

        // Single delete
        $delSingle = $this->actingAs($user)->deleteJson(route('calls.destroy', $call1->id));
        $delSingle->assertOk();

        $this->assertNotNull($call1->fresh()->user_deleted_at);

        // Bulk delete
        $delBulk = $this->actingAs($user)->postJson(route('calls.destroy_bulk'), [
            'ids' => [$call2->id],
        ]);
        $delBulk->assertOk();

        $this->assertNotNull($call2->fresh()->user_deleted_at);

        // User history index ignores soft-deleted calls
        $userView = $this->actingAs($user)->get(route('calls.index', ['tab' => 'history']));
        $userView->assertOk();
        $callsData = $userView->inertiaProps('calls.data');
        $this->assertEmpty($callsData);

        // Admin still sees all calls
        $adminView = $this->actingAs($admin)->get(route('admin.calls.index'));
        $adminView->assertOk();
        $adminCalls = $adminView->inertiaProps('calls.data');
        $this->assertCount(2, $adminCalls);
    }

    public function test_user_can_soft_delete_messages_single_bulk_and_conversation(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $prefix = PhonePrefix::create([
            'prefix' => '+9821',
            'country_code' => 'IR',
            'name' => 'Tehran',
            'is_active' => true,
        ]);

        $line = PhoneNumber::create([
            'user_id' => $user->id,
            'phone_prefix_id' => $prefix->id,
            'number' => '88880002',
            'status' => PhoneNumberStatus::Allocated,
            'type' => PhoneNumberType::UserStandard,
            'allocated_at' => now(),
        ]);

        $msg1 = Message::create([
            'phone_number_id' => $line->id,
            'sender' => '88880002',
            'destination_number' => '99990001',
            'peer_number' => '99990001',
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Processed,
            'body' => 'Hello 1',
            'server_received_at' => now(),
        ]);

        $msg2 = Message::create([
            'phone_number_id' => $line->id,
            'sender' => '99990001',
            'destination_number' => '88880002',
            'peer_number' => '99990001',
            'direction' => MessageDirection::Inbound,
            'processing_status' => MessageProcessingStatus::Received,
            'body' => 'Reply 1',
            'server_received_at' => now(),
        ]);

        // Single delete msg1
        $resSingle = $this->actingAs($user)->deleteJson(route('messages.destroy', $msg1->id));
        $resSingle->assertOk();
        $this->assertNotNull($msg1->fresh()->user_deleted_at);

        // Delete entire conversation with 99990001
        $resConv = $this->actingAs($user)->postJson(route('messages.destroy_conversation'), [
            'phone_number_id' => $line->id,
            'peer_number' => '99990001',
        ]);
        $resConv->assertOk();
        $this->assertNotNull($msg2->fresh()->user_deleted_at);

        // User poll returns 0 messages
        $pollRes = $this->actingAs($user)->getJson(route('messages.poll', [
            'phone_number_id' => $line->id,
            'peer' => '99990001',
        ]));
        $pollRes->assertOk();
        $this->assertEmpty($pollRes->json('data'));

        // Admin still sees all messages
        $adminView = $this->actingAs($admin)->get(route('admin.messages.index'));
        $adminView->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $msg1->id]);
        $this->assertDatabaseHas('messages', ['id' => $msg2->id]);
    }
}
