<?php

namespace Tests\Feature\Messages;

use App\Contracts\MessageArchiveServiceInterface;
use App\Contracts\MessageServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ArchivedMessage;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseOptimizationAndArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected PhoneNumber $phoneNumber;
    protected MessageArchiveServiceInterface $archiveService;
    protected MessageServiceInterface $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveService = app(MessageArchiveServiceInterface::class);
        $this->messageService = app(MessageServiceInterface::class);

        $this->user = User::factory()->create([
            'username' => 'db_opt_user',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->phoneNumber = PhoneNumber::create([
            'number' => '000099901',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
            'allocated_at' => now(),
            'accepts_incoming_sms' => true,
        ]);
    }

    public function test_messages_archive_moves_old_messages_atomically_to_cold_storage(): void
    {
        // 1. Create an old message (100 days ago)
        $oldMsg = Message::create([
            'phone_number_id' => $this->phoneNumber->id,
            'destination_number' => '000099902',
            'sender' => $this->phoneNumber->number,
            'peer_number' => '000099902',
            'body' => 'پیام قدیمی که باید به آرشیو منتقل شود',
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now()->subDays(100),
        ]);
        DB::table('messages')->where('id', $oldMsg->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // 2. Create a recent message (5 days ago)
        $recentMsg = Message::create([
            'phone_number_id' => $this->phoneNumber->id,
            'destination_number' => '000099902',
            'sender' => $this->phoneNumber->number,
            'peer_number' => '000099902',
            'body' => 'پیام جدید فعال که نباید آرشیو شود',
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now()->subDays(5),
        ]);
        DB::table('messages')->where('id', $recentMsg->id)->update([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $stats = $this->archiveService->archiveOlderThan(days: 90, chunkSize: 50, dryRun: false);

        $this->assertEquals(1, $stats['archived_count']);
        $this->assertEquals(1, $stats['eligible_count']);

        // Assert old message is removed from active table
        $this->assertDatabaseMissing('messages', ['id' => $oldMsg->id]);

        // Assert old message exists in archived_messages cold storage table
        $this->assertDatabaseHas('archived_messages', [
            'id' => $oldMsg->id,
            'phone_number_id' => $this->phoneNumber->id,
            'body' => 'پیام قدیمی که باید به آرشیو منتقل شود',
        ]);

        // Assert recent message is still in active table
        $this->assertDatabaseHas('messages', ['id' => $recentMsg->id]);
        $this->assertDatabaseMissing('archived_messages', ['id' => $recentMsg->id]);
    }

    public function test_messages_archive_dry_run_does_not_modify_database(): void
    {
        $oldMsg = Message::create([
            'phone_number_id' => $this->phoneNumber->id,
            'destination_number' => '000099902',
            'sender' => $this->phoneNumber->number,
            'peer_number' => '000099902',
            'body' => 'پیام قدیمی تست شبیه‌سازی',
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now()->subDays(120),
        ]);
        DB::table('messages')->where('id', $oldMsg->id)->update([
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);

        $stats = $this->archiveService->archiveOlderThan(days: 90, dryRun: true);

        $this->assertTrue($stats['dry_run']);
        $this->assertEquals(1, $stats['eligible_count']);
        $this->assertEquals(0, $stats['archived_count']);

        // Message MUST still remain in active table
        $this->assertDatabaseHas('messages', ['id' => $oldMsg->id]);
        $this->assertDatabaseMissing('archived_messages', ['id' => $oldMsg->id]);
    }

    public function test_archived_message_can_be_restored_to_active_table(): void
    {
        $oldMsg = Message::create([
            'phone_number_id' => $this->phoneNumber->id,
            'destination_number' => '000099902',
            'sender' => $this->phoneNumber->number,
            'peer_number' => '000099902',
            'body' => 'پیام قابل بازیابی از آرشیو',
            'direction' => MessageDirection::Inbound,
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now()->subDays(100),
        ]);
        DB::table('messages')->where('id', $oldMsg->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $this->archiveService->archiveOlderThan(days: 90);

        $this->assertDatabaseHas('archived_messages', ['id' => $oldMsg->id]);
        $this->assertDatabaseMissing('messages', ['id' => $oldMsg->id]);

        // Restore
        $restored = $this->archiveService->restoreArchivedMessage($oldMsg->id);
        $this->assertTrue($restored);

        $this->assertDatabaseHas('messages', [
            'id' => $oldMsg->id,
            'body' => 'پیام قابل بازیابی از آرشیو',
        ]);
        $this->assertDatabaseMissing('archived_messages', ['id' => $oldMsg->id]);
    }

    public function test_polling_avalanche_prevention_avoids_redundant_queries_when_no_delta(): void
    {
        $msg = Message::create([
            'phone_number_id' => $this->phoneNumber->id,
            'destination_number' => '000099902',
            'sender' => $this->phoneNumber->number,
            'peer_number' => '000099902',
            'body' => 'پیام فعال برای تست پلینگ',
            'direction' => MessageDirection::Outbound,
            'processing_status' => MessageProcessingStatus::Received,
            'server_received_at' => now(),
        ]);

        // 1st Poll with after_id = 0 -> fetches msg
        $res1 = $this->actingAs($this->user)->getJson(route('messages.poll', [
            'phone_number_id' => $this->phoneNumber->id,
            'after_id' => 0,
        ]));

        $res1->assertStatus(200);
        $this->assertEquals(1, $res1->json('count'));

        // 2nd Poll with after_id = msg->id (No delta)
        $res2 = $this->actingAs($this->user)->getJson(route('messages.poll', [
            'phone_number_id' => $this->phoneNumber->id,
            'after_id' => $msg->id,
        ]));

        $res2->assertStatus(200);
        // Fast response with count 0 and 0 new messages
        $this->assertEquals(0, $res2->json('count'));
        $this->assertEmpty($res2->json('data'));
    }
}
