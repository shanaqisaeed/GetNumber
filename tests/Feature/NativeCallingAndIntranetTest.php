<?php

namespace Tests\Feature;

use App\DTOs\CallPushPayload;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\CallLifecycleEvent;
use App\Models\MobileDevice;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Communication\CallEventStreamService;
use App\Services\Communication\DeviceRegistryService;
use App\Services\Communication\IceServerProvider;
use App\Services\Communication\NetworkModeManager;
use App\Services\Communication\Push\PushGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NativeCallingAndIntranetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('voice_bridge_enabled', false);
        SystemSetting::set('voice_bridge_secret', 'test_shared_bridge_secret_2026');
        SystemSetting::set('voice_call_rate_per_minute', 0.1000);
        SystemSetting::set('voice_intra_prefix_rate', 0.1000);
        SystemSetting::set('voice_inter_prefix_rate', 0.2500);
        SystemSetting::set('id_voice_rate_per_minute', 0.0500);
        SystemSetting::set('voice_intranet_ssids', "Ravyno-Corp-WiFi\nRavyno-VoIP-LAN");
    }

    public function test_mobile_device_registration_encrypts_push_tokens_at_rest_and_issues_local_push_ticket(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $rawVoipToken = 'apns_voip_secret_token_a1b2c3d4e5f600112233445566778899';
        $rawFcmToken = 'fcm_reg_secret_token_99887766554433221100ffeeddccbbaa';

        $response = $this->actingAs($user)->postJson(route('api.v1.mobile.devices.register'), [
            'device_uuid' => 'ios-iphone15-001',
            'platform' => 'ios',
            'app_version' => '1.2.0',
            'os_version' => 'iOS 17.5',
            'device_model' => 'iPhone 15 Pro',
            'voip_push_token' => $rawVoipToken,
            'notification_push_token' => $rawFcmToken,
            'local_push_connected' => true,
            'network_mode' => 'INTRANET',
            'ssid' => 'Ravyno-Corp-WiFi',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('bridge_version', '1.0.0');
        $response->assertJsonPath('device.device_uuid', 'ios-iphone15-001');
        $response->assertJsonPath('device.platform', 'ios');
        $response->assertJsonPath('device.has_voip_token', true);
        $response->assertJsonPath('device.has_notification_token', true);
        $response->assertJsonPath('local_push.allowed_intranet_ssids', ['Ravyno-Corp-WiFi', 'Ravyno-VoIP-LAN']);

        // Ensure raw tokens are NEVER exposed in API response JSON
        $rawBody = $response->getContent();
        $this->assertStringNotContainsString($rawVoipToken, $rawBody);
        $this->assertStringNotContainsString($rawFcmToken, $rawBody);

        // Ensure raw tokens are encrypted at rest in database
        $rawDbRow = DB::table('mobile_devices')->where('device_uuid', 'ios-iphone15-001')->first();
        $this->assertNotNull($rawDbRow);
        $this->assertNotEquals($rawVoipToken, $rawDbRow->voip_push_token_encrypted);
        $this->assertNotEquals($rawFcmToken, $rawDbRow->notification_push_token_encrypted);
        $this->assertEquals(hash('sha256', $rawVoipToken), $rawDbRow->voip_push_token_hash);

        // Verify decryption works via Eloquent model
        $device = MobileDevice::where('device_uuid', 'ios-iphone15-001')->firstOrFail();
        $this->assertEquals($rawVoipToken, $device->getDecryptedVoipPushToken());
        $this->assertEquals($rawFcmToken, $device->getDecryptedNotificationPushToken());

        // Verify Local Push HMAC ticket verification
        $ticket = $response->json('local_push.ticket');
        $this->assertNotEmpty($ticket);
        $verified = app(DeviceRegistryService::class)->verifyLocalPushTicket($ticket);
        $this->assertNotNull($verified);
        $this->assertEquals('ios-iphone15-001', $verified['device_uuid']);
        $this->assertEquals($user->id, $verified['user_id']);
    }

    public function test_multi_device_atomic_accept_allows_first_device_and_rejects_second_device_with_409(): void
    {
        [$caller, $callee, $lineA, $lineB] = $this->seedCallerAndCallee();

        // Register 2 devices for Callee (1 iOS + 1 Android) and get their Lock-Screen Device Bearer Tokens
        $regIos = $this->actingAs($callee)->postJson(route('api.v1.mobile.devices.register'), [
            'device_uuid' => 'callee-ios-device-1',
            'platform' => 'ios',
            'local_push_connected' => true,
        ])->assertOk();
        $iosBearerToken = $regIos->json('device_auth_token');
        $this->assertNotEmpty($iosBearerToken);

        $regAndroid = $this->actingAs($callee)->postJson(route('api.v1.mobile.devices.register'), [
            'device_uuid' => 'callee-android-device-2',
            'platform' => 'android',
            'local_push_connected' => true,
        ])->assertOk();
        $androidBearerToken = $regAndroid->json('device_auth_token');
        $this->assertNotEmpty($androidBearerToken);

        // Caller initiates call to Callee
        $initRes = $this->actingAs($caller)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => $lineB->number,
            'has_video' => true,
        ])->assertOk();

        $callUuid = $initRes->json('call.call_uuid');
        $this->assertNotEmpty($callUuid);

        // 1. iOS Device answers from Lock Screen using ONLY its Device Bearer Token (no web session!)
        $this->app['auth']->forgetGuards();
        $ansIos = $this->withHeaders([
            'Authorization' => 'Bearer ' . $iosBearerToken,
        ])->postJson(route('api.v1.mobile.calls.answer', ['callUuid' => $callUuid]), [
            'device_uuid' => 'callee-ios-device-1',
        ]);

        $ansIos->assertOk();
        $ansIos->assertJsonPath('success', true);
        $ansIos->assertJsonPath('call.accepted_device_id', 'callee-ios-device-1');
        $ansIos->assertJsonPath('call.has_video', true);

        // 2. Idempotent retry from the SAME iOS Device succeeds with 200 OK
        $ansIosRetry = $this->withHeaders([
            'Authorization' => 'Bearer ' . $iosBearerToken,
        ])->postJson(route('api.v1.mobile.calls.answer', ['callUuid' => $callUuid]), [
            'device_uuid' => 'callee-ios-device-1',
        ]);
        $ansIosRetry->assertOk();
        $ansIosRetry->assertJsonPath('call.accepted_device_id', 'callee-ios-device-1');

        // 3. Competing answer from Android Device 2 MUST be rejected with 409 Conflict
        $ansAndroid = $this->withHeaders([
            'Authorization' => 'Bearer ' . $androidBearerToken,
        ])->postJson(route('api.v1.mobile.calls.answer', ['callUuid' => $callUuid]), [
            'device_uuid' => 'callee-android-device-2',
        ]);

        $ansAndroid->assertStatus(409);
        $ansAndroid->assertJsonPath('success', false);
        $ansAndroid->assertJsonPath('code', 'CALL_ALREADY_ACCEPTED_ON_ANOTHER_DEVICE');
        $ansAndroid->assertJsonPath('accepted_device_id', 'callee-ios-device-1');
    }

    public function test_unanswered_cancelled_call_does_not_debit_wallet_and_records_cancelled_reason(): void
    {
        [$caller, $callee, $lineA, $lineB] = $this->seedCallerAndCallee(0.2500);
        $initialBalance = (string) $caller->wallet->balance;

        $initRes = $this->actingAs($caller)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => $lineB->number,
        ])->assertOk();

        $callId = $initRes->json('call.id');
        $callUuid = $initRes->json('call.call_uuid');

        // Caller hangs up while call is still ringing (before Callee answers)
        $endRes = $this->actingAs($caller)->postJson(route('voice.end', $callId));
        $endRes->assertOk();
        $endRes->assertJsonPath('call.duration_seconds', 0);
        $endRes->assertJsonPath('call.cost', 0);

        // Wallet balance must remain 100% untouched
        $this->assertEquals($initialBalance, (string) $caller->wallet->fresh()->balance);

        // Both caller and callee legs must record cancelled_by_caller
        $this->assertDatabaseHas('voice_calls', [
            'call_uuid' => $callUuid,
            'direction' => 'inbound',
            'end_reason' => 'cancelled_by_caller',
        ]);
    }

    public function test_ring_timeout_expires_unanswered_calls_to_missed(): void
    {
        [$caller, $callee, $lineA, $lineB] = $this->seedCallerAndCallee();

        $initRes = $this->actingAs($caller)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => $lineB->number,
        ])->assertOk();

        $callUuid = $initRes->json('call.call_uuid');

        // Fast-forward ring_timeout_at into the past
        VoiceCall::where('call_uuid', $callUuid)->update([
            'ring_timeout_at' => now()->subSeconds(5),
        ]);

        // Attempting to answer an expired ringing call returns 410 Gone and marks it missed
        $inboundCall = VoiceCall::where('call_uuid', $callUuid)->where('direction', 'inbound')->firstOrFail();
        $ansRes = $this->actingAs($callee)->postJson(route('api.v1.mobile.calls.answer', ['callUuid' => $callUuid]), [
            'device_uuid' => 'dev-1',
        ]);

        $ansRes->assertStatus(410);
        $this->assertEquals(VoiceCall::STATE_MISSED, $inboundCall->fresh()->status);
        $this->assertEquals('ring_timeout', $inboundCall->fresh()->end_reason);
    }

    public function test_intranet_mode_purges_all_third_party_cloud_stun_and_turn_servers_and_routes_local_push(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        // Configure mixed local and third-party public STUN/TURN servers
        SystemSetting::set('voice_offline_mode', '1');
        SystemSetting::set('voice_turn_domain_or_ip', 'turn.intra.company.local');
        SystemSetting::set('voice_turn_port', '3478');
        SystemSetting::set('voice_stun_urls', implode("\n", [
            'stun:10.10.20.10:3478',
            'stun:stun.l.google.com:19302',
            'stun:stun.cloudflare.com:3478',
            'stun:stun.sipgate.net:3478',
            'stun:stun.voip.blackberry.com:3478',
            'stun:openrelay.metered.ca:80',
        ]));
        SystemSetting::set('voice_turn_urls', implode("\n", [
            'turn:turn.intra.company.local:3478?transport=udp',
            'turn:openrelay.metered.ca:443?transport=tcp',
        ]));

        // 1. Verify NetworkModeManager reports INTRANET
        $probeRes = $this->getJson(route('api.v1.network.probe'));
        $probeRes->assertOk();
        $probeRes->assertJsonPath('mode', NetworkModeManager::MODE_INTRANET);
        $probeRes->assertJsonPath('strict_offline_mode', true);
        $probeRes->assertJsonPath('services.public_wan', false);

        // 2. Verify IceServerProvider purges ALL public STUN and TURN servers
        $rtcConfig = app(IceServerProvider::class)->rtcConfigFor($user);
        $this->assertTrue($rtcConfig['isOfflineMode']);
        $this->assertEquals(NetworkModeManager::MODE_INTRANET, $rtcConfig['networkMode']);

        $stunUrls = $rtcConfig['iceServers'][0]['urls'];
        $this->assertContains('stun:turn.intra.company.local:3478', $stunUrls);
        $this->assertContains('stun:10.10.20.10:3478', $stunUrls);
        foreach ($stunUrls as $u) {
            $this->assertStringNotContainsString('google.com', $u);
            $this->assertStringNotContainsString('cloudflare.com', $u);
            $this->assertStringNotContainsString('sipgate.net', $u);
            $this->assertStringNotContainsString('blackberry.com', $u);
            $this->assertStringNotContainsString('metered.ca', $u);
        }

        $turnUrls = $rtcConfig['iceServers'][1]['urls'];
        $this->assertContains('turn:turn.intra.company.local:3478?transport=udp', $turnUrls);
        foreach ($turnUrls as $u) {
            $this->assertStringNotContainsString('metered.ca', $u);
        }

        // 3. Register iOS and Android devices connected to Local Push and verify PushGateway routes via Local Push
        app(DeviceRegistryService::class)->registerOrUpdateDevice($user, [
            'device_uuid' => 'intra-ios-1',
            'platform' => 'ios',
            'local_push_connected' => true,
        ]);
        app(DeviceRegistryService::class)->registerOrUpdateDevice($user, [
            'device_uuid' => 'intra-android-1',
            'platform' => 'android',
            'local_push_connected' => true,
        ]);

        $payload = new CallPushPayload(
            callUuid: 'uuid-intranet-call-1',
            callId: 501,
            callerHandle: '+851001',
            callerDisplayName: 'Internal Caller',
            destinationLine: '+851002',
            hasVideo: false,
            issuedAt: time(),
            expiresAt: time() + 45,
        );

        $dispatchSummary = app(PushGatewayService::class)->dispatchIncomingCall($user->id, $payload);
        $this->assertEquals('INTRANET', $dispatchSummary['network_mode']);
        $this->assertEquals(2, $dispatchSummary['total_devices']);
        $this->assertEquals(2, $dispatchSummary['delivered_count']);

        $providersUsed = array_column($dispatchSummary['results'], 'provider');
        $this->assertContains('ios_local', $providersUsed);
        $this->assertContains('android_local', $providersUsed);

        // 4. Duplicate dispatch for the same call_uuid is suppressed by deduplication lock
        $duplicateSummary = app(PushGatewayService::class)->dispatchIncomingCall($user->id, $payload);
        $this->assertEquals(0, $duplicateSummary['delivered_count']);
    }

    public function test_unauthorized_third_user_cannot_access_answer_reject_or_end_call_by_call_uuid(): void
    {
        [$caller, $callee, $lineA, $lineB] = $this->seedCallerAndCallee();
        $attacker = User::factory()->create(['role' => UserRole::User]);

        $initRes = $this->actingAs($caller)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => $lineB->number,
        ])->assertOk();

        $callUuid = $initRes->json('call.call_uuid');

        // Attacker attempts to view, answer, reject, and end the call using call_uuid
        $this->actingAs($attacker)->getJson(route('api.v1.mobile.calls.show', ['callUuid' => $callUuid]))->assertStatus(404);
        $this->actingAs($attacker)->postJson(route('api.v1.mobile.calls.answer', ['callUuid' => $callUuid]))->assertStatus(404);
        $this->actingAs($attacker)->postJson(route('api.v1.mobile.calls.reject', ['callUuid' => $callUuid]))->assertStatus(404);
        $this->actingAs($attacker)->postJson(route('api.v1.mobile.calls.end', ['callUuid' => $callUuid]))->assertStatus(404);
    }

    public function test_call_event_stream_enforces_idempotency_and_dead_letter_queue_on_failure(): void
    {
        $stream = app(CallEventStreamService::class);
        $executionCount = 0;

        $envelope = [
            'event_id' => 'evt_test_idempotent_001',
            'schema_version' => 1,
            'event_type' => 'call.accepted',
            'call_uuid' => 'uuid-stream-001',
        ];

        // 1. First consumption succeeds
        $first = $stream->consumeIdempotently($envelope, function () use (&$executionCount) {
            $executionCount++;
        });
        $this->assertTrue($first);
        $this->assertEquals(1, $executionCount);

        // 2. Duplicate delivery with same event_id is ignored
        $second = $stream->consumeIdempotently($envelope, function () use (&$executionCount) {
            $executionCount++;
        });
        $this->assertFalse($second);
        $this->assertEquals(1, $executionCount);

        // 3. Failing consumer retries and lands in DLQ
        $attempts = 0;
        $dlqResult = $stream->consumeIdempotently([
            'event_id' => 'evt_test_failing_002',
            'schema_version' => 1,
            'event_type' => 'call.invite',
            'call_uuid' => 'uuid-stream-002',
        ], function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('Simulated consumer failure');
        }, 3);

        $this->assertFalse($dlqResult);
        $this->assertEquals(3, $attempts);
        $this->assertDatabaseHas('call_lifecycle_events', [
            'event_id' => 'evt_test_failing_002',
            'event_type' => 'dlq.call.invite',
        ]);
    }

    public function test_opening_pwa_calls_page_during_ringing_does_not_disconnect_call_and_preserves_video_flag(): void
    {
        [$caller, $callee, $lineA, $lineB] = $this->seedCallerAndCallee();

        // 1. Caller initiates a video call (has_video = true)
        $initRes = $this->actingAs($caller)->postJson(route('voice.initiate'), [
            'phone_number_id' => $lineA->id,
            'destination' => $lineB->number,
            'has_video' => true,
        ])->assertOk();

        $initRes->assertJsonPath('call.has_video', true);
        $callUuid = $initRes->json('call.call_uuid');
        $callerCallId = $initRes->json('call.id');

        // 2. Callee enters the PWA (GET /calls) while the call is ringing
        $this->actingAs($callee)->get(route('calls.index'))->assertOk();

        // 3. Call must NOT be terminated! Both legs must still be active/ringing
        $callerSignals = $this->actingAs($caller)->getJson(route('voice.signals', $callerCallId))->assertOk();
        $callerSignals->assertJsonPath('is_active', true);

        // 4. Callee checks active incoming call and sees has_video = true
        $activeIncoming = $this->actingAs($callee)->getJson(route('voice.active_incoming'))->assertOk();
        $activeIncoming->assertJsonPath('has_incoming', true);
        $activeIncoming->assertJsonPath('call.call_uuid', $callUuid);
        $activeIncoming->assertJsonPath('call.has_video', true);
        $inboundCallId = $activeIncoming->json('call.id');

        // 5. Callee answers the call; response preserves has_video = true so callee can view caller's video even if answering audio-only
        $ansRes = $this->actingAs($callee)->postJson(route('voice.answer', $inboundCallId))->assertOk();
        $ansRes->assertJsonPath('call.has_video', true);

        // 6. Verify WebPushNotificationService::resolveSubject() never returns http://localhost
        $subject = app(\App\Services\WebPushNotificationService::class)->resolveSubject();
        $this->assertTrue(str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://'));
        $this->assertStringNotContainsString('localhost', $subject);
    }

    /**
     * @return array{0: User, 1: User, 2: PhoneNumber, 3: PhoneNumber}
     */
    protected function seedCallerAndCallee(float $intraRate = 0.0000): array
    {
        $userA = User::factory()->create(['role' => UserRole::User]);
        $userA->wallet()->create(['balance' => '10.0000']);
        $userB = User::factory()->create(['role' => UserRole::User]);
        $userB->wallet()->create(['balance' => '10.0000']);

        $prefix = PhonePrefix::updateOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'Prefix 85',
                'country_name' => 'Internal',
                'flag' => '🌐',
                'voice_intra_prefix_rate' => number_format($intraRate, 4, '.', ''),
                'voice_inter_prefix_rate' => '0.2500',
                'is_active' => true,
            ]
        );

        $lineA = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '4001',
            'formatted_number' => '+854001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userA->id,
            'allocated_at' => now(),
        ]);

        $lineB = PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '4002',
            'formatted_number' => '+854002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $userB->id,
            'allocated_at' => now(),
        ]);

        return [$userA, $userB, $lineA, $lineB];
    }
}
