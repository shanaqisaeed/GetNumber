<?php

namespace Tests\Feature;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\UserQuotaExceededException;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PhoneNumberAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationalSessionsAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default prefixes and system settings
        PhonePrefix::create([
            'prefix' => '+98',
            'name' => 'ایران',
            'country_code' => 'IR',
            'is_default' => true,
            'is_active' => true,
        ]);

        SystemSetting::set('max_lines_per_user', '10');
        SystemSetting::set('auth_username_enabled', '1');
        SystemSetting::set('auth_email_enabled', '1');
        SystemSetting::set('auth_ldap_enabled', '0');
        SystemSetting::set('auth_keycloak_enabled', '0');
    }

    public function test_cannot_link_disabled_email_provider(): void
    {
        SystemSetting::set('auth_email_enabled', '0');

        $user = User::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $response = $this->actingAs($user)->post(route('profile.identities.email'), [
            'email' => 'new-email@company.com',
            'current_password' => 'Secret123!',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertDatabaseMissing('user_identities', [
            'provider' => 'email',
            'provider_id' => 'new-email@company.com',
        ]);
    }

    public function test_cannot_link_disabled_ldap_provider(): void
    {
        SystemSetting::set('auth_ldap_enabled', '0');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('profile.identities.ldap'), [
            'ldap_username' => 'john.doe',
            'ldap_password' => 'CorpPass123!',
        ]);

        $response->assertSessionHasErrors(['ldap_username']);
    }

    public function test_cannot_redirect_to_disabled_keycloak_when_authenticated(): void
    {
        SystemSetting::set('auth_keycloak_enabled', '0');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('auth.keycloak.redirect'));

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('error');
    }

    public function test_user_can_view_active_sessions_list_with_parsed_agents(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        // Make an initial request so session is initialized and bound to user
        $this->get(route('profile.edit'));
        $currentSessionId = session()->getId();

        // Ensure database has sessions
        DB::table('sessions')->insertOrIgnore([
            [
                'id' => $currentSessionId,
                'user_id' => $user->id,
                'ip_address' => '192.168.1.10',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'payload' => 'dummy',
                'last_activity' => time(),
            ],
            [
                'id' => 'other-session-android-456',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.50',
                'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'payload' => 'dummy2',
                'last_activity' => time() - 3600,
            ],
        ]);

        $response = $this->getJson(route('sessions.index'));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $sessions = $response->json('sessions');
        $this->assertGreaterThanOrEqual(2, count($sessions));

        $android = collect($sessions)->firstWhere('id', 'other-session-android-456');
        $this->assertNotNull($android);
        $this->assertFalse($android['is_current_device']);
        $this->assertStringContainsString('اندروید', $android['platform']);
        $this->assertEquals('mobile', $android['device_type']);
    }

    public function test_user_can_terminate_specific_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        DB::table('sessions')->insert([
            'id' => 'victim-session-789',
            'user_id' => $user->id,
            'ip_address' => '172.16.0.5',
            'user_agent' => 'Firefox/115.0',
            'payload' => 'payload-data',
            'last_activity' => time() - 600,
        ]);

        $response = $this->deleteJson(route('sessions.destroy', 'victim-session-789'));

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseMissing('sessions', [
            'id' => 'victim-session-789',
        ]);
    }

    public function test_user_can_logout_other_devices_and_invalidate_sessions(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('MySecurePass123!'),
        ]);

        $this->actingAs($user);

        // Make an initial request so session is initialized and bound to user
        $this->get(route('profile.edit'));
        $currentSessionId = session()->getId();

        DB::table('sessions')->insertOrIgnore([
            [
                'id' => $currentSessionId,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Chrome',
                'payload' => 'cur',
                'last_activity' => time(),
            ],
            [
                'id' => 'session-to-drop-1',
                'user_id' => $user->id,
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Edge',
                'payload' => 'drop1',
                'last_activity' => time() - 100,
            ],
            [
                'id' => 'session-to-drop-2',
                'user_id' => $user->id,
                'ip_address' => '192.168.1.101',
                'user_agent' => 'Safari',
                'payload' => 'drop2',
                'last_activity' => time() - 200,
            ],
        ]);

        $response = $this->postJson(route('sessions.logout_other'), [
            'password' => 'MySecurePass123!',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Other sessions are wiped
        $this->assertDatabaseMissing('sessions', [
            'id' => 'session-to-drop-1',
        ]);
        $this->assertDatabaseMissing('sessions', [
            'id' => 'session-to-drop-2',
        ]);
    }

    public function test_max_lines_quota_is_configurable_via_general_settings(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        // Admin changes max_lines_per_user to 2
        $response = $this->actingAs($admin)->post(route('admin.settings.general.update'), [
            'app_name' => 'سامانه تست',
            'app_description' => 'توضیحات تست',
            'max_lines_per_user' => 2,
            'event_notify_login_failed' => true,
            'event_notify_user_registered' => true,
            'event_notify_security_alert' => true,
        ]);

        $response->assertRedirect(route('admin.settings.general', ['tab' => 'general']));
        $this->assertEquals('2', SystemSetting::get('max_lines_per_user'));

        // Standard user allocations
        $user = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $prefix = PhonePrefix::where('is_default', true)->first();

        // Give user 2 allocated numbers
        PhoneNumber::create([
            'prefix_id' => $prefix->id,
            'number' => '912000001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
        ]);
        PhoneNumber::create([
            'prefix_id' => $prefix->id,
            'number' => '912000002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user->id,
            'allocated_at' => now(),
        ]);

        $allocationService = app(PhoneNumberAllocationService::class);

        $this->expectException(UserQuotaExceededException::class);
        $allocationService->allocateStandardNumberToUser($user);
    }

    public function test_unified_general_settings_page_loads_all_tabs_data(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.settings.general', ['tab' => 'auth']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/General')
            ->has('generalSettings')
            ->has('authSettings')
            ->has('ldapSettings')
            ->has('keycloakSettings')
            ->where('initialTab', 'auth')
            ->where('generalSettings.max_lines_per_user', 10)
        );
    }
}
