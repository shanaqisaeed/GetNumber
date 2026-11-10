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

    /**
     * Test user can have completely different passwords for username vs email identities in table 2.
     */
    public function test_user_can_have_different_passwords_for_username_and_email_identities(): void
    {
        $user = User::factory()->create([
            'username' => 'custom_user',
            'email' => 'custom@domain.com',
            'password' => Hash::make('FallbackPass123!'),
            'status' => UserStatus::Active,
        ]);

        // Table 2: Username credential is PasswordOne!
        $user->linkIdentity('username', 'custom_user', null, [], 'PasswordOne!');
        // Table 2: Email credential is PasswordTwo!
        $user->linkIdentity('email', 'custom@domain.com', 'custom@domain.com', [], 'PasswordTwo!');

        // 1. Logging in with username and PasswordOne! must succeed
        $response1 = $this->post(route('login'), [
            'auth_type' => 'username',
            'username' => 'custom_user',
            'password' => 'PasswordOne!',
        ]);
        $response1->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        // 2. Logging in with username and Email's password (PasswordTwo!) must fail
        $response2 = $this->post(route('login'), [
            'auth_type' => 'username',
            'username' => 'custom_user',
            'password' => 'PasswordTwo!',
        ]);
        $response2->assertSessionHasErrors('username');
        $this->assertGuest();

        // 3. Logging in with email and PasswordTwo! must succeed
        $response3 = $this->post(route('login'), [
            'auth_type' => 'email',
            'email' => 'custom@domain.com',
            'password' => 'PasswordTwo!',
        ]);
        $response3->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        // 4. Logging in with email and Username's password (PasswordOne!) must fail
        $response4 = $this->post(route('login'), [
            'auth_type' => 'email',
            'email' => 'custom@domain.com',
            'password' => 'PasswordOne!',
        ]);
        $response4->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Test user can set or update dedicated password for an identity.
     */
    public function test_user_can_update_dedicated_identity_password(): void
    {
        $user = User::factory()->create([
            'username' => 'pwd_updater',
            'email' => 'update_pwd@domain.com',
            'password' => Hash::make('InitialPass123!'),
            'status' => UserStatus::Active,
        ]);

        $user->linkIdentity('username', 'pwd_updater', null, [], 'InitialPass123!');
        $user->linkIdentity('email', 'update_pwd@domain.com', 'update_pwd@domain.com', [], 'InitialPass123!');

        // Update dedicated password for email
        $response = $this->actingAs($user)->put(route('profile.identities.password.update', 'email'), [
            'current_password' => 'InitialPass123!',
            'password' => 'BrandNewEmailPass888!',
            'password_confirmation' => 'BrandNewEmailPass888!',
        ]);

        $response->assertSessionHas('success');

        auth()->logout();

        // New email password works
        $loginRes = $this->post(route('login'), [
            'auth_type' => 'email',
            'email' => 'update_pwd@domain.com',
            'password' => 'BrandNewEmailPass888!',
        ]);
        $loginRes->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
