<?php

namespace Tests\Feature\Tickets;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\TicketDepartment;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected User $agent;
    protected User $admin;
    protected PhoneNumber $phoneNumber;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->otherUser = User::factory()->create([
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        $this->agent = User::factory()->create([
            'name' => 'کارشناس فنی تست',
            'role' => UserRole::Agent,
            'status' => UserStatus::Active,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'مدیر ارشد تست',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->phoneNumber = PhoneNumber::create([
            'number' => '000123456',
            'type' => PhoneNumberType::UserStandard,
            'status' => PhoneNumberStatus::Allocated,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_guest_cannot_access_tickets_and_redirects_to_login(): void
    {
        $response = $this->get(route('tickets.index'));
        $response->assertRedirect(route('login'));

        $response = $this->get(route('tickets.create'));
        $response->assertRedirect(route('login'));

        $response = $this->get(route('admin.tickets.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_user_can_view_ticket_index_and_creation_form(): void
    {
        $response = $this->actingAs($this->user)->get(route('tickets.index'));
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Tickets/Index')
                ->has('tickets.data')
                ->has('stats')
                ->has('departments')
                ->has('statuses')
            );

        $response = $this->actingAs($this->user)->get(route('tickets.create'));
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Tickets/Create')
                ->has('departments')
                ->has('priorities')
                ->has('userNumbers', 1)
            );
    }

    public function test_user_can_create_ticket_with_optional_number_and_attachments(): void
    {
        $fakePdf = UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf');
        $fakePng = UploadedFile::fake()->image('screenshot.png', 400, 300);

        $response = $this->actingAs($this->user)->post(route('tickets.store'), [
            'subject' => 'مشکل در برقراری اتصال به سرشماره اختصاصی',
            'department' => TicketDepartment::Numbers->value,
            'priority' => TicketPriority::High->value,
            'phone_number_id' => $this->phoneNumber->id,
            'message' => 'سلام، هنگام دریافت پیامک با این خط خطای اتصال دریافت می‌شود و لاگ ثبت نمی‌گردد.',
            'attachments' => [$fakePdf, $fakePng],
        ]);

        $ticket = Ticket::first();
        $this->assertNotNull($ticket);

        $response->assertRedirect(route('tickets.show', $ticket));
        $this->assertStringStartsWith('TK-', $ticket->ticket_number);
        $this->assertEquals('مشکل در برقراری اتصال به سرشماره اختصاصی', $ticket->subject);
        $this->assertEquals(TicketDepartment::Numbers, $ticket->department);
        $this->assertEquals(TicketPriority::High, $ticket->priority);
        $this->assertEquals(TicketStatus::Open, $ticket->status);
        $this->assertEquals($this->phoneNumber->id, $ticket->phone_number_id);
        $this->assertEquals($this->user->id, $ticket->user_id);

        // Check messages and attachments
        $this->assertCount(1, $ticket->messages);
        $message = $ticket->messages->first();
        $this->assertEquals($this->user->id, $message->user_id);
        $this->assertFalse($message->is_staff_reply);
        $this->assertCount(2, $message->attachments);

        // Check file storage
        foreach ($message->attachments as $att) {
            Storage::disk('local')->assertExists($att->file_path);
        }

        // Check audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket_created',
            'user_id' => $this->user->id,
            'entity_id' => $ticket->id,
            'entity_type' => Ticket::class,
        ]);
    }

    public function test_user_can_view_own_ticket_thread_and_reply_multiple_times(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0001',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::Technical,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'subject' => 'درخواست بررسی وب‌هوک',
            'last_reply_at' => now(),
        ]);

        $m1 = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'message' => 'پیام اولیه کاربر جهت پیگیری وب‌هوک.',
            'is_staff_reply' => false,
        ]);

        // User views ticket
        $response = $this->actingAs($this->user)->get(route('tickets.show', $ticket));
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Tickets/Show')
                ->where('ticket.id', $ticket->id)
                ->has('ticket.messages', 1)
            );

        // User posts second reply
        $replyResponse = $this->actingAs($this->user)->post(route('tickets.reply', $ticket), [
            'message' => 'این پیام تکمیلی اول من است، لاگ وب‌هوک ضمیمه شد.',
        ]);
        $replyResponse->assertSessionHasNoErrors();
        $this->assertCount(2, $ticket->fresh()->messages);

        // User posts third reply
        $replyResponse2 = $this->actingAs($this->user)->post(route('tickets.reply', $ticket), [
            'message' => 'این پیام تکمیلی دوم من است.',
        ]);
        $replyResponse2->assertSessionHasNoErrors();
        $this->assertCount(3, $ticket->fresh()->messages);
    }

    public function test_user_cannot_view_or_reply_to_other_users_ticket(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0002',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::Technical,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'subject' => 'تیکت خصوصی کاربر اول',
            'last_reply_at' => now(),
        ]);

        // Other user attempts to view -> 403
        $response = $this->actingAs($this->otherUser)->get(route('tickets.show', $ticket));
        $response->assertForbidden();

        // Other user attempts to reply -> 403
        $response = $this->actingAs($this->otherUser)->post(route('tickets.reply', $ticket), [
            'message' => 'تلاش نامعتبر برای ارسال پاسخ در تیکت غیرمجاز',
        ]);
        $response->assertForbidden();
    }

    public function test_user_cannot_download_attachments_from_other_users_tickets(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0003',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::General,
            'priority' => TicketPriority::Low,
            'status' => TicketStatus::Open,
            'subject' => 'تیکت دارای فایل حساس',
            'last_reply_at' => now(),
        ]);

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'message' => 'فایل ضمیمه است.',
            'is_staff_reply' => false,
        ]);

        $filePath = 'tickets/' . $ticket->id . '/secret.pdf';
        Storage::disk('local')->put($filePath, 'sensitive file contents');

        $attachment = TicketAttachment::create([
            'ticket_message_id' => $message->id,
            'file_name' => 'secret.pdf',
            'file_path' => $filePath,
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ]);

        // Regular user owner can download
        $ownerResp = $this->actingAs($this->user)->get(route('tickets.attachments.download', [$ticket, $attachment]));
        $ownerResp->assertOk();

        // Other user cannot download -> 403
        $intruderResp = $this->actingAs($this->otherUser)->get(route('tickets.attachments.download', [$ticket, $attachment]));
        $intruderResp->assertForbidden();
    }

    public function test_user_can_close_ticket_and_cannot_reply_afterwards(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0004',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::Api,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'subject' => 'حل شده است',
            'last_reply_at' => now(),
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'message' => 'مشکل اولیه.',
            'is_staff_reply' => false,
        ]);

        $closeResp = $this->actingAs($this->user)->post(route('tickets.close', $ticket));
        $closeResp->assertSessionHasNoErrors();
        $this->assertEquals(TicketStatus::Closed, $ticket->fresh()->status);

        // Attempt to reply to closed ticket -> 403 Forbidden
        $replyResp = $this->actingAs($this->user)->post(route('tickets.reply', $ticket), [
            'message' => 'ارسال پیام در تیکت بسته شده باید رد شود.',
        ]);
        $replyResp->assertForbidden();
    }

    public function test_staff_agent_and_admin_can_access_admin_tickets_and_regular_user_is_forbidden(): void
    {
        // Regular user denied
        $userResp = $this->actingAs($this->user)->get(route('admin.tickets.index'));
        $userResp->assertForbidden();

        // Agent allowed
        $agentResp = $this->actingAs($this->agent)->get(route('admin.tickets.index'));
        $agentResp->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tickets/Index')
                ->has('tickets.data')
                ->has('stats')
                ->has('staffMembers')
            );

        // Admin allowed
        $adminResp = $this->actingAs($this->admin)->get(route('admin.tickets.index'));
        $adminResp->assertOk();
    }

    public function test_staff_can_reply_to_ticket_and_ticket_status_changes_to_answered(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0005',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::Technical,
            'priority' => TicketPriority::High,
            'status' => TicketStatus::Open,
            'subject' => 'خطای پورت وب‌سرویس',
            'last_reply_at' => now()->subHours(2),
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'message' => 'وب‌سرویس روی پورت ۴۴۳ پاسخ نمی‌دهد.',
            'is_staff_reply' => false,
        ]);

        $fakeDoc = UploadedFile::fake()->create('guide.pdf', 80, 'application/pdf');

        $replyResp = $this->actingAs($this->agent)->post(route('admin.tickets.reply', $ticket), [
            'message' => 'سلام، تنظیمات پورت فایروال بررسی و رفع گردید. لطفا مجددا تست فرمایید.',
            'attachments' => [$fakeDoc],
        ]);
        $replyResp->assertSessionHasNoErrors();

        $freshTicket = $ticket->fresh();
        $this->assertEquals(TicketStatus::Answered, $freshTicket->status);
        $this->assertCount(2, $freshTicket->messages);

        $staffMessage = $freshTicket->messages->last();
        $this->assertTrue($staffMessage->is_staff_reply);
        $this->assertEquals($this->agent->id, $staffMessage->user_id);
        $this->assertCount(1, $staffMessage->attachments);

        // Audit log verified
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket_replied',
            'user_id' => $this->agent->id,
            'entity_id' => $ticket->id,
            'entity_type' => Ticket::class,
        ]);
    }

    public function test_staff_can_assign_ticket_to_specialist(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0006',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::Technical,
            'priority' => TicketPriority::High,
            'status' => TicketStatus::Open,
            'subject' => 'درخواست تخصیص کارشناس',
            'last_reply_at' => now(),
        ]);

        $assignResp = $this->actingAs($this->admin)->patch(route('admin.tickets.assign', $ticket), [
            'assigned_to' => $this->agent->id,
        ]);
        $assignResp->assertSessionHasNoErrors();

        $this->assertEquals($this->agent->id, $ticket->fresh()->assigned_to);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket_assigned',
            'user_id' => $this->admin->id,
            'entity_id' => $ticket->id,
            'entity_type' => Ticket::class,
        ]);
    }

    public function test_staff_can_update_ticket_status(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TK-TEST-0007',
            'user_id' => $this->user->id,
            'department' => TicketDepartment::Technical,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::Open,
            'subject' => 'به‌روزرسانی وضعیت تیکت',
            'last_reply_at' => now(),
        ]);

        $statusResp = $this->actingAs($this->agent)->patch(route('admin.tickets.status', $ticket), [
            'status' => TicketStatus::InProgress->value,
        ]);
        $statusResp->assertSessionHasNoErrors();

        $this->assertEquals(TicketStatus::InProgress, $ticket->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ticket_status_changed',
            'user_id' => $this->agent->id,
            'entity_id' => $ticket->id,
            'entity_type' => Ticket::class,
        ]);
    }

    public function test_attachment_validation_rejects_disallowed_file_types(): void
    {
        $maliciousFile = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $response = $this->actingAs($this->user)->post(route('tickets.store'), [
            'subject' => 'تست اعتبارسنجی فایل',
            'department' => TicketDepartment::Technical->value,
            'priority' => TicketPriority::Low->value,
            'message' => 'متن آزمایشی تیکت جهت بررسی فایل ممنوعه.',
            'attachments' => [$maliciousFile],
        ]);

        $response->assertSessionHasErrors(['attachments.0']);
    }
}
