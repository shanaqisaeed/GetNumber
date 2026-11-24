<?php

namespace Tests\Feature;

use App\Contracts\ConferenceServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\UserRole;
use App\Models\ConferenceMessage;
use App\Models\ConferenceRoom;
use App\Models\PhoneNumber;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConferenceSystemTest extends TestCase
{
    use RefreshDatabase;

    protected ConferenceServiceInterface $conferenceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conferenceService = app(ConferenceServiceInterface::class);
    }

    public function test_org_bulk_sms_page_loads_without_sql_is_active_error(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $org = $user->getOrCreatePersonalOrganization();

        PhoneNumber::create([
            'number' => '851000',
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => PhoneNumberStatus::Allocated,
        ]);

        $this->actingAs($user)
            ->get(route('org.bulk_sms.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Organization/BulkSms/Index')
                ->has('sender_lines', 1)
            );
    }

    public function test_user_can_create_and_delete_org_conference_room_with_7_day_expiry(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $response = $this->actingAs($user)->post(route('org.conference.store'), [
            'title' => 'جلسه هفتگی توسعه',
            'admission_policy' => 'approval',
            'initial_audio_state' => 'muted',
            'webinar_mode' => true,
            'screen_share_policy' => 'by_permission',
            'mute_others_on_share' => true,
            'allow_whiteboard' => true,
            'max_participants' => 30,
        ]);

        $room = ConferenceRoom::first();

        $this->assertNotNull($room);
        $this->assertNotNull($room->organization_id);
        $this->assertEquals('جلسه هفتگی توسعه', $room->title);
        $this->assertTrue($room->webinar_mode);
        $this->assertTrue($room->mute_others_on_share);
        $this->assertEquals('by_permission', $room->screen_share_policy);
        $this->assertNotNull($room->expires_at);
        $this->assertTrue($room->expires_at->isAfter(Carbon::now()->addDays(6)));

        $response->assertRedirect(route('org.conference.lobby', ['uuid' => $room->room_uuid]));

        // Host can render room page without errors and camera defaults to true
        $this->actingAs($user)
            ->get(route('org.conference.room', ['uuid' => $room->room_uuid]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Organization/Conference/Room')
                ->where('currentParticipant.is_camera_on', true)
            );

        // Host can delete room
        $this->actingAs($user)
            ->deleteJson(route('org.conference.destroy', ['uuid' => $room->room_uuid]))
            ->assertOk();

        $this->assertSoftDeleted('conference_rooms', ['id' => $room->id]);
    }

    public function test_strict_single_seat_line_policy_prevents_duplicate_number_in_same_room(): void
    {
        $host = User::factory()->create(['role' => UserRole::User]);
        $guest1 = User::factory()->create(['role' => UserRole::User]);
        $guest2 = User::factory()->create(['role' => UserRole::User]);

        $room = $this->conferenceService->createRoom($host, [
            'title' => 'جلسه محرمانه',
            'admission_policy' => 'open',
        ]);

        $phoneNumber = '09121234567';

        $this->actingAs($guest1)->postJson(route('org.conference.join', ['uuid' => $room->room_uuid]), [
            'phone_number' => $phoneNumber,
            'is_camera_on' => true,
            'is_muted' => false,
        ])->assertOk();

        $duplicateAttempt = $this->actingAs($guest2)->postJson(route('org.conference.join', ['uuid' => $room->room_uuid]), [
            'phone_number' => $phoneNumber,
        ]);

        $duplicateAttempt->assertStatus(422);
        $duplicateAttempt->assertJsonFragment([
            'success' => false,
            'message' => __('conference.auth.single_seat_violation'),
        ]);
    }

    public function test_screen_share_permission_grant_and_mute_others_on_share(): void
    {
        $host = User::factory()->create(['role' => UserRole::User]);
        $guest = User::factory()->create(['role' => UserRole::User]);
        $otherGuest = User::factory()->create(['role' => UserRole::User]);

        $room = $this->conferenceService->createRoom($host, [
            'title' => 'جلسه پرزنتیشن',
            'admission_policy' => 'open',
            'screen_share_policy' => 'by_permission',
            'mute_others_on_share' => true,
        ]);

        $this->conferenceService->joinRoom($room, $host, '09120000001');
        $guestJoin = $this->conferenceService->joinRoom($room, $guest, '09120000002');
        $otherJoin = $this->conferenceService->joinRoom($room, $otherGuest, '09120000003');

        // Guest initially cannot share screen when policy is by_permission
        $this->actingAs($guest)
            ->postJson(route('org.conference.start_screen_share', ['uuid' => $room->room_uuid]))
            ->assertStatus(403);

        // Host grants screen share permission to guest
        $this->actingAs($host)
            ->postJson(route('org.conference.grant_screen_share', [
                'uuid' => $room->room_uuid,
                'participant' => $guestJoin['participant']->id,
            ]), ['can_share' => true])
            ->assertOk();

        // Unmute otherGuest before screen share starts
        $otherJoin['participant']->update(['is_muted' => false]);
        $this->assertFalse($otherJoin['participant']->fresh()->is_muted);

        // Now guest can start screen share, which automatically mutes otherGuest
        $this->actingAs($guest)
            ->postJson(route('org.conference.start_screen_share', ['uuid' => $room->room_uuid]))
            ->assertOk();

        $this->assertTrue($guestJoin['participant']->fresh()->is_sharing_screen);
        $this->assertTrue($otherJoin['participant']->fresh()->is_muted);
    }

    public function test_host_controls_waiting_lobby_lock_webinar_and_webrtc_signals(): void
    {
        $host = User::factory()->create(['role' => UserRole::User]);
        $guest = User::factory()->create(['role' => UserRole::User]);

        $room = $this->conferenceService->createRoom($host, [
            'title' => 'جلسه مدیریتی',
            'admission_policy' => 'approval',
            'webinar_mode' => false,
        ]);

        $hostJoin = $this->conferenceService->joinRoom($room, $host, '09120000010');
        $guestJoin = $this->conferenceService->joinRoom($room, $guest, '09120000020');

        $this->assertEquals('waiting_approval', $guestJoin['status']);

        // Host admits waiting participant
        $this->actingAs($host)
            ->postJson(route('org.conference.admit', [
                'uuid' => $room->room_uuid,
                'participant' => $guestJoin['participant']->id,
            ]))
            ->assertOk();

        $this->assertEquals('admitted', $guestJoin['participant']->fresh()->status);

        // Host sends WebRTC offer signal to guest
        $this->actingAs($host)
            ->postJson(route('org.conference.signal', ['uuid' => $room->room_uuid]), [
                'type' => 'offer',
                'target_participant_id' => $guestJoin['participant']->id,
                'payload' => ['sdp' => ['type' => 'offer', 'sdp' => 'v=0...']],
            ])
            ->assertOk();

        // Guest polls state and receives the signal
        $stateRes = $this->actingAs($guest)
            ->getJson(route('org.conference.state', ['uuid' => $room->room_uuid, 'since_seq' => 0]))
            ->assertOk();

        $this->assertCount(1, $stateRes->json('signals'));
        $this->assertEquals('offer', $stateRes->json('signals.0.type'));

        // Host toggles lock and webinar mode
        $this->actingAs($host)
            ->postJson(route('org.conference.lock', ['uuid' => $room->room_uuid]))
            ->assertOk()
            ->assertJsonFragment(['is_locked' => true]);

        $this->actingAs($host)
            ->postJson(route('org.conference.webinar', ['uuid' => $room->room_uuid]))
            ->assertOk()
            ->assertJsonFragment(['webinar_mode' => true]);

        // Host exports attendance CSV
        $this->actingAs($host)
            ->get(route('org.conference.export_attendance', ['uuid' => $room->room_uuid]))
            ->assertOk();
    }

    public function test_cleanup_expired_rooms_artisan_command(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $expiredRoom = ConferenceRoom::create([
            'created_by_user_id' => $user->id,
            'room_uuid' => 'conf_expired_test',
            'title' => 'اتاق منقضی شده قدیمی',
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_active' => true,
        ]);

        $validRoom = ConferenceRoom::create([
            'created_by_user_id' => $user->id,
            'room_uuid' => 'conf_valid_test',
            'title' => 'اتاق معتبر جاری',
            'expires_at' => Carbon::now()->addDays(5),
            'is_active' => true,
        ]);

        $this->artisan('conference:cleanup-expired')
            ->assertExitCode(0);

        $this->assertSoftDeleted('conference_rooms', ['id' => $expiredRoom->id]);
        $this->assertDatabaseHas('conference_rooms', ['id' => $validRoom->id, 'deleted_at' => null]);
    }

    public function test_in_meeting_poll_creation_voting_and_closing(): void
    {
        $host = User::factory()->create(['role' => UserRole::User]);
        $guest = User::factory()->create(['role' => UserRole::User]);

        $room = $this->conferenceService->createRoom($host, [
            'title' => 'جلسه نظرسنجی',
        ]);

        $poll = $this->conferenceService->createPoll($room, $host, 'آیا با طرح موافقید؟', ['بله', 'خیر']);

        $this->assertNotNull($poll);
        $this->assertTrue($poll->is_active);

        $vote = $this->conferenceService->votePoll($poll, $guest, 0);
        $this->assertEquals(0, $vote->option_index);

        $summary = $poll->getResultsSummary();
        $this->assertEquals(1, $summary['total_votes']);
        $this->assertEquals(100.0, $summary['options'][0]['percentage']);

        // Host closes poll
        $this->actingAs($host)
            ->postJson(route('org.conference.polls.close', ['uuid' => $room->room_uuid, 'poll' => $poll->id]))
            ->assertOk();

        $this->assertFalse($poll->fresh()->is_active);
    }

    public function test_in_meeting_chat_and_visibility(): void
    {
        $host = User::factory()->create(['role' => UserRole::User]);
        $guest1 = User::factory()->create(['role' => UserRole::User]);
        $guest2 = User::factory()->create(['role' => UserRole::User]);

        $room = $this->conferenceService->createRoom($host, ['title' => 'چت']);

        $this->conferenceService->sendMessage($room, $guest1, 'سلام به همه');
        $this->conferenceService->sendMessage($room, $guest1, 'پیام خصوصی به میزبان', $host->id);

        $hostMessages = ConferenceMessage::where('conference_room_id', $room->id)
            ->visibleFor($host->id)
            ->get();
        $this->assertCount(2, $hostMessages);

        $guest2Messages = ConferenceMessage::where('conference_room_id', $room->id)
            ->visibleFor($guest2->id)
            ->get();
        $this->assertCount(1, $guest2Messages);
    }

    public function test_admin_can_configure_sfu_ports_and_conference_room_receives_sfu_config(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post(route('admin.settings.voice_bridge.update'), [
            'host' => '185.100.200.50',
            'api_port' => 49000,
            'ws_port' => 49001,
            'ws_public_url' => '',
            'secret' => 'test_secret_32_chars_long_123456',
            'enabled' => true,
            'sfu_enabled' => true,
            'sfu_ws_path' => '/ws/sfu',
            'sfu_ws_public_url' => 'wss://meet.ravyno.ir/ws/sfu',
            'sfu_min_port' => 49152,
            'sfu_max_port' => 49250,
        ])->assertRedirect();

        $this->assertEquals('1', \App\Models\SystemSetting::get('voice_sfu_enabled'));
        $this->assertEquals('/ws/sfu', \App\Models\SystemSetting::get('voice_sfu_ws_path'));
        $this->assertEquals('wss://meet.ravyno.ir/ws/sfu', \App\Models\SystemSetting::get('voice_sfu_ws_public_url'));
        $this->assertEquals('49152', \App\Models\SystemSetting::get('voice_sfu_min_port'));
        $this->assertEquals('49250', \App\Models\SystemSetting::get('voice_sfu_max_port'));

        $room = $this->conferenceService->createRoom($admin, [
            'title' => 'تست تنظیمات SFU',
            'admission_policy' => 'open',
        ]);

        $this->actingAs($admin)
            ->get(route('org.conference.room', ['uuid' => $room->room_uuid]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Organization/Conference/Room')
                ->where('sfuConfig.enabled', true)
                ->where('sfuConfig.wsUrl', 'wss://meet.ravyno.ir/ws/sfu')
                ->where('sfuConfig.wsPort', 49001)
                ->where('sfuConfig.wsPath', '/ws/sfu')
                ->where('sfuConfig.minPort', 49152)
                ->where('sfuConfig.maxPort', 49250)
            );
    }
}
