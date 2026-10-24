<?php

namespace Tests\Feature\Admin;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhonePrefixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_guest_cannot_access_admin_prefixes(): void
    {
        $response = $this->get('/admin/prefixes');

        $response->assertRedirect('/login');
    }

    public function test_regular_user_cannot_access_admin_prefixes(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/prefixes');

        $response->assertStatus(403);
    }

    public function test_admin_can_view_prefixes_index(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/prefixes');

        $response->assertStatus(200);
    }

    public function test_admin_can_create_new_prefix(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/prefixes', [
            'prefix' => '+98',
            'name' => 'پیش‌شماره ملی ایران',
            'country_code' => 'IR',
            'is_active' => true,
            'is_default' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('phone_prefixes', [
            'prefix' => '+98',
            'clean_prefix' => '98',
            'name' => 'پیش‌شماره ملی ایران',
            'country_code' => 'IR',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    public function test_admin_can_set_new_prefix_as_default(): void
    {
        $prefix = PhonePrefix::create([
            'prefix' => '+98',
            'clean_prefix' => '98',
            'name' => 'پیش‌شماره ایران',
            'country_code' => 'IR',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->patch("/admin/prefixes/{$prefix->id}", [
            'name' => 'پیش‌شماره ایران',
            'is_default' => true,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertTrue($prefix->fresh()->is_default);

        // Verify other prefixes are no longer default
        $this->assertEquals(1, PhonePrefix::where('is_default', true)->count());
    }

    public function test_admin_cannot_deactivate_default_prefix(): void
    {
        $defaultPrefix = PhonePrefix::getDefaultPrefix();

        $response = $this->actingAs($this->admin)->patch("/admin/prefixes/{$defaultPrefix->id}", [
            'name' => $defaultPrefix->name,
            'is_default' => true,
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertTrue($defaultPrefix->fresh()->is_active);
    }

    public function test_admin_cannot_delete_prefix_with_linked_numbers(): void
    {
        $prefix = PhonePrefix::create([
            'prefix' => '+90',
            'clean_prefix' => '90',
            'name' => 'پیش‌شماره ترکیه',
            'is_default' => false,
            'is_active' => true,
        ]);

        // Link a phone number
        PhoneNumber::create([
            'phone_prefix_id' => $prefix->id,
            'number' => '000010001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $response = $this->actingAs($this->admin)->delete("/admin/prefixes/{$prefix->id}");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('phone_prefixes', ['id' => $prefix->id]);
    }

    public function test_admin_can_delete_unused_non_default_prefix(): void
    {
        $prefix = PhonePrefix::create([
            'prefix' => '+44',
            'clean_prefix' => '44',
            'name' => 'پیش‌شماره بریتانیا',
            'is_default' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->delete("/admin/prefixes/{$prefix->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('phone_prefixes', ['id' => $prefix->id]);
    }
}
