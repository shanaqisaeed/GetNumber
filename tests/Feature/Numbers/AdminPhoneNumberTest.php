<?php

namespace Tests\Feature\Numbers;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_admin_numbers(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::User,
        ]);

        $response = $this->actingAs($user)->get('/admin/numbers');
        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_numbers_list(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/numbers');
        $response->assertStatus(200);
    }

    public function test_admin_can_batch_generate_numbers(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/numbers', [
            'mode' => 'batch',
            'count' => 5,
            'digits' => 9,
            'type' => 'user_standard',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('phone_numbers', 5);
    }

    public function test_admin_can_search_users_for_assign(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'name' => 'کاربر آزمایشی',
            'username' => 'searchable_user',
        ]);

        $response = $this->actingAs($admin)->getJson('/admin/users/search?q=searchable');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'id' => $user->id,
            'username' => 'searchable_user',
        ]);
    }

    public function test_admin_can_create_single_number(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/numbers', [
            'mode' => 'single',
            'number' => '000555666',
            'type' => 'user_standard',
            'display_title' => 'شماره ویژه',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('phone_numbers', [
            'number' => '000555666',
            'display_title' => 'شماره ویژه',
            'status' => PhoneNumberStatus::Available->value,
        ]);
    }

    public function test_admin_can_change_number_status(): void
    {
        $admin = User::factory()->admin()->create();
        $phone = PhoneNumber::factory()->create([
            'status' => PhoneNumberStatus::Available,
        ]);

        $response = $this->actingAs($admin)->patch("/admin/numbers/{$phone->id}/status", [
            'status' => 'suspended',
            'reason' => 'تعلیق موقت آزمایشی',
        ]);

        $response->assertRedirect();
        $this->assertSame(PhoneNumberStatus::Suspended, $phone->fresh()->status);
    }

    public function test_admin_can_assign_number_to_user(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->create();

        $phone = PhoneNumber::factory()->create([
            'status' => PhoneNumberStatus::Available,
        ]);

        $response = $this->actingAs($admin)->post("/admin/numbers/{$phone->id}/assign", [
            'user_id' => $targetUser->id,
            'display_title' => 'خط تخصیص‌یافته توسط مدیر',
        ]);

        $response->assertRedirect();
        $this->assertSame($targetUser->id, $phone->fresh()->user_id);
        $this->assertSame(PhoneNumberStatus::Allocated, $phone->fresh()->status);
    }

    public function test_admin_can_release_number(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->create();

        $phone = PhoneNumber::factory()->create([
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $targetUser->id,
            'allocated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post("/admin/numbers/{$phone->id}/release");

        $response->assertRedirect();
        $this->assertNull($phone->fresh()->user_id);
        $this->assertSame(PhoneNumberStatus::Available, $phone->fresh()->status);
    }
}
