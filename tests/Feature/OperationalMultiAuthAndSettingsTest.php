<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationalMultiAuthAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test existing users have identities and can authenticate by username.
     */
    public function test_user_can_authenticate_with_username(): void
    {
        $user = User::factory()->create([
            'username' => 'test_operator',
            'password' => Hash::make('Secret123456!'),
            'status' => UserStatus::Active,
        ]);
        $user->linkIdentity('username', 'test_operator');

        $response = $this->post(route('login'), [
            'auth_type' => 'username',
            'username' => 'test_operator',
            'password' => 'Secret123456!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test user can authenticate with email.
     */
    public function test_user_can_authenticate_with_email(): void
    {
        $user = User::factory()->create([
            'username' => 'email_user',
            'email' => 'operator@company.com',
            'password' => Hash::make('Secret123456!'),
            'status' => UserStatus::Active,
        ]);
        $user->linkIdentity('email', 'operator@company.com', 'operator@company.com');

        $response = $this->post(route('login'), [
            'auth_type' => 'email',
            'email' => 'operator@company.com',
            'password' => 'Secret123456!',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test disabled authentication provider cannot be used to login.
     */
    public function test_disabled_auth_provider_is_rejected(): void
    {
        SystemSetting::set('auth_ldap_enabled', '0');

        $response = $this->post(route('login'), [
            'auth_type' => 'ldap',
            'ldap_username' => 'admin_ldap',
            'ldap_password' => 'pass',
        ]);

        $response->assertSessionHasErrors('ldap_username');
        $this->assertGuest();
    }

    /**
     * Test user registration with email.
     */
    public function test_user_can_register_with_email(): void
    {
        SystemSetting::set('allow_user_registration', '1');
        SystemSetting::set('auth_email_enabled', '1');

        $response = $this->post(route('register'), [
            'registration_type' => 'email',
            'name' => 'کاربر جدید سازمانی',
            'email' => 'new.employee@enterprise.com',
            'password' => 'Pass123456@Secure',
            'password_confirmation' => 'Pass123456@Secure',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', [
            'email' => 'new.employee@enterprise.com',
        ]);
        $this->assertDatabaseHas('user_identities', [
            'provider' => 'email',
            'provider_id' => 'new.employee@enterprise.com',
        ]);
    }

    /**
     * Test user can link email from profile.
     */
    public function test_authenticated_user_can_link_email(): void
    {
        $user = User::factory()->create([
            'username' => 'linking_user',
            'password' => Hash::make('MyPassword123!'),
        ]);

        $response = $this->actingAs($user)->post(route('profile.identities.email'), [
            'email' => 'linked.email@domain.com',
            'current_password' => 'MyPassword123!',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_id' => 'linked.email@domain.com',
        ]);
        $this->assertEquals('linked.email@domain.com', $user->fresh()->email);
    }

    /**
     * Test user can unlink an identity if another identity exists.
     */
    public function test_user_can_unlink_identity(): void
    {
        $user = User::factory()->create([
            'username' => 'multi_user',
            'email' => 'to_unlink@example.com',
            'password' => Hash::make('Secret123!'),
        ]);

        $user->linkIdentity('username', 'multi_user');
        $user->linkIdentity('email', 'to_unlink@example.com', 'to_unlink@example.com');

        $response = $this->actingAs($user)->delete(route('profile.identities.unlink', 'email'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('user_identities', [
            'user_id' => $user->id,
            'provider' => 'email',
        ]);
    }

    /**
     * Test admin can access and update general settings.
     */
    public function test_admin_can_update_general_settings(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.settings.general.update'), [
            'app_name' => 'پلتفرم جدید سازمانی',
            'app_description' => 'شرح جدید',
            'event_notify_login_failed' => true,
            'event_notify_user_registered' => true,
            'event_notify_security_alert' => false,
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals('پلتفرم جدید سازمانی', SystemSetting::get('app_name'));
        $this->assertEquals('0', SystemSetting::get('event_notify_security_alert'));
    }

    /**
     * Test admin can update auth toggles and at least one must remain enabled.
     */
    public function test_admin_must_keep_at_least_one_auth_method(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.settings.auth.update'), [
            'auth_username_enabled' => false,
            'auth_email_enabled' => false,
            'auth_ldap_enabled' => false,
            'auth_keycloak_enabled' => false,
            'allow_user_registration' => false,
        ]);

        $response->assertSessionHasErrors('auth_username_enabled');
    }

    /**
     * Test Keycloak redirect route.
     */
    public function test_keycloak_redirect_when_enabled(): void
    {
        SystemSetting::set('auth_keycloak_enabled', '1');
        SystemSetting::set('keycloak_base_url', 'https://sso.example.com');
        SystemSetting::set('keycloak_realm', 'testrealm');
        SystemSetting::set('keycloak_client_id', 'client1');

        $response = $this->get(route('auth.keycloak.redirect'));
        $response->assertRedirect();
        $this->assertStringContainsString('https://sso.example.com/realms/testrealm/protocol/openid-connect/auth', $response->headers->get('Location'));
    }
}
