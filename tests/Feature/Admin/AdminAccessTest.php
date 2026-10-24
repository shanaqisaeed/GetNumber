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

    public function test_admin_can_update_user_identity_details(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->create([
            'name' => 'نام قدیمی',
            'username' => 'old_username',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $targetUser), [
            'name' => 'نام جدید ویرایش شده',
            'username' => 'new_username_123',
            'role' => 'admin',
            'status' => 'suspended',
            'password' => 'NewPassword@999',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $targetUser->refresh();
        $this->assertSame('نام جدید ویرایش شده', $targetUser->name);
        $this->assertSame('new_username_123', $targetUser->username);
        $this->assertSame(UserRole::Admin, $targetUser->role);
        $this->assertSame(UserStatus::Suspended, $targetUser->status);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPassword@999', $targetUser->password));
    }

    public function test_admin_can_fetch_user_allocated_numbers_json(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        \App\Models\PhoneNumber::factory()->create([
            'user_id' => $user->id,
            'number' => '98900111222',
            'status' => \App\Enums\PhoneNumberStatus::Allocated,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.numbers', $user));

        $response->assertOk();
        $response->assertJsonStructure([
            'user' => ['id', 'name', 'username'],
            'numbers' => [
                '*' => ['id', 'number', 'formatted_number', 'status', 'type', 'display_title']
            ]
        ]);
        $response->assertJsonFragment(['number' => '98900111222']);
    }

    public function test_admin_can_search_users_by_phone_number_and_username(): void
    {
        $admin = User::factory()->admin()->create();
        $userWithNumber = User::factory()->create([
            'username' => 'special_target_user',
        ]);

        \App\Models\PhoneNumber::factory()->create([
            'user_id' => $userWithNumber->id,
            'number' => '98900999888',
        ]);

        $otherUser = User::factory()->create([
            'username' => 'regular_person',
        ]);

        // Search by phone number
        $response = $this->actingAs($admin)->get(route('admin.users.index', ['search' => '98900999888']));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->where('users.total', 1)
            ->where('users.data.0.username', 'special_target_user')
        );

        // Search by username
        $response = $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'regular_person']));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->where('users.total', 1)
            ->where('users.data.0.username', 'regular_person')
        );
    }
}
