<?php

namespace Tests\Feature\Messages;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminMessageChatTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $userAli;
    protected User $userReza;
    protected PhoneNumber $lineAli;
    protected PhoneNumber $lineReza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'admin_super',
            'name' => 'مدیر ارشد سیستم',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->userAli = User::factory()->create([
            'username' => 'ali_rezaei',
            'name' => 'علی رضایی',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->userReza = User::factory()->create([
            'username' => 'reza_mohammadi',
            'name' => 'رضا محمدی',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->lineAli = PhoneNumber::create([
            'number' => '1000',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->userAli->id,
            'display_title' => 'پشتیبانی علی',
            'accepts_incoming_sms' => true,
        ]);

        $this->lineReza = PhoneNumber::create([
            'number' => '1001',
            'type' => PhoneNumberType::UserService,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->userReza->id,
            'display_title' => 'فروش رضا',
            'accepts_incoming_sms' => true,
        ]);
    }

    public function test_admin_can_view_conversations_with_both_parties_details(): void
    {
        // Ali sends to Reza
        $this->actingAs($this->userAli)->postJson('/messages/send', [
            'from_number_id' => $this->lineAli->id,
            'destinations' => ['1001'],
            'body' => 'سلام رضا، وضعیت سفارش چطوره؟',
        ])->assertStatus(200);

        // Admin opens messages
        $response = $this->actingAs($this->admin)->get('/admin/messages');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Messages/Index')
                ->has('conversations')
                ->where('conversations.0.line_a.number', '1000')
                ->where('conversations.0.line_b.number', '1001')
                ->where('conversations.0.line_a.user.username', 'ali_rezaei')
                ->where('conversations.0.line_b.user.username', 'reza_mohammadi')
                ->has('messages.data')
            );
    }

    public function test_admin_can_load_conversation_messages_json(): void
    {
        // Ali sends to Reza
        $this->actingAs($this->userAli)->postJson('/messages/send', [
            'from_number_id' => $this->lineAli->id,
            'destinations' => ['1001'],
            'body' => 'پیام آزمایشی یک',
        ]);

        // Reza replies to Ali
        $this->actingAs($this->userReza)->postJson('/messages/send', [
            'from_number_id' => $this->lineReza->id,
            'destinations' => ['1000'],
            'body' => 'پاسخ آزمایشی دو',
        ]);

        $res = $this->actingAs($this->admin)->getJson("/admin/messages/conversation?phone_number_id={$this->lineAli->id}&peer_number=1001");

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.body', 'پیام آزمایشی یک')
            ->assertJsonPath('messages.0.sender.number', '1000')
            ->assertJsonPath('messages.0.receiver.number', '1001')
            ->assertJsonPath('messages.1.body', 'پاسخ آزمایشی دو')
            ->assertJsonPath('messages.1.sender.number', '1001')
            ->assertJsonPath('messages.1.receiver.number', '1000');
    }

    public function test_admin_can_send_sms_on_behalf_of_any_line(): void
    {
        $res = $this->actingAs($this->admin)->postJson('/admin/messages/send', [
            'from_number_id' => $this->lineAli->id,
            'destinations' => ['1001'],
            'body' => 'پیام رسمی مدیریت سیستم',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'sent_count' => 1,
                'failed_count' => 0,
            ]);

        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->lineAli->id,
            'body' => 'پیام رسمی مدیریت سیستم',
            'direction' => MessageDirection::Outbound->value,
        ]);

        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->lineReza->id,
            'body' => 'پیام رسمی مدیریت سیستم',
            'direction' => MessageDirection::Inbound->value,
        ]);
    }

    public function test_regular_user_cannot_access_admin_conversation_endpoints(): void
    {
        $this->actingAs($this->userAli)
            ->getJson("/admin/messages/conversation?phone_number_id={$this->lineAli->id}&peer_number=1001")
            ->assertStatus(403);

        $this->actingAs($this->userAli)
            ->postJson('/admin/messages/send', [
                'from_number_id' => $this->lineAli->id,
                'destinations' => ['1001'],
                'body' => 'تلاش غیرمجاز',
            ])
            ->assertStatus(403);
    }
}
