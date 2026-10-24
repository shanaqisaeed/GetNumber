<?php

namespace Tests\Feature\Api;

use App\Contracts\ApiTokenServiceInterface;
use App\Enums\ApiTokenStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected ApiTokenServiceInterface $tokenService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = app(ApiTokenServiceInterface::class);
    }

    public function test_guest_cannot_access_api_tokens_management(): void
    {
        $response = $this->get('/api-tokens');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_api_tokens_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api-tokens');
        $response->assertStatus(200);
    }

    public function test_user_can_create_api_token_and_receives_plain_text_token_in_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api-tokens', [
            'name' => 'سیستم CRM تست',
            'expires_days' => 30,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('plain_text_token');
        $response->assertSessionHas('success');

        $plainTextToken = session('plain_text_token');
        $this->assertStringStartsWith('gn_live_', $plainTextToken);

        $this->assertDatabaseHas('api_tokens', [
            'user_id' => $user->id,
            'name' => 'سیستم CRM تست',
            'status' => ApiTokenStatus::Active->value,
        ]);

        $tokenRecord = ApiToken::where('user_id', $user->id)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertEquals(hash('sha256', $plainTextToken), $tokenRecord->token_hash);
        $this->assertNotNull($tokenRecord->expires_at);
        $this->assertStringStartsWith('gn_live_', $tokenRecord->token_prefix);
    }

    public function test_api_v1_ping_with_valid_bearer_token_authenticates_and_updates_last_used(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $created = $this->tokenService->createToken($user, 'تست پینگ');
        $plainToken = $created['plain_text_token'];
        $tokenModel = $created['token'];

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$plainToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'name' => $user->name,
                    ],
                    'token' => [
                        'name' => 'تست پینگ',
                        'prefix' => $tokenModel->token_prefix,
                    ],
                ],
            ]);

        $tokenModel->refresh();
        $this->assertNotNull($tokenModel->last_used_at);
        $this->assertNotNull($tokenModel->last_used_ip);
    }

    public function test_api_v1_ping_with_valid_x_api_key_header_authenticates(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $created = $this->tokenService->createToken($user, 'تست X-API-Key');
        $plainToken = $created['plain_text_token'];

        $response = $this->withHeaders([
            'X-API-Key' => $plainToken,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                    ],
                ],
            ]);
    }

    public function test_api_v1_ping_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'MISSING_API_TOKEN',
            ]);
    }

    public function test_api_v1_ping_with_invalid_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer gn_live_invalidtoken1234567890abcdef',
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_API_TOKEN',
            ]);
    }

    public function test_api_v1_ping_with_revoked_token_returns_401(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $created = $this->tokenService->createToken($user, 'توکن باطل‌شده');
        $this->tokenService->revokeToken($created['token']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_API_TOKEN',
            ]);
    }

    public function test_api_v1_ping_with_expired_token_returns_401(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $created = $this->tokenService->createToken(
            user: $user,
            name: 'توکن منقضی',
            expiresAt: now()->subDay()
        );

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_API_TOKEN',
            ]);
    }

    public function test_api_v1_ping_with_inactive_user_returns_401(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Suspended]);
        $created = $this->tokenService->createToken($user, 'کاربر مسدود');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'code' => 'INVALID_API_TOKEN',
            ]);
    }

    public function test_user_can_rotate_token(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $created = $this->tokenService->createToken($user, 'توکن قابل چرخش');
        $oldPlainToken = $created['plain_text_token'];
        $tokenModel = $created['token'];

        $response = $this->actingAs($user)->post("/api-tokens/{$tokenModel->id}/rotate");

        $response->assertRedirect();
        $response->assertSessionHas('plain_text_token');
        $newPlainToken = session('plain_text_token');

        $this->assertNotEquals($oldPlainToken, $newPlainToken);

        // Old plain token fails
        $oldResp = $this->withHeaders([
            'Authorization' => "Bearer {$oldPlainToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');
        $oldResp->assertStatus(401);

        // New plain token succeeds
        $newResp = $this->withHeaders([
            'Authorization' => "Bearer {$newPlainToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');
        $newResp->assertStatus(200);
    }

    public function test_user_can_revoke_token(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $created = $this->tokenService->createToken($user, 'توکن جهت ابطال');
        $tokenModel = $created['token'];

        $response = $this->actingAs($user)->post("/api-tokens/{$tokenModel->id}/revoke");

        $response->assertRedirect();
        $tokenModel->refresh();
        $this->assertEquals(ApiTokenStatus::Revoked, $tokenModel->status);

        $apiResp = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');
        $apiResp->assertStatus(401);
    }

    public function test_user_can_delete_token(): void
    {
        $user = User::factory()->create();
        $created = $this->tokenService->createToken($user, 'توکن جهت حذف');
        $tokenModel = $created['token'];

        $response = $this->actingAs($user)->delete("/api-tokens/{$tokenModel->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('api_tokens', ['id' => $tokenModel->id]);
    }

    public function test_user_cannot_rotate_or_revoke_other_users_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $created = $this->tokenService->createToken($user1, 'توکن کاربر ۱');
        $tokenModel = $created['token'];

        $response = $this->actingAs($user2)->post("/api-tokens/{$tokenModel->id}/rotate");
        $response->assertStatus(403);

        $response2 = $this->actingAs($user2)->post("/api-tokens/{$tokenModel->id}/revoke");
        $response2->assertStatus(403);
    }

    public function test_admin_can_view_all_tokens_and_revoke_any_token(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $created = $this->tokenService->createToken($user, 'توکن کاربر برای ادمین');
        $tokenModel = $created['token'];

        $response = $this->actingAs($admin)->get('/admin/api-tokens');
        $response->assertStatus(200);

        $revokeResp = $this->actingAs($admin)->post("/admin/api-tokens/{$tokenModel->id}/revoke");
        $revokeResp->assertRedirect();

        $tokenModel->refresh();
        $this->assertEquals(ApiTokenStatus::Revoked, $tokenModel->status);
    }

    public function test_api_v1_numbers_returns_only_active_numbers_of_authenticated_user(): void
    {
        $user1 = User::factory()->create(['status' => UserStatus::Active]);
        $user2 = User::factory()->create(['status' => UserStatus::Active]);

        // User 1 numbers
        PhoneNumber::create([
            'number' => '10001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user1->id,
            'allocated_at' => now(),
        ]);
        PhoneNumber::create([
            'number' => '10002',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user1->id,
            'allocated_at' => now(),
        ]);
        // User 1 suspended number (should not be in active numbers)
        PhoneNumber::create([
            'number' => '10003',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Suspended,
            'user_id' => $user1->id,
        ]);

        // User 2 number
        PhoneNumber::create([
            'number' => '20001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user2->id,
            'allocated_at' => now(),
        ]);

        $created = $this->tokenService->createToken($user1, 'توکن لیست شماره‌ها');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/numbers');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 2,
            ]);

        $numbers = collect($response->json('data'))->pluck('number')->all();
        $this->assertContains('10001', $numbers);
        $this->assertContains('10002', $numbers);
        $this->assertNotContains('10003', $numbers);
        $this->assertNotContains('20001', $numbers);
    }

    public function test_admin_can_create_api_token_with_maximum_permissions_for_themselves(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($admin)->post('/admin/api-tokens', [
            'name' => 'کلید مدیریت مرکزی سرور',
            'expires_days' => 180,
            'abilities' => ['*'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('plain_text_token');
        $response->assertSessionHas('success');

        $plainTextToken = session('plain_text_token');
        $this->assertStringStartsWith('gn_live_', $plainTextToken);

        $this->assertDatabaseHas('api_tokens', [
            'user_id' => $admin->id,
            'name' => 'کلید مدیریت مرکزی سرور',
            'status' => ApiTokenStatus::Active->value,
        ]);

        $tokenRecord = ApiToken::where('user_id', $admin->id)->first();
        $this->assertTrue($tokenRecord->can('*'));
        $this->assertTrue($tokenRecord->can('numbers:manage'));
        $this->assertTrue($tokenRecord->can('messages:write'));

        // Test API ping with this token returns is_admin: true and has_full_access: true
        $pingResp = $this->withHeaders([
            'Authorization' => "Bearer {$plainTextToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/ping');

        $pingResp->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $admin->id,
                        'is_admin' => true,
                        'role' => 'admin',
                    ],
                    'token' => [
                        'name' => 'کلید مدیریت مرکزی سرور',
                        'has_full_access' => true,
                    ],
                ],
            ]);
    }

    public function test_admin_can_create_api_token_for_another_user_with_specific_abilities(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetUser = User::factory()->create(['status' => UserStatus::Active]);

        $response = $this->actingAs($admin)->post('/admin/api-tokens', [
            'name' => 'سیستم انبارداری کلاینت',
            'user_id' => $targetUser->id,
            'expires_days' => 60,
            'abilities' => ['numbers:read'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('plain_text_token');

        $this->assertDatabaseHas('api_tokens', [
            'user_id' => $targetUser->id,
            'name' => 'سیستم انبارداری کلاینت',
        ]);

        $tokenRecord = ApiToken::where('user_id', $targetUser->id)->first();
        $this->assertTrue($tokenRecord->can('numbers:read'));
        $this->assertFalse($tokenRecord->can('numbers:manage'));
    }

    public function test_admin_can_rotate_any_token_from_admin_panel(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $created = $this->tokenService->createToken($user, 'توکن کاربر عادی');
        $oldToken = $created['plain_text_token'];
        $tokenModel = $created['token'];

        $response = $this->actingAs($admin)->post("/admin/api-tokens/{$tokenModel->id}/rotate");

        $response->assertRedirect();
        $response->assertSessionHas('plain_text_token');
        $newToken = session('plain_text_token');

        $this->assertNotEquals($oldToken, $newToken);

        // Old token rejected
        $this->withHeaders(['Authorization' => "Bearer {$oldToken}"])
            ->getJson('/api/v1/ping')
            ->assertStatus(401);

        // New token accepted
        $this->withHeaders(['Authorization' => "Bearer {$newToken}"])
            ->getJson('/api/v1/ping')
            ->assertStatus(200);
    }

    public function test_admin_token_with_full_access_can_view_all_system_numbers_and_filter_by_user(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
        $user1 = User::factory()->create(['status' => UserStatus::Active]);
        $user2 = User::factory()->create(['status' => UserStatus::Active]);

        PhoneNumber::create([
            'number' => '50001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user1->id,
            'allocated_at' => now(),
        ]);
        PhoneNumber::create([
            'number' => '50002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $user2->id,
            'allocated_at' => now(),
        ]);
        PhoneNumber::create([
            'number' => '50003',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $created = $this->tokenService->createToken($admin, 'کلید ارشد ادمین', ['*']);
        $adminToken = $created['plain_text_token'];

        // 1. Admin without filter sees all numbers across the platform
        $allResp = $this->withHeaders([
            'Authorization' => "Bearer {$adminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/numbers');

        $allResp->assertStatus(200);
        $allNumbers = collect($allResp->json('data'))->pluck('number')->all();
        $this->assertContains('50001', $allNumbers);
        $this->assertContains('50002', $allNumbers);
        $this->assertContains('50003', $allNumbers);

        // 2. Admin filtered by user_id
        $filteredResp = $this->withHeaders([
            'Authorization' => "Bearer {$adminToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/numbers?user_id={$user1->id}");

        $filteredResp->assertStatus(200);
        $user1Numbers = collect($filteredResp->json('data'))->pluck('number')->all();
        $this->assertContains('50001', $user1Numbers);
        $this->assertNotContains('50002', $user1Numbers);
    }

    public function test_admin_token_can_assign_number_via_api(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
        $targetUser = User::factory()->create(['status' => UserStatus::Active]);

        $phone = PhoneNumber::create([
            'number' => '000060001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $created = $this->tokenService->createToken($admin, 'کلید ادمین تخصیص', ['*']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/numbers/assign', [
            'number' => '000060001',
            'user_id' => $targetUser->id,
            'display_title' => 'خط پشتیبانی اختصاصی',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'number' => '000060001',
                    'user_id' => $targetUser->id,
                    'status' => PhoneNumberStatus::Allocated->value,
                ],
            ]);

        $phone->refresh();
        $this->assertEquals(PhoneNumberStatus::Allocated, $phone->status);
        $this->assertEquals($targetUser->id, $phone->user_id);
    }

    public function test_regular_user_token_cannot_assign_number_via_api(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $targetUser = User::factory()->create(['status' => UserStatus::Active]);

        PhoneNumber::create([
            'number' => '000070001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $created = $this->tokenService->createToken($user, 'کلید کاربر عادی', ['*']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$created['plain_text_token']}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/numbers/assign', [
            'number' => '000070001',
            'user_id' => $targetUser->id,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'FORBIDDEN_ADMIN_ACTION',
            ]);
    }
}
