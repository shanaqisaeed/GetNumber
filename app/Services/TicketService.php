<?php

namespace App\Services;

use App\Contracts\TicketServiceInterface;
use App\Enums\TicketDepartment;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\NumberAllocationException;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TicketService implements TicketServiceInterface
{
    /**
     * Allowed MIME types and extensions for attachments (JPG, PNG, PDF, ZIP, TXT) up to 5MB.
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'txt'];
    public const MAX_FILE_SIZE_KB = 5120; // 5 MB

    public function createTicket(User $user, array $data, array $attachments = []): Ticket
    {
        // 1. Verify phone number ownership if provided
        $phoneNumberId = null;
        if (! empty($data['phone_number_id'])) {
            $phone = PhoneNumber::findOrFail($data['phone_number_id']);
            if ($phone->user_id !== $user->id && ! $user->isStaff()) {
                throw new NumberAllocationException('خط انتخاب‌شده متعلق به حساب کاربری شما نمی‌باشد.');
            }
            $phoneNumberId = $phone->id;
        }

        return DB::transaction(function () use ($user, $data, $phoneNumberId, $attachments) {
            $department = is_string($data['department'])
                ? TicketDepartment::from($data['department'])
                : $data['department'];

            $priority = is_string($data['priority'])
                ? TicketPriority::from($data['priority'])
                : $data['priority'];

            $ticket = Ticket::create([
                'user_id' => $user->id,
                'phone_number_id' => $phoneNumberId,
                'department' => $department,
                'priority' => $priority,
                'status' => TicketStatus::Open,
                'subject' => trim($data['subject']),
                'last_reply_at' => now(),
            ]);

            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => trim($data['message']),
                'is_staff_reply' => false,
                'ip_address' => request()?->ip(),
            ]);

            // Save attachments
            $this->storeAttachments($message, $attachments, $ticket->id);

            AuditLog::record('ticket_created', $ticket, $user, [
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'department' => $ticket->department->value,
                'priority' => $ticket->priority->value,
                'attachments_count' => count($attachments),
            ]);

            return $ticket->fresh(['user', 'phoneNumber', 'messages.attachments']);
        });
    }

    public function replyToTicket(Ticket $ticket, User $author, string $message, array $attachments = []): TicketMessage
    {
        if ($ticket->isClosed() && ! $author->isStaff()) {
            throw ValidationException::withMessages([
                'message' => 'این تیکت بسته‌شده است و امکان ارسال پاسخ جدید توسط کاربر وجود ندارد.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $author, $message, $attachments) {
            $isStaff = $author->isStaff();

            $ticketMessage = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'message' => trim($message),
                'is_staff_reply' => $isStaff,
                'ip_address' => request()?->ip(),
            ]);

            // Store attachments
            $this->storeAttachments($ticketMessage, $attachments, $ticket->id);

            // Update ticket status
            $newStatus = $isStaff ? TicketStatus::Answered : TicketStatus::Open;
            $ticket->update([
                'status' => $newStatus,
                'last_reply_at' => now(),
            ]);

            AuditLog::record('ticket_replied', $ticket, $author, [
                'ticket_number' => $ticket->ticket_number,
                'is_staff_reply' => $isStaff,
                'attachments_count' => count($attachments),
            ]);

            return $ticketMessage->fresh(['user', 'attachments']);
        });
    }

    public function updateStatus(Ticket $ticket, TicketStatus $status, User $actor): Ticket
    {
        $oldStatus = $ticket->status->value;

        $ticket->update([
            'status' => $status,
        ]);

        AuditLog::record('ticket_status_changed', $ticket, $actor, [
            'ticket_number' => $ticket->ticket_number,
            'from_status' => $oldStatus,
            'to_status' => $status->value,
        ]);

        return $ticket->fresh();
    }

    public function assignTicket(Ticket $ticket, ?User $agent, User $actor): Ticket
    {
        if ($agent && ! $agent->isStaff()) {
            throw new \InvalidArgumentException('کاربر انتخاب‌شده کارشناس پشتیبانی یا مدیر سامانه نمی‌باشد.');
        }

        $ticket->update([
            'assigned_to' => $agent?->id,
            'status' => $agent && $ticket->status === TicketStatus::Open ? TicketStatus::InProgress : $ticket->status,
        ]);

        AuditLog::record('ticket_assigned', $ticket, $actor, [
            'ticket_number' => $ticket->ticket_number,
            'assigned_to_id' => $agent?->id,
            'assigned_to_username' => $agent?->username,
        ]);

        return $ticket->fresh(['assignedAgent']);
    }

    public function closeTicket(Ticket $ticket, User $actor): Ticket
    {
        return $this->updateStatus($ticket, TicketStatus::Closed, $actor);
    }

    /**
     * Store and associate uploaded files safely in local non-public storage.
     *
     * @param array<int, UploadedFile> $attachments
     */
    protected function storeAttachments(TicketMessage $message, array $attachments, int $ticketId): void
    {
        foreach ($attachments as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            // Store in private storage directory
            $path = $file->store("tickets/{$ticketId}", 'local');

            TicketAttachment::create([
                'ticket_message_id' => $message->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getClientMimeType() ?: ($file->getMimeType() ?: 'application/octet-stream'),
            ]);
        }
    }
}
