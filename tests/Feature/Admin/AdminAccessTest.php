<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_when_accessing_admin(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/login');
    }

    public function test_regular_user_is_forbidden_from_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $response = $this->actingAs($user)->get('/admin');
        $response->assertStatus(403);
    }

    public function test_regular_user_is_forbidden_from_admin_users_list(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $response = $this->actingAs($user)->get('/admin/users');
        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_admin_users_list(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertStatus(200);
    }

    public function test_admin_can_create_a_new_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'کاربر آزمایشی جدید',
            'username' => 'created_by_admin',
            'password' => 'SecurePass@123',
            'password_confirmation' => 'SecurePass@123',
            'role' => 'user',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', [
            'username' => 'created_by_admin',
            'name' => 'کاربر آزمایشی جدید',
            'role' => UserRole::User->value,
            'status' => UserStatus::Active->value,
        ]);
    }

    public function test_admin_can_suspend_and_activate_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        // Suspend user
        $response = $this->actingAs($admin)->patch(route('admin.users.status', $targetUser), [
            'status' => 'suspended',
        ]);

        $response->assertRedirect();
        $this->assertSame(UserStatus::Suspended, $targetUser->fresh()->status);

        // Reactivate user
        $response = $this->actingAs($admin)->patch(route('admin.users.status', $targetUser), [
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertSame(UserStatus::Active, $targetUser->fresh()->status);
    }

    public function test_admin_cannot_suspend_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->patch(route('admin.users.status', $admin), [
            'status' => 'suspended',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
    }

    public function test_suspended_user_session_is_terminated_by_middleware(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        // Logged in user accesses dashboard
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertStatus(200);

        // Now user is suspended
        $user->update(['status' => UserStatus::Suspended]);

        // Next request triggers logout and redirect
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }
}
