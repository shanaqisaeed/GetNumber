<?php

namespace Tests\Feature\Messages;

use App\Contracts\MessageServiceInterface;
use App\Contracts\UnreadCountServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\ProcessMessageBatchJob;
use App\Models\Message;
use App\Models\MessageBatch;
use App\Models\MessageBatchItem;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageQueueAndResyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
    protected User $user3;
    protected PhoneNumber $line1;
    protected PhoneNumber $line2;
    protected PhoneNumber $line3;
    protected MessageServiceInterface $messageService;
    protected UnreadCountServiceInterface $unreadService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messageService = app(MessageServiceInterface::class);
        $this->unreadService = app(UnreadCountServiceInterface::class);

        $this->user1 = User::factory()->create([
            'username' => 'sender_user',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->user2 = User::factory()->create([
            'username' => 'dest_user_one',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->user3 = User::factory()->create([
            'username' => 'dest_user_two',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->line1 = PhoneNumber::create([
            'number' => '000010001',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user1->id,
            'allocated_at' => now(),
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
            'user_id' => $this->user3->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);
    }

    public function test_user_can_dispatch_bulk_sms_asynchronously_to_queue(): void
    {
        Queue::fake([ProcessMessageBatchJob::class]);

        $response = $this->actingAs($this->user1)->postJson(route('messages.send_bulk'), [
            'from_number_id' => $this->line1->id,
            'destinations' => [$this->line2->number, $this->line3->number],
            'body' => 'پیامک گروهی تست صف‌های پس‌زمینه',
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'total_count' => 2,
                'status' => MessageBatch::STATUS_PENDING,
            ]);

        $batchId = $response->json('batch_id');
        $this->assertNotEmpty($batchId);

        $this->assertDatabaseHas('message_batches', [
            'batch_id' => $batchId,
            'user_id' => $this->user1->id,
            'phone_number_id' => $this->line1->id,
            'total_count' => 2,
        ]);

        $this->assertEquals(2, MessageBatchItem::whereHas('batch', fn ($q) => $q->where('batch_id', $batchId))->count());

        Queue::assertPushed(ProcessMessageBatchJob::class);
    }

    public function test_batch_resume_mechanism_prevents_duplicate_sending_after_restart(): void
    {
        $batch = MessageBatch::create([
            'batch_id' => 'BATCH-RESUME-001',
            'user_id' => $this->user1->id,
            'phone_number_id' => $this->line1->id,
            'sender' => $this->line1->number,
            'body' => 'پیام تست مکانیزم ازسرگیری ارسال',
            'total_count' => 2,
            'processed_count' => 1,
            'success_count' => 1,
            'failed_count' => 0,
            'status' => MessageBatch::STATUS_PROCESSING,
        ]);

        // Destination 1 was ALREADY sent in a previous run before crash
        $alreadySentMessage = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line2->number,
            'sender' => $this->line1->number,
            'peer_number' => $this->line2->number,
            'body' => 'پیام قبلی',
            'server_received_at' => now(),
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        MessageBatchItem::create([
            'message_batch_id' => $batch->id,
            'destination' => $this->line2->number,
            'status' => MessageBatchItem::STATUS_SENT,
            'attempts' => 1,
            'message_id' => $alreadySentMessage->id,
            'processed_at' => now()->subMinute(),
        ]);

        // Destination 2 was pending
        $pendingItem = MessageBatchItem::create([
            'message_batch_id' => $batch->id,
            'destination' => $this->line3->number,
            'status' => MessageBatchItem::STATUS_PENDING,
            'attempts' => 0,
        ]);

        // Run the Job to resume
        $job = new ProcessMessageBatchJob($batch->id);
        $job->handle();

        $freshBatch = $batch->fresh();
        $this->assertEquals(MessageBatch::STATUS_COMPLETED, $freshBatch->status);
        $this->assertEquals(2, $freshBatch->processed_count);
        $this->assertEquals(2, $freshBatch->success_count);

        // Assert destination 2 is sent
        $this->assertEquals(MessageBatchItem::STATUS_SENT, $pendingItem->fresh()->status);
        $this->assertNotNull($pendingItem->fresh()->message_id);

        // Assert destination 1 was NOT resent (only 1 outbound message to destination 1 exists)
        $this->assertEquals(1, Message::where('destination_number', $this->line2->number)->where('direction', MessageDirection::Outbound)->count());
    }

    public function test_unread_count_service_increments_and_decrements_cache(): void
    {
        $this->assertEquals(0, $this->unreadService->getUserUnreadCount($this->user2->id));

        // Create inbound message
        $inboundMsg = Message::create([
            'phone_number_id' => $this->line2->id,
            'destination_number' => $this->line2->number,
            'sender' => $this->line1->number,
            'peer_number' => $this->line1->number,
            'body' => 'پیام ورودی جدید',
            'server_received_at' => now(),
            'direction' => MessageDirection::Inbound,
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        $this->unreadService->incrementForMessage($inboundMsg);

        $this->assertEquals(1, $this->unreadService->getUserUnreadCount($this->user2->id));
        $this->assertEquals(1, $this->unreadService->getLineUnreadCount($this->line2->id));

        // Decrement
        $this->unreadService->decrementUnread($this->user2->id, $this->line2->id, 1);
        $this->assertEquals(0, $this->unreadService->getUserUnreadCount($this->user2->id));
    }

    public function test_resync_endpoint_returns_only_missed_messages_after_reconnect(): void
    {
        // Message 1 (older)
        $msg1 = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line2->number,
            'sender' => $this->line1->number,
            'peer_number' => $this->line2->number,
            'body' => 'پیام اول قدیمی',
            'server_received_at' => now()->subMinutes(5),
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        // Message 2 (newer)
        $msg2 = Message::create([
            'phone_number_id' => $this->line1->id,
            'destination_number' => $this->line2->number,
            'sender' => $this->line1->number,
            'peer_number' => $this->line2->number,
            'body' => 'پیام دوم جدید پس از قطعی اتصال',
            'server_received_at' => now()->subMinute(),
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
        ]);

        // Client reconnects after msg1, requesting messages with after_id = msg1->id
        $response = $this->actingAs($this->user1)->getJson(route('messages.resync', [
            'phone_number_id' => $this->line1->id,
            'after_id' => $msg1->id,
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'count' => 1,
                'latest_id' => $msg2->id,
            ]);

        $this->assertCount(1, $response->json('messages'));
        $this->assertEquals($msg2->id, $response->json('messages.0.id'));
        $this->assertEquals('پیام دوم جدید پس از قطعی اتصال', $response->json('messages.0.body'));
    }

    public function test_batch_status_endpoint_returns_realtime_progress(): void
    {
        $batch = MessageBatch::create([
            'batch_id' => 'BATCH-STAT-999',
            'user_id' => $this->user1->id,
            'phone_number_id' => $this->line1->id,
            'sender' => $this->line1->number,
            'body' => 'بررسی درصد پیشرفت',
            'total_count' => 10,
            'processed_count' => 7,
            'success_count' => 6,
            'failed_count' => 1,
            'status' => MessageBatch::STATUS_PROCESSING,
        ]);

        $response = $this->actingAs($this->user1)->getJson(route('messages.batch.status', $batch->batch_id));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'batch_id' => 'BATCH-STAT-999',
                'status' => MessageBatch::STATUS_PROCESSING,
                'total_count' => 10,
                'processed_count' => 7,
                'success_count' => 6,
                'failed_count' => 1,
                'progress_percentage' => 70,
                'is_finished' => false,
            ]);
    }
}

