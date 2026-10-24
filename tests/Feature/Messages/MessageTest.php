<?php

namespace Tests\Feature\Messages;

use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected User $admin;
    protected PhoneNumber $userNumber;
    protected PhoneNumber $otherUserNumber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->otherUser = User::factory()->create([
            'username' => 'otheruser',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->admin = User::factory()->create([
            'username' => 'superadmin',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->userNumber = PhoneNumber::create([
            'number' => '000010001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'allocated_at' => now(),
        ]);

        $this->otherUserNumber = PhoneNumber::create([
            'number' => '000010002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->otherUser->id,
            'allocated_at' => now(),
        ]);
    }

    /**
     * Helper to create an active API token with plain text value.
     */
    protected function createApiToken(User $user, array $abilities = ['*']): array
    {
        $plain = 'gn_'.Str::random(40);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'Test Token',
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 7),
            'abilities' => $abilities,
            'is_active' => true,
        ]);

        return [$token, $plain];
    }

    public function test_inbound_message_api_records_message_successfully(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', [
                'destination_number' => '+85000010001',
                'sender' => '989121111111',
                'body' => 'کد تایید ورود شما: ۱۲۳۴۵',
                'source_system_id' => 'GATEWAY-A',
                'client_message_id' => 'MSG-UNIQUE-001',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'destination_number' => '000010001',
                    'sender' => '989121111111',
                    'body' => 'کد تایید ورود شما: ۱۲۳۴۵',
                    'client_message_id' => 'MSG-UNIQUE-001',
                    'source_system_id' => 'GATEWAY-A',
                    'processing_status' => 'received',
                    'is_duplicate' => false,
                ],
            ]);

        $this->assertDatabaseHas('messages', [
            'destination_number' => '000010001',
            'client_message_id' => 'MSG-UNIQUE-001',
            'source_system_id' => 'GATEWAY-A',
            'phone_number_id' => $this->userNumber->id,
        ]);
    }

    public function test_inbound_message_api_handles_plus_85_prefix_normalization(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', [
                'destination_number' => '+85000010001',
                'sender' => 'INFO',
                'body' => 'پیام با پیشوند بین‌المللی',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'destination_number' => '000010001',
                ],
            ]);
    }

    public function test_inbound_message_fails_if_destination_number_missing_full_prefix(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        // Sent without +85 prefix
        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '000010001',
                'sender' => '989121111111',
                'body' => 'پیام بدون پیش‌شماره کامل',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_number'])
            ->assertJson([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
            ]);
    }

    public function test_inbound_message_fails_if_destination_number_has_inactive_or_invalid_prefix(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        // Sent with unregistered prefix (+99)
        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '+99000010001',
                'sender' => '989121111111',
                'body' => 'پیش‌شماره نامعتبر',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_number']);
    }

    public function test_inbound_message_fails_if_destination_number_length_invalid(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        // Too short (only 2 digits after prefix: +8512)
        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '+8512',
                'sender' => '989121111111',
                'body' => 'طول خیلی کوتاه',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_number']);
    }

    public function test_inbound_message_fails_if_token_lacks_write_ability(): void
    {
        // Token has only messages:read ability
        [, $plainToken] = $this->createApiToken($this->user, ['messages:read']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'destination_number' => '+85000010001',
                'sender' => '989121111111',
                'body' => 'تست عدم دسترسی کلید',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ]);
    }

    public function test_inbound_message_api_enforces_idempotency_with_client_message_id(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        $payload = [
            'destination_number' => '+85000010001',
            'sender' => 'PAYMENT_GATEWAY',
            'body' => 'تراکنش با موفقیت انجام شد.',
            'source_system_id' => 'BANK_MELLAT',
            'client_message_id' => 'TXN-99887766',
        ];

        // 1st request -> Created 201
        $res1 = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', $payload);
        $res1->assertStatus(201)
            ->assertJson(['data' => ['is_duplicate' => false]]);

        // 2nd identical request -> Ok 200, idempotent duplicate
        $res2 = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_duplicate' => true,
                    'client_message_id' => 'TXN-99887766',
                ],
            ]);

        // Verify only 1 record exists in database
        $this->assertEquals(
            1,
            Message::where('client_message_id', 'TXN-99887766')->count()
        );
    }

    public function test_inbound_message_fails_if_destination_number_not_allocated(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        // Create an available unallocated number
        PhoneNumber::create([
            'number' => '000099999',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Available,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', [
                'destination_number' => '+85000099999',
                'sender' => '989121111111',
                'body' => 'تست ارسال به خط آزاد',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_number']);
    }

    public function test_user_api_token_cannot_record_message_for_another_users_number(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:write']);

        // Target is otherUser's number
        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', [
                'destination_number' => '+85' . $this->otherUserNumber->number,
                'sender' => 'ATTACKER',
                'body' => 'تلاش برای ثبت پیام برای خط کاربر دیگر',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_api_token_can_record_message_for_any_users_number(): void
    {
        [, $plainAdminToken] = $this->createApiToken($this->admin, ['*']);

        $response = $this->withHeader('Authorization', "Bearer {$plainAdminToken}")
            ->postJson('/api/v1/messages/inbound', [
                'destination_number' => '+85' . $this->userNumber->number,
                'sender' => 'ADMIN_SYSTEM',
                'body' => 'پیام سیستمی ارسال‌شده توسط ادمین',
            ]);

        $response->assertStatus(201);
    }

    public function test_public_api_docs_is_accessible_without_authentication(): void
    {
        $response = $this->get('/docs/api');

        $response->assertStatus(200);
    }

    public function test_token_secrecy_plain_token_is_never_leaked_in_token_list(): void
    {
        [$token, $plainToken] = $this->createApiToken($this->user);

        $response = $this->actingAs($this->user)->get('/api-tokens');

        $response->assertStatus(200);
        // The plain secret should NOT be present in page response
        $this->assertStringNotContainsString($plainToken, $response->getContent());
    }

    public function test_user_can_view_their_messages_page_and_data_is_isolated(): void
    {
        // Message for User
        Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'TEST_SENDER_1',
            'body' => 'پیام کاربر اول',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        // Message for OtherUser
        Message::create([
            'phone_number_id' => $this->otherUserNumber->id,
            'destination_number' => $this->otherUserNumber->number,
            'sender' => 'TEST_SENDER_2',
            'body' => 'پیام کاربر دوم',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $response = $this->actingAs($this->user)->get('/messages');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Messages/Index')
                ->has('conversations', 1)
                ->where('conversations.0.latest_message.body', 'پیام کاربر اول')
                ->where('stats.total_messages', 1)
            );
    }

    public function test_user_messages_poll_returns_only_new_records_after_specified_id(): void
    {
        $msg1 = Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'SENDER1',
            'body' => 'پیام اول',
            'server_received_at' => now()->subMinutes(5),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $msg2 = Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'SENDER2',
            'body' => 'پیام دوم',
            'server_received_at' => now()->subMinutes(2),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $msg3 = Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'SENDER3',
            'body' => 'پیام سوم',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        // Poll after msg2
        $response = $this->actingAs($this->user)->getJson("/messages/poll?after_id={$msg2->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 1,
                'data' => [
                    [
                        'id' => $msg3->id,
                        'body' => 'پیام سوم',
                    ],
                ],
            ]);
    }

    public function test_admin_can_view_all_system_messages_and_delete_message(): void
    {
        $msg = Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'SPAMMER',
            'body' => 'پیام هرزنامه که باید حذف شود',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        // Admin views overview
        $response = $this->actingAs($this->admin)->get('/admin/messages');
        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Messages/Index')
                ->has('messages.data', 1)
            );

        // Admin deletes message
        $deleteResponse = $this->actingAs($this->admin)->delete("/admin/messages/{$msg->id}");
        $deleteResponse->assertRedirect();

        $this->assertDatabaseMissing('messages', [
            'id' => $msg->id,
        ]);
    }

    public function test_regular_user_cannot_access_admin_messages(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/messages');
        $response->assertStatus(403);
    }

    public function test_api_v1_messages_index_returns_paginated_user_messages(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:read']);

        Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'GATEWAY',
            'body' => 'پیام شماره ۱',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->getJson('/api/v1/messages');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'pagination' => [
                    'total' => 1,
                ],
            ]);
    }

    public function test_api_v1_messages_poll_returns_new_messages_after_id(): void
    {
        [, $plainToken] = $this->createApiToken($this->user, ['messages:read']);

        $msg1 = Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'S1',
            'body' => 'پیام ۱',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $msg2 = Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->userNumber->number,
            'sender' => 'S2',
            'body' => 'پیام ۲ جدید',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->getJson("/api/v1/messages/poll?after_id={$msg1->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 1,
                'data' => [
                    [
                        'id' => $msg2->id,
                        'body' => 'پیام ۲ جدید',
                    ],
                ],
            ]);
    }

    public function test_user_can_send_single_sms_to_another_user_line(): void
    {
        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->userNumber->id,
            'destinations' => [$this->otherUserNumber->number],
            'body' => 'سلام، این یک پیامک تستی بین دو خط است.',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sent_count' => 1,
                'failed_count' => 0,
            ]);

        // Assert Outbound message created for sender
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->otherUserNumber->number,
            'sender' => $this->userNumber->number,
            'direction' => 'outbound',
            'body' => 'سلام، این یک پیامک تستی بین دو خط است.',
        ]);

        // Assert Inbound message created for recipient
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->otherUserNumber->id,
            'destination_number' => $this->otherUserNumber->number,
            'sender' => $this->userNumber->number,
            'direction' => 'inbound',
            'body' => 'سلام، این یک پیامک تستی بین دو خط است.',
        ]);
    }

    public function test_user_can_send_bulk_sms_to_multiple_recipients(): void
    {
        // Third user and number
        $thirdUser = User::factory()->create([
            'username' => 'thirduser',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $thirdNumber = PhoneNumber::create([
            'number' => '000010003',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $thirdUser->id,
            'allocated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->userNumber->id,
            'destinations' => [$this->otherUserNumber->number, $thirdNumber->number],
            'body' => 'پیامک گروهی سازمانی به تمام اعضا',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sent_count' => 2,
                'failed_count' => 0,
            ]);

        $this->assertEquals(2, Message::where('phone_number_id', $this->userNumber->id)->where('direction', 'outbound')->count());
        $this->assertDatabaseHas('messages', ['phone_number_id' => $thirdNumber->id, 'direction' => 'inbound']);
    }

    public function test_sms_to_non_9_digit_platform_service_number_is_rejected(): void
    {
        // Create a 5-digit platform service line
        $platformNumber = PhoneNumber::create([
            'number' => '10001',
            'type' => PhoneNumberType::PlatformService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->admin->id,
            'display_title' => 'سرشماره اطلاع‌رسانی پلتفرم',
            'accepts_incoming_sms' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->userNumber->id,
            'destinations' => [$platformNumber->number],
            'body' => 'پیام به سرشماره پلتفرم',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => false,
                'sent_count' => 0,
                'failed_count' => 1,
            ]);

        $this->assertStringContainsString('توسط کاربران عادی مجاز نمی‌باشد', $response->json('results.0.reason'));
    }

    public function test_sms_to_service_number_with_incoming_disabled_is_rejected(): void
    {
        // Create 6-digit user service line with accepts_incoming_sms = false
        $serviceNumber = PhoneNumber::create([
            'number' => '800001',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->otherUser->id,
            'display_title' => 'سامانه پیام صوتی یک‌طرفه',
            'accepts_incoming_sms' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->userNumber->id,
            'destinations' => [$serviceNumber->number],
            'body' => 'تست ارسال به خط یک‌طرفه',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => false,
                'sent_count' => 0,
                'failed_count' => 1,
            ]);

        $this->assertStringContainsString('صرفاً یک‌طرفه', $response->json('results.0.reason'));
    }

    public function test_sms_to_active_user_service_number_with_incoming_enabled_succeeds(): void
    {
        // Create 5-digit user service line with accepts_incoming_sms = true
        $serviceNumber = PhoneNumber::create([
            'number' => '90001',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->otherUser->id,
            'display_title' => 'پشتیبانی ۲۴ ساعته مشتریان',
            'accepts_incoming_sms' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $this->userNumber->id,
            'destinations' => [$serviceNumber->number],
            'body' => 'سلام، درخواست راهنمایی دارم.',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sent_count' => 1,
                'failed_count' => 0,
            ]);
    }

    public function test_conversations_search_filters_by_text_body(): void
    {
        Message::create([
            'phone_number_id' => $this->userNumber->id,
            'destination_number' => $this->otherUserNumber->number,
            'sender' => $this->userNumber->number,
            'peer_number' => $this->otherUserNumber->number,
            'direction' => 'outbound',
            'body' => 'کلمه_منحصر_بفرد_جستجو در چت',
            'server_received_at' => now(),
            'processing_status' => MessageProcessingStatus::Processed,
        ]);

        $response = $this->actingAs($this->user)->get('/messages?phone_number_id='.$this->userNumber->id.'&search=کلمه_منحصر_بفرد');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Messages/Index')
                ->has('conversations', 1)
                ->where('conversations.0.peer_number', $this->otherUserNumber->number)
            );
    }

    public function test_user_can_send_sms_between_their_own_two_allocated_lines_and_view_in_both_threads(): void
    {
        // User owns line 1000 and line 1001
        $line1000 = PhoneNumber::create([
            'number' => '1000',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'display_title' => 'خط پشتیبانی من',
            'accepts_incoming_sms' => true,
        ]);

        $line1001 = PhoneNumber::create([
            'number' => '1001',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'display_title' => 'خط فروش من',
            'accepts_incoming_sms' => true,
        ]);

        // Send from 1000 to +85 1001
        $response = $this->actingAs($this->user)->postJson('/messages/send', [
            'from_number_id' => $line1000->id,
            'destinations' => ['+85 1001'],
            'body' => 'پیام آزمایشی بین دو خط خودم',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'sent_count' => 1,
                'failed_count' => 0,
            ]);

        // Verify conversation for line 1000 has 1001 with is_own_number = true
        $resLine1000 = $this->actingAs($this->user)->get("/messages?phone_number_id={$line1000->id}&peer=1001");
        $resLine1000->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Messages/Index')
                ->has('conversations', 1)
                ->where('conversations.0.peer_number', '1001')
                ->where('conversations.0.is_own_number', true)
                ->has('activeMessages', 1)
                ->where('activeMessages.0.body', 'پیام آزمایشی بین دو خط خودم')
                ->where('activeMessages.0.direction', 'outbound')
            );

        // Verify conversation for line 1001 has 1000 with is_own_number = true and inbound message
        $resLine1001 = $this->actingAs($this->user)->get("/messages?phone_number_id={$line1001->id}&peer=1000");
        $resLine1001->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Messages/Index')
                ->has('conversations', 1)
                ->where('conversations.0.peer_number', '1000')
                ->where('conversations.0.is_own_number', true)
                ->has('activeMessages', 1)
                ->where('activeMessages.0.body', 'پیام آزمایشی بین دو خط خودم')
                ->where('activeMessages.0.direction', 'inbound')
            );
    }
}
