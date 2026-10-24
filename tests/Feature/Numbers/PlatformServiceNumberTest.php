<?php

namespace Tests\Feature\Numbers;

use App\Contracts\PhoneNumberAllocationServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\NumberAllocationException;
use App\Models\ApiToken;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformServiceNumberTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $user;
    protected PhoneNumber $userNumber;
    protected PhoneNumber $platformNumber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'sys_admin',
            'name' => 'مدیر سامانه',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->user = User::factory()->create([
            'username' => 'regular_user',
            'name' => 'کاربر عادی',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->userNumber = PhoneNumber::create([
            'number' => '000010001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'allocated_at' => now(),
            'display_title' => null,
            'accepts_incoming_sms' => true,
        ]);

        $this->platformNumber = PhoneNumber::create([
            'number' => '2000',
            'type' => PhoneNumberType::PlatformService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->admin->id,
            'allocated_at' => now(),
            'display_title' => 'مرکز اعلانات پلتفرم',
            'accepts_incoming_sms' => true,
        ]);
    }

    /**
     * Helper to generate a valid API token for testing.
     */
    protected function createApiToken(User $user, array $abilities = ['*']): array
    {
        $plainToken = 'gn_' . Str::random(40);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'تست توکن',
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => $abilities,
            'status' => 'active',
        ]);

        return [$token, $plainToken];
    }

    public function test_admin_can_create_platform_service_number_with_display_title_and_incoming_flag(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/numbers', [
            'mode' => 'single',
            'number' => '3000',
            'type' => 'platform_service',
            'display_title' => 'سامانه اطلاع‌رسانی پلتفرم',
            'accepts_incoming_sms' => false,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('phone_numbers', [
            'number' => '3000',
            'type' => PhoneNumberType::PlatformService->value,
            'display_title' => 'سامانه اطلاع‌رسانی پلتفرم',
            'accepts_incoming_sms' => false,
            'status' => PhoneNumberStatus::Allocated->value,
        ]);
    }

    public function test_platform_service_number_cannot_be_assigned_to_regular_user_in_admin(): void
    {
        $response = $this->actingAs($this->admin)->post("/admin/numbers/{$this->platformNumber->id}/assign", [
            'user_id' => $this->user->id,
            'display_title' => 'تلاش انتساب',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('phone_numbers', [
            'id' => $this->platformNumber->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_platform_service_number_is_excluded_from_pool_allocation_to_regular_users(): void
    {
        $availablePlatform = PhoneNumber::create([
            'number' => '4000',
            'type' => PhoneNumberType::PlatformService,
            'status' => PhoneNumberStatus::Available,
            'display_title' => 'پلتفرم آزاد',
            'accepts_incoming_sms' => true,
        ]);

        $allocationService = app(PhoneNumberAllocationServiceInterface::class);

        // When user allocates standard number, it should never pick available platform number
        $allocated = $allocationService->allocateStandardNumberToUser($this->user);

        $this->assertNotSame($availablePlatform->number, $allocated->number);
        $this->assertSame(PhoneNumberType::UserStandard, $allocated->type);
    }

    public function test_regular_user_cannot_allocate_specific_platform_service_number(): void
    {
        $this->expectException(NumberAllocationException::class);

        $allocationService = app(PhoneNumberAllocationServiceInterface::class);
        $allocationService->allocateServiceNumberToUser(
            user: $this->user,
            serviceNumber: '2000',
            displayTitle: 'جعل خط پلتفرم'
        );
    }

    public function test_regular_user_cannot_send_sms_to_platform_service_number(): void
    {
        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->userNumber->id,
            'destinations' => ['2000'],
            'body' => 'سلام به خط پلتفرم',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'sent_count' => 0,
                'failed_count' => 1,
            ]);

        $this->assertDatabaseMissing('messages', [
            'destination_number' => '2000',
            'sender' => $this->userNumber->number,
        ]);
    }

    public function test_regular_user_cannot_send_sms_from_platform_service_number(): void
    {
        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->platformNumber->id,
            'destinations' => [$this->userNumber->number],
            'body' => 'پیام جعلی از پلتفرم',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_send_sms_from_platform_service_number(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/admin/messages/send', [
            'from_number_id' => $this->platformNumber->id,
            'destinations' => [$this->userNumber->number],
            'body' => 'اطلاعیه رسمی سامانه به کاربر',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'sent_count' => 1,
                'failed_count' => 0,
            ]);

        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->platformNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => $this->platformNumber->number,
            'direction' => MessageDirection::Outbound->value,
        ]);
    }

    public function test_regular_user_api_token_cannot_record_inbound_messages_to_platform_service_number(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '2000',
                'sender' => 'OUTSIDE_SENDER',
                'body' => 'تلاش برای ثبت پیام در خط پلتفرم',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_api_token_can_record_inbound_messages_to_platform_service_number(): void
    {
        [, $plainToken] = $this->createApiToken($this->admin, ['*']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '2000',
                'sender' => 'TELECOM_GATEWAY',
                'body' => 'پیام ورودی معتبر به خط پلتفرم',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'destination_number' => '2000',
                    'destination_title' => 'مرکز اعلانات پلتفرم',
                ],
            ]);
    }

    public function test_inbound_message_rejected_if_destination_line_has_incoming_sms_disabled(): void
    {
        $oneWayLine = PhoneNumber::create([
            'number' => '5000',
            'type' => PhoneNumberType::PlatformService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->admin->id,
            'allocated_at' => now(),
            'display_title' => 'خط یک‌طرفه اطلاع‌رسانی',
            'accepts_incoming_sms' => false,
        ]);

        [, $plainToken] = $this->createApiToken($this->admin, ['*']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '5000',
                'sender' => 'GATEWAY',
                'body' => 'پیام ورودی که باید رد شود',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_number']);
    }

    public function test_display_title_policy_uses_service_title_and_falls_back_to_plus_85_number(): void
    {
        // 1. With display_title
        $this->assertSame('مرکز اعلانات پلتفرم', $this->platformNumber->effective_title);

        // 2. Without display_title
        $rawNumber = PhoneNumber::create([
            'number' => '6000',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'display_title' => null,
        ]);

        $this->assertSame('+856000', $rawNumber->effective_title);
    }

    public function test_regular_user_cannot_update_or_release_platform_service_number(): void
    {
        // Regular user attempts to update platform line title
        $response = $this->actingAs($this->user)->patch("/numbers/{$this->platformNumber->id}", [
            'display_title' => 'جعل عنوان',
        ]);
        $response->assertStatus(403);

        // Regular user attempts to delete/release platform line
        $delResponse = $this->actingAs($this->user)->delete("/numbers/{$this->platformNumber->id}");
        $delResponse->assertStatus(403);
    }
}
