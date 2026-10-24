<?php

namespace App\Contracts;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

interface TicketServiceInterface
{
    /**
     * Create a new support ticket with initial message and optional attachments.
     *
     * @param array<string, mixed> $data
     * @param array<int, UploadedFile> $attachments
     */
    public function createTicket(User $user, array $data, array $attachments = []): Ticket;

    /**
     * Reply to an existing support ticket.
     *
     * @param array<int, UploadedFile> $attachments
     */
    public function replyToTicket(Ticket $ticket, User $author, string $message, array $attachments = []): TicketMessage;

    /**
     * Update status of a ticket (e.g. InProgress, Closed).
     */
    public function updateStatus(Ticket $ticket, TicketStatus $status, User $actor): Ticket;

    /**
     * Assign ticket to a support agent or unassign.
     */
    public function assignTicket(Ticket $ticket, ?User $agent, User $actor): Ticket;

    /**
     * Close a ticket.
     */
    public function closeTicket(Ticket $ticket, User $actor): Ticket;
}
