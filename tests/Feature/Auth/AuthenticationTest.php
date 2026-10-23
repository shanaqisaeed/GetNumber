<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $response = $this->post('/login', [
            'username' => 'testuser',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_admin_is_redirected_to_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'adminboss',
        ]);

        $response = $this->post('/login', [
            'username' => 'adminboss',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser2',
        ]);

        $this->post('/login', [
            'username' => 'testuser2',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_nonexistent_username(): void
    {
        $this->post('/login', [
            'username' => 'nonexistent',
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_suspended_user_is_rejected_on_login(): void
    {
        $user = User::factory()->suspended()->create([
            'username' => 'banneduser',
        ]);

        $response = $this->post('/login', [
            'username' => 'banneduser',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username']);
    }

    public function test_inactive_user_is_rejected_on_login(): void
    {
        $user = User::factory()->inactive()->create([
            'username' => 'pendinguser',
        ]);

        $response = $this->post('/login', [
            'username' => 'pendinguser',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['username']);
    }

    public function test_login_rate_limiting_locks_out_after_too_many_attempts(): void
    {
        $user = User::factory()->create([
            'username' => 'ratelimituser',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'username' => 'ratelimituser',
                'password' => 'wrong-password',
            ]);
        }

        // 6th attempt should be locked out
        $response = $this->post('/login', [
            'username' => 'ratelimituser',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors(['username']);
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
