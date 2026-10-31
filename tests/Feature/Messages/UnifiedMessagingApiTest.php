<?php

namespace Tests\Feature\Messages;

use App\Enums\ApiTokenStatus;
use App\Enums\MessageDirection;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnifiedMessagingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
    protected User $admin;
    protected PhoneNumber $line1;
    protected PhoneNumber $line2;
    protected PhoneNumber $line3;
    protected PhoneNumber $platformLine;

    protected function setUp(): void
    {
        parent::setUp();

        PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'پیش‌شماره سازمانی پیش‌فرض',
                'country_code' => 'INTL',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $this->user1 = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->user2 = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->line1 = PhoneNumber::create([
            'number' => '000010001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user1->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $this->line2 = PhoneNumber::create([
            'number' => '000010002',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user2->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $this->line3 = PhoneNumber::create([
            'number' => '000010003',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user2->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);

        $this->platformLine = PhoneNumber::create([
            'number' => '9999',
            'type' => PhoneNumberType::PlatformService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->admin->id,
            'allocated_at' => now(),
            'display_title' => 'مرکز پیام سیستم',
            'accepts_incoming_sms' => false,
        ]);
    }

    protected function createApiToken(User $user, array $abilities = ['*']): array
    {
        $plainToken = 'gn_test_' . Str::random(40);

        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'تست وب‌سرویس',
            'token_hash' => hash('sha256', $plainToken),
            'token_prefix' => substr($plainToken, 0, 8),
            'abilities' => $abilities,
            'status' => ApiTokenStatus::Active,
            'last_used_at' => null,
            'expires_at' => null,
        ]);

        return [$token, $plainToken];
    }

    /**
     * Unified POST /api/v1/messages sends single outbound message successfully.
     */
    public function test_unified_api_sends_single_outbound_message_with_audit_log(): void
    {
        [, $plainToken] = $this->createApiToken($this->user1, ['messages:send']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'from' => $this->line1->number,
                'to' => $this->line2->formatted_number,
                'message' => 'سلام، این یک پیام آزمایشی وب‌سرویس یکپارچه است.',
                'client_message_id' => 'CL-OUT-001',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'direction' => 'outbound',
                    'sender' => $this->line1->number,
                    'destination_number' => $this->line2->number,
                    'body' => 'سلام، این یک پیام آزمایشی وب‌سرویس یکپارچه است.',
                    'client_message_id' => 'CL-OUT-001',
                    'is_duplicate' => false,
                ],
            ]);

        // Assert database records
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line2->number,
            'direction' => 'outbound',
        ]);

        // Inbound copy delivered to destination
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->line2->id,
            'destination_number' => $this->line2->number,
            'direction' => 'inbound',
        ]);

        // Audit trail recorded
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user1->id,
            'action' => 'api.message.sent',
        ]);
    }

    /**
     * Unified POST /api/v1/messages supports bulk dispatch to multiple destinations.
     */
    public function test_unified_api_supports_bulk_outbound_dispatch(): void
    {
        [, $plainToken] = $this->createApiToken($this->user1, ['messages:write']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'sender' => $this->line1->number,
                'destinations' => [
                    $this->line2->number,
                    $this->line3->formatted_number,
                ],
                'body' => 'پیام گروهی اطلاع‌رسانی از طریق وب‌سرویس',
                'client_message_id' => 'BATCH-TEST-100',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'sender' => $this->line1->number,
                    'total' => 2,
                    'sent_count' => 2,
                    'failed_count' => 0,
                    'is_duplicate' => false,
                ],
            ]);

        $this->assertCount(2, $response->json('data.results'));

        // Verify audit log for bulk
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user1->id,
            'action' => 'api.messages.bulk_sent',
        ]);
    }

    /**
     * Unified API guarantees idempotency for duplicate requests.
     */
    public function test_unified_api_enforces_idempotency(): void
    {
        [, $plainToken] = $this->createApiToken($this->user1, ['messages:send']);

        $payload = [
            'sender' => $this->line1->number,
            'destination_number' => $this->line2->number,
            'body' => 'پیام با کلید یکتا',
            'client_message_id' => 'IDEM-KEY-777',
        ];

        // 1st request -> 201
        $res1 = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', $payload);
        $res1->assertStatus(201)
            ->assertJson(['data' => ['is_duplicate' => false]]);

        // 2nd identical request -> 200 OK idempotent
        $res2 = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_duplicate' => true,
                    'client_message_id' => 'IDEM-KEY-777',
                ],
            ]);
    }

    /**
     * Token lacking write/send ability is rejected with 403.
     */
    public function test_unified_api_rejects_tokens_without_send_or_write_ability(): void
    {
        [, $plainToken] = $this->createApiToken($this->user1, ['messages:read']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'sender' => $this->line1->number,
                'destination_number' => $this->line2->number,
                'body' => 'تست عدم دسترسی',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ]);
    }

    /**
     * User cannot send from another user's allocated line.
     */
    public function test_user_cannot_send_from_line_they_do_not_own(): void
    {
        [, $plainToken] = $this->createApiToken($this->user1, ['messages:write']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages', [
                'sender' => $this->line2->number, // Line belongs to user2
                'destination_number' => $this->line1->number,
                'body' => 'تلاش برای جعل خط فرستنده',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Legacy webhook endpoint /api/v1/messages/inbound continues to operate seamlessly.
     */
    public function test_legacy_inbound_endpoint_backward_compatibility(): void
    {
        [, $plainToken] = $this->createApiToken($this->user1, ['messages:write']);

        $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/messages/inbound', [
                'destination_number' => $this->line1->formatted_number,
                'sender' => '989129990000',
                'body' => 'پیام ورودی از وب‌هوک درگاه قدیمی',
                'client_message_id' => 'LEGACY-WH-1',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'direction' => 'inbound',
                    'sender' => '989129990000',
                    'destination_number' => $this->line1->number,
                ],
            ]);
    }
}
