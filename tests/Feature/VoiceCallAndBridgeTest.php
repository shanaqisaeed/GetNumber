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
}

