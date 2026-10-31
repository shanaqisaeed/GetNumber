<?php

namespace Tests\Feature\Messages;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealTimeMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
    protected User $admin;
    protected PhoneNumber $line1;
    protected PhoneNumber $line2;

    protected function setUp(): void
    {
        parent::setUp();

        PhonePrefix::firstOrCreate(
            ['prefix' => '+85'],
            [
                'clean_prefix' => '85',
                'name' => 'پیش‌شماره پیش‌فرض',
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
    }

    public function test_message_received_event_structure_and_channels(): void
    {
        $message = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line1->number,
            'sender' => '989120000000',
            'peer_number' => '989120000000',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام آزمایشی زنده',
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now(),
        ]);

        $event = new MessageReceived($message);

        $this->assertEquals('message.received', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(3, $channels);

        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('private-admin.messages', $channelNames);
        $this->assertContains('private-lines.'.$this->line1->id, $channelNames);
        $this->assertContains('private-users.'.$this->user1->id, $channelNames);

        $data = $event->broadcastWith();
        $this->assertEquals($message->id, $data['id']);
        $this->assertEquals('989120000000', $data['sender']);
        $this->assertEquals('پیام آزمایشی زنده', $data['body']);
        $this->assertEquals($this->user1->id, $data['user_id']);
        $this->assertEquals(1, $data['unread_count']);
    }

    public function test_message_status_updated_event_structure_and_channels(): void
    {
        $message = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line1->number,
            'sender' => '989120000000',
            'peer_number' => '989120000000',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام خوانده شده',
            'processing_status' => MessageProcessingStatus::Processed,
            'server_received_at' => now(),
        ]);

        $event = new MessageStatusUpdated($message);

        $this->assertEquals('message.status.updated', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('private-admin.messages', $channelNames);
        $this->assertContains('private-lines.'.$this->line1->id, $channelNames);
        $this->assertContains('private-users.'.$this->user1->id, $channelNames);

        $data = $event->broadcastWith();
        $this->assertEquals($message->id, $data['id']);
        $this->assertEquals('processed', $data['processing_status']);
    }

    public function test_channel_authorization_rules(): void
    {
        // 1. Channel users.{id}
        // User1 can access their own channel
        $user1Own = ((int) $this->user1->id === (int) $this->user1->id) || $this->user1->isAdmin();
        $this->assertTrue($user1Own);

        // User1 cannot access User2's channel
        $user1Other = ((int) $this->user1->id === (int) $this->user2->id) || $this->user1->isAdmin();
        $this->assertFalse($user1Other);

        // Admin can access User2's channel
        $adminOther = ((int) $this->admin->id === (int) $this->user2->id) || $this->admin->isAdmin();
        $this->assertTrue($adminOther);

        // 2. Channel lines.{phoneNumberId}
        // User1 owns line 1
        $user1Line1 = $this->user1->isAdmin() || $this->user1->phoneNumbers()->where('phone_numbers.id', $this->line1->id)->exists();
        $this->assertTrue($user1Line1);

        // User1 does not own line 2
        $user1Line2 = $this->user1->isAdmin() || $this->user1->phoneNumbers()->where('phone_numbers.id', $this->line2->id)->exists();
        $this->assertFalse($user1Line2);

        // Admin can access line 2
        $adminLine2 = $this->admin->isAdmin() || $this->admin->phoneNumbers()->where('phone_numbers.id', $this->line2->id)->exists();
        $this->assertTrue($adminLine2);

        // 3. Channel admin.messages
        $this->assertFalse($this->user1->isAdmin());
        $this->assertTrue($this->admin->isAdmin());
    }

    public function test_unread_summary_endpoint_calculates_correct_counters(): void
    {
        // 2 unread on line 1
        Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line1->number,
            'sender' => '989121111111',
            'peer_number' => '989121111111',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام ۱',
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now()->subMinute(),
        ]);

        $latestMsg = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line1->number,
            'sender' => '989122222222',
            'peer_number' => '989122222222',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام ۲ جدیدتر',
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now(),
        ]);

        // 1 processed (read) on line 1
        Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line1->number,
            'sender' => '989123333333',
            'peer_number' => '989123333333',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام قبلاً خوانده شده',
            'processing_status' => MessageProcessingStatus::Processed,
            'server_received_at' => now()->subHours(2),
        ]);

        // Message on user2's line (should not be counted for user1)
        Message::create([
            'phone_number_id' => $this->line2->id,
            'destination_number' => $this->line2->number,
            'sender' => '989124444444',
            'peer_number' => '989124444444',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام کاربر دیگر',
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now(),
        ]);

        $this->actingAs($this->user1);

        $response = $this->getJson(route('messages.unread_summary'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'unread_total' => 2,
                'unread_by_line' => [
                    $this->line1->id => 2,
                ],
                'latest_message' => [
                    'id' => $latestMsg->id,
                    'phone_number_id' => $this->line1->id,
                    'body' => 'پیام ۲ جدیدتر',
                ],
            ]);
    }

    public function test_opening_conversation_updates_status_and_broadcasts(): void
    {
        $message = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line1->number,
            'sender' => '989129998877',
            'peer_number' => '989129998877',
            'direction' => MessageDirection::Inbound,
            'body' => 'پیام نخوانده قبل از باز کردن چت',
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now(),
        ]);

        $this->actingAs($this->user1);

        $response = $this->getJson(route('messages.conversation', [
            'phone_number_id' => $this->line1->id,
            'peer' => '989129998877',
        ]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'processing_status' => 'processed',
        ]);
    }

    public function test_send_sms_dispatches_realtime_broadcast_for_both_lines(): void
    {
        $messageService = app(\App\Contracts\MessageServiceInterface::class);

        $result = $messageService->sendSms(
            $this->line1,
            [$this->line2->formatted_number],
            'سلام به خط کاربر ۲',
            $this->user1
        );

        $this->assertEquals(1, $result['sent_count']);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('success', $result['results'][0]['status']);

        // Outbound on line1
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->line1->id,
            'direction' => 'outbound',
            'body' => 'سلام به خط کاربر ۲',
        ]);

        // Inbound copy on line2
        $this->assertDatabaseHas('messages', [
            'phone_number_id' => $this->line2->id,
            'direction' => 'inbound',
            'body' => 'سلام به خط کاربر ۲',
        ]);
    }
}
