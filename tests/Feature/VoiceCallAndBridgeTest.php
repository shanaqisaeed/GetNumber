<?php

namespace Tests\Feature;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceCallAndBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('voice_bridge_enabled', false); // Test offline fallback mode
        SystemSetting::set('voice_call_rate_per_minute', 0.1000);
        SystemSetting::set('voice_intra_prefix_rate', 0.1000);
        SystemSetting::set('voice_inter_prefix_rate', 0.2500);
        SystemSetting::set('id_voice_rate_per_minute', 0.0500);
    }

    public function test_user_can_access_calls_index(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->wallet()->create(['balance' => '5.0000']);

        $response = $this->actingAs($user)->get(route('calls.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('User/Calls/Index'));
    }

    public function test_user_can_access_contacts_picker_json(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        Contact::create([
            'user_id' => $user->id,
            'name' => 'سعید رضایی',
            'phone_number' => '+853000',
            'avatar_color' => 'indigo',
            'notes' => 'تیم فنی',
        ]);

        $response = $this->actingAs($user)->getJson(route('contacts.picker'));

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'contacts' => [
                ['id', 'name', 'phone_number', 'avatar_color', 'notes'],
            ],
            'count',
        ]);
        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('contacts.0.name', 'سعید رضایی');
    }

    public function test_user_can_initiate_and_end_voice_call_with_wallet_debit(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $wallet = $user->wallet()->create(['balance' => '10.0000']);

        $line = PhoneNumber::create([
            'number' => '100200',
            'formatted_number' => '+85100200',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
        ]);

        // Initiate call
        $initResponse = $this->actingAs($user)->postJson(route('voice.initiate'), [
            'phone_number_id' => $line->id,
            'destination' => '+85999000',
        ]);

        $initResponse->assertOk();
        $initResponse->assertJsonPath('success', true);
        $callId = $initResponse->json('call.id');

        $this->assertDatabaseHas('voice_calls', [
            'id' => $callId,
            'user_id' => $user->id,
            'status' => 'in_call',
        ]);

        // End call with 120 seconds duration (2 minutes)
        $endResponse = $this->actingAs($user)->postJson(route('voice.end', $callId), [
            'duration_seconds' => 120,
        ]);

        $endResponse->assertOk();
        $endResponse->assertJsonPath('success', true);
        $endResponse->assertJsonPath('call.duration_seconds', 120);
        $endResponse->assertJsonPath('call.billed_minutes', 2);

        $this->assertDatabaseHas('voice_calls', [
            'id' => $callId,
            'status' => 'completed',
            'duration_seconds' => 120,
        ]);

        // Wallet was debited
        $wallet->refresh();
        $this->assertTrue(bccomp((string) $wallet->balance, '10.0000', 4) < 0);
    }

    public function test_admin_can_view_calls_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->get(route('admin.calls.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Calls/Index'));
    }

    public function test_admin_can_update_voice_bridge_settings_with_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->post(route('admin.settings.voice_bridge.update'), [
            'host' => '192.168.1.100',
            'api_port' => 49000,
            'ws_port' => 49001,
            'secret' => 'a1b2c3d4e5f678901234567890abcdef',
            'enabled' => true,
        ]);

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'bridge']));
        $this->assertEquals('192.168.1.100', SystemSetting::get('voice_bridge_host'));
        $this->assertEquals(49000, (int) SystemSetting::get('voice_bridge_api_port'));
        $this->assertEquals(49001, (int) SystemSetting::get('voice_bridge_ws_port'));
        $this->assertEquals('a1b2c3d4e5f678901234567890abcdef', SystemSetting::get('voice_bridge_secret'));
        $this->assertTrue((bool) (int) SystemSetting::get('voice_bridge_enabled'));
    }

    public function test_admin_can_set_low_ports_like_port_25(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->post(route('admin.settings.voice_bridge.update'), [
            'host' => '127.0.0.1',
            'api_port' => 25,
            'ws_port' => 80,
            'secret' => 'custom-secret-key',
            'enabled' => true,
        ]);

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'bridge']));
        $this->assertEquals(25, (int) SystemSetting::get('voice_bridge_api_port'));
        $this->assertEquals(80, (int) SystemSetting::get('voice_bridge_ws_port'));
    }

    public function test_dynamic_tariff_is_resolved_from_prefix_and_system_settings(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->wallet()->create(['balance' => '0.0000']); // Zero balance

        $prefix = \App\Models\PhonePrefix::updateOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'Prefix 85',
                'country_name' => 'Internal',
                'flag' => '🌐',
                'voice_intra_prefix_rate' => '0.0000', // Free intra
                'voice_inter_prefix_rate' => '0.2500',
                'is_active' => true,
            ]
        );

        $line = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '100300',
            'formatted_number' => '+85100300',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
        ]);

        // 1. Intra-prefix call with 0.0000 tariff should succeed even with zero balance
        $freeCallRes = $this->actingAs($user)->postJson(route('voice.initiate'), [
            'phone_number_id' => $line->id,
            'destination' => '+85100400',
        ]);
        $freeCallRes->assertOk();
        $freeCallRes->assertJsonPath('call.rate_per_minute', 0);

        // 2. Inter-prefix call with 0.2500 tariff should fail with insufficient balance
        $paidCallRes = $this->actingAs($user)->postJson(route('voice.initiate'), [
            'phone_number_id' => $line->id,
            'destination' => '+989120000000',
        ]);
        $paidCallRes->assertStatus(422);
        $paidCallRes->assertJsonPath('success', false);
        $this->assertStringContainsString('کافی نمی‌باشد', $paidCallRes->json('message'));
    }

    public function test_calling_short_extension_and_custom_id_without_sql_errors(): void
    {
        $userA = User::factory()->create(['role' => UserRole::User]);
        $userA->wallet()->create(['balance' => '10.0000']);

        $userB = User::factory()->create(['role' => UserRole::User]);
        $userB->wallet()->create(['balance' => '10.0000']);

        $prefix = \App\Models\PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'Prefix 85',
                'country_name' => 'Internal',
                'flag' => '🌐',
                'voice_intra_prefix_rate' => '0.0000',
                'voice_inter_prefix_rate' => '0.2500',
                'is_active' => true,
            ]
        );

        $lineA = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '1010',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userA->id,
            'allocated_at' => now(),
        ]);

        $lineB = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '1000',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userB->id,
            'allocated_at' => now(),
        ]);

        // 1. Dial short extension '1000'
        $call1000 = $this->actingAs($userA)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => '1000',
        ]);
        $call1000->assertOk();
        $call1000->assertJsonPath('success', true);
        $call1000->assertJsonPath('call.is_internal', true);

        // Verify callee received incoming call
        $incomingRes = $this->actingAs($userB)->getJson(route('voice.active_incoming'));
        $incomingRes->assertOk();
        $incomingRes->assertJsonPath('has_incoming', true);

        // 2. Dial with prefix '851000'
        $call851000 = $this->actingAs($userA)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => '851000',
        ]);
        $call851000->assertOk();
        $call851000->assertJsonPath('success', true);

        // 3. Dial Custom ID allocation
        $def = \App\Models\ServiceDefinition::create([
            'key' => 'custom_id',
            'driver_class' => 'App\\Services\\Catalog\\Drivers\\CustomIdDriver',
            'category' => 'identity',
            'channel_type' => 'custom_id',
            'translation_prefix' => 'services.custom_id',
            'is_active' => true,
            'is_purchasable' => true,
            'requires_approval_for_enterprise' => false,
            'available_for_roles' => ['user', 'admin'],
            'sort_order' => 1,
        ]);

        $cidAlloc = \App\Models\ServiceResourceAllocation::create([
            'service_definition_id' => $def->id,
            'user_id' => $userB->id,
            'service_key' => 'custom_id',
            'channel_type' => 'custom_id',
            'resource_identifier' => '@saeed',
            'status' => 'active',
            'allocated_at' => now(),
        ]);

        $callCid = $this->actingAs($userA)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => '@saeed',
        ]);
        $callCid->assertOk();
        $callCid->assertJsonPath('success', true);
        $callCid->assertJsonPath('call.destination', '@saeed');
    }

    public function test_webrtc_signaling_and_mutual_hangup_between_peers(): void
    {
        $userA = User::factory()->create(['role' => UserRole::User]);
        $userA->wallet()->create(['balance' => '10.0000']);
        $userB = User::factory()->create(['role' => UserRole::User]);
        $userB->wallet()->create(['balance' => '10.0000']);

        $prefix = \App\Models\PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'Prefix 85',
                'country_name' => 'Internal',
                'flag' => '🌐',
                'voice_intra_prefix_rate' => '0.0000',
                'voice_inter_prefix_rate' => '0.2500',
                'is_active' => true,
            ]
        );

        $lineA = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '2001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userA->id,
            'allocated_at' => now(),
        ]);

        $lineB = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '2002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userB->id,
            'allocated_at' => now(),
        ]);

        // 1. User A initiates call to User B
        $initRes = $this->actingAs($userA)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => '2002',
        ]);
        $initRes->assertOk();
        $callAId = $initRes->json('call.id');

        // User B gets incoming call
        $incRes = $this->actingAs($userB)->getJson(route('voice.active_incoming'));
        $incRes->assertOk();
        $callBId = $incRes->json('call.id');

        // 2. User A sends SDP Offer
        $offerRes = $this->actingAs($userA)->postJson(route('voice.signal', $callAId), [
            'type' => 'offer',
            'payload' => ['sdp' => 'v=0\r\no=caller...', 'type' => 'offer'],
        ]);
        $offerRes->assertOk();

        // 3. User B answers call and polls signals to receive User A's Offer
        $this->actingAs($userB)->postJson(route('voice.answer', $callBId))->assertOk();

        $signalsB = $this->actingAs($userB)->getJson(route('voice.signals', $callBId));
        $signalsB->assertOk();
        $signalsB->assertJsonPath('is_active', true);
        $this->assertCount(1, $signalsB->json('signals'));
        $this->assertEquals('offer', $signalsB->json('signals.0.type'));

        // 4. User B sends SDP Answer back
        $answerRes = $this->actingAs($userB)->postJson(route('voice.signal', $callBId), [
            'type' => 'answer',
            'payload' => ['sdp' => 'v=0\r\no=callee...', 'type' => 'answer'],
        ]);
        $answerRes->assertOk();

        // 5. User A polls signals to receive User B's Answer
        $signalsA = $this->actingAs($userA)->getJson(route('voice.signals', $callAId));
        $signalsA->assertOk();
        $this->assertCount(1, $signalsA->json('signals'));
        $this->assertEquals('answer', $signalsA->json('signals.0.type'));

        // 6. User A ends the call -> User B must be notified of termination
        $this->actingAs($userA)->postJson(route('voice.end', $callAId), ['duration_seconds' => 10])->assertOk();

        // User B polls signals / pulse -> is_active must now be false
        $pulseB = $this->actingAs($userB)->postJson(route('voice.pulse', $callBId), ['elapsed_seconds' => 10]);
        $pulseB->assertOk();
        $pulseB->assertJsonPath('active', false);
        $pulseB->assertJsonPath('can_continue', false);

        $signalsBAfterEnd = $this->actingAs($userB)->getJson(route('voice.signals', $callBId));
        $signalsBAfterEnd->assertOk();
        $signalsBAfterEnd->assertJsonPath('is_active', false);
        $signalsBAfterEnd->assertJsonPath('status', 'completed');
    }

    public function test_concurrent_ice_candidates_and_cursor_polling_do_not_drop_signals(): void
    {
        $userA = User::factory()->create(['role' => UserRole::User]);
        $userA->wallet()->create(['balance' => '10.0000']);
        $userB = User::factory()->create(['role' => UserRole::User]);
        $userB->wallet()->create(['balance' => '10.0000']);

        $prefix = \App\Models\PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'Prefix 85',
                'country_name' => 'Internal',
                'flag' => '🌐',
                'voice_intra_prefix_rate' => '0.0000',
                'voice_inter_prefix_rate' => '0.2500',
                'is_active' => true,
            ]
        );

        $lineA = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '3001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userA->id,
            'allocated_at' => now(),
        ]);

        PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '3002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userB->id,
            'allocated_at' => now(),
        ]);

        $initRes = $this->actingAs($userA)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => '3002',
        ]);
        $callAId = $initRes->json('call.id');

        $incRes = $this->actingAs($userB)->getJson(route('voice.active_incoming'));
        $callBId = $incRes->json('call.id');

        // Send offer + 6 rapid ICE candidates before User B answers
        $this->actingAs($userA)->postJson(route('voice.signal', $callAId), [
            'type' => 'offer',
            'payload' => ['sdp' => 'v=0...', 'type' => 'offer'],
        ])->assertOk();

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($userA)->postJson(route('voice.signal', $callAId), [
                'type' => 'ice_candidate',
                'payload' => ['candidate' => "candidate:{$i} 1 UDP 2122252543 192.168.1.{$i} 5000{$i} typ host"],
            ])->assertOk();
        }

        // Answer returns full bridge metadata and rtc_config
        $ansRes = $this->actingAs($userB)->postJson(route('voice.answer', $callBId));
        $ansRes->assertOk();
        $ansRes->assertJsonStructure([
            'success',
            'call' => ['id', 'source', 'destination', 'status', 'bridge_session_id', 'bridge_ws_endpoint', 'rtc_config'],
        ]);

        // Poll with after_id=0 -> must receive all 7 signals in exact order
        $poll1 = $this->actingAs($userB)->getJson(route('voice.signals', ['voiceCall' => $callBId, 'after_id' => 0]));
        $poll1->assertOk();
        $this->assertCount(7, $poll1->json('signals'));
        $this->assertEquals('offer', $poll1->json('signals.0.type'));
        $lastId = $poll1->json('last_signal_id');
        $this->assertGreaterThan(0, $lastId);

        // Subsequent poll with cursor after_id=$lastId returns 0 duplicates
        $poll2 = $this->actingAs($userB)->getJson(route('voice.signals', ['voiceCall' => $callBId, 'after_id' => $lastId]));
        $poll2->assertOk();
        $this->assertCount(0, $poll2->json('signals'));
    }

    public function test_ice_server_provider_generates_turn_hmac_and_saves_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post(route('admin.settings.voice_bridge.update'), [
            'host' => '10.0.0.5',
            'api_port' => 49000,
            'ws_port' => 49001,
            'ws_public_url' => 'wss://voice.example.com/ws/signaling',
            'secret' => 'sec_123456',
            'enabled' => true,
            'stun_urls' => "stun:stun.l.google.com:19302\nstun:stun.cloudflare.com:3478",
            'turn_urls' => "turn:10.0.0.5:3478?transport=udp\nturn:10.0.0.5:3478?transport=tcp",
            'turn_secret' => 'coturn_shared_hmac_secret',
            'ice_transport_policy' => 'relay',
        ])->assertRedirect();

        $provider = app(\App\Services\Communication\IceServerProvider::class);
        $rtc = $provider->rtcConfigFor($admin);

        $this->assertTrue($rtc['hasTurn']);
        $this->assertEquals('relay', $rtc['iceTransportPolicy']);
        $this->assertCount(2, $rtc['iceServers']);
        $this->assertNotEmpty($rtc['iceServers'][1]['username']);
        $this->assertNotEmpty($rtc['iceServers'][1]['credential']);
    }

    public function test_ice_server_provider_offline_mode_purges_external_cloud_stun(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post(route('admin.settings.voice_bridge.update'), [
            'host' => '192.168.1.50',
            'api_port' => 49000,
            'ws_port' => 49001,
            'secret' => 'intranet_secret_999',
            'enabled' => true,
            'turn_domain_or_ip' => '192.168.1.50',
            'turn_port' => 3478,
            'offline_mode' => true,
        ])->assertRedirect();

        $provider = app(\App\Services\Communication\IceServerProvider::class);
        $rtc = $provider->rtcConfigFor($admin);

        $this->assertTrue($rtc['isOfflineMode']);
        $this->assertTrue($rtc['hasTurn']);

        $allStunUrls = $rtc['iceServers'][0]['urls'];
        $this->assertContains('stun:192.168.1.50:3478', $allStunUrls);
        foreach ($allStunUrls as $url) {
            $this->assertStringNotContainsString('google.com', $url);
            $this->assertStringNotContainsString('cloudflare.com', $url);
        }
    }

    public function test_ice_server_provider_auto_generates_turn_urls_and_uses_bridge_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        SystemSetting::set('voice_turn_domain_or_ip', 'voice.company.ir');
        SystemSetting::set('voice_turn_port', '3478');
        SystemSetting::set('voice_turns_port', '5349');
        SystemSetting::set('voice_turns_enabled', '1');
        SystemSetting::set('voice_turn_urls', '');
        SystemSetting::set('voice_turn_secret', '');
        SystemSetting::set('voice_bridge_secret', 'shared_bridge_sec_888');

        $provider = app(\App\Services\Communication\IceServerProvider::class);
        $rtc = $provider->rtcConfigFor($admin);

        $this->assertTrue($rtc['hasTurn']);
        $turnUrls = $rtc['iceServers'][1]['urls'];
        $this->assertContains('turn:voice.company.ir:3478?transport=udp', $turnUrls);
        $this->assertContains('turn:voice.company.ir:3478?transport=tcp', $turnUrls);
        $this->assertContains('turns:voice.company.ir:5349?transport=tcp', $turnUrls);

        $username = $rtc['iceServers'][1]['username'];
        $credential = $rtc['iceServers'][1]['credential'];
        $expected = base64_encode(hash_hmac('sha1', $username, 'shared_bridge_sec_888', true));
        $this->assertEquals($expected, $credential);
    }

    public function test_voice_call_service_builds_bridge_urls_cleanly(): void
    {
        $this->assertEquals(
            'https://voice.company.ir/health',
            \App\Services\Communication\VoiceCallService::buildBridgeUrl('voice.company.ir', 443, '/health')
        );

        $this->assertEquals(
            'https://voice.company.ir/api/sessions/start',
            \App\Services\Communication\VoiceCallService::buildBridgeUrl('https://voice.company.ir', 443, 'api/sessions/start')
        );

        $this->assertEquals(
            'http://10.0.0.5:49000/health',
            \App\Services\Communication\VoiceCallService::buildBridgeUrl('10.0.0.5', 49000, '/health')
        );
    }
}

