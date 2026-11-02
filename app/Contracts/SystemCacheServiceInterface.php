<?php

namespace App\Contracts;

use App\Models\Message;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Support\Collection;

interface SystemCacheServiceInterface
{
    // ==========================================
    // SMS & Conversation Caching
    // ==========================================

    /**
     * Get or calculate cached conversations for a phone number.
     */
    public function getLineConversations(PhoneNumber $phoneNumber, ?string $search = null): Collection;

    /**
     * Get or calculate cached messages for a specific conversation peer.
     */
    public function getLineConversationMessages(PhoneNumber $phoneNumber, string $peer, ?int $afterId = null): Collection;

    /**
     * Invalidate and immediately re-save/prime fresh conversations and messages for a line.
     */
    public function handleMessageCreated(Message $message): void;

    /**
     * Invalidate cached messages when a message is deleted.
     */
    public function handleMessageDeleted(Message $message): void;

    /**
     * Get cached latest message ID for a line (used for zero-DB polling).
     */
    public function getLineLatestMessageId(int $phoneNumberId): int;

    /**
     * Get global latest message ID (for admin zero-DB polling).
     */
    public function getGlobalLatestMessageId(): int;

    // ==========================================
    // Phone Numbers & Lines Caching
    // ==========================================

    public function getUserAllocatedNumbers(User $user): Collection;

    public function getUserActiveNumbersCount(int $userId): int;

    public function getAdminNumbersStats(): array;

    public function getSystemPhoneNumbersList(): Collection;

    public function handlePhoneNumberChanged(PhoneNumber $phoneNumber): void;

    // ==========================================
    // Users Caching
    // ==========================================

    public function getAdminUsersStats(): array;

    public function getAdminUsersDropdown(): Collection;

    public function handleUserChanged(User $user): void;

    // ==========================================
    // Requests & Logs Caching
    // ==========================================

    public function getUserPendingRequestsCount(int $userId): int;

    public function getAdminRequestsStats(): array;

    public function handleNumberRequestChanged(NumberRequest $request): void;

    public function getAuditLogsStats(): array;

    public function getSecurityIncidentsStats(): array;

    public function handleSecurityIncidentChanged(SecurityIncident $incident): void;

    // ==========================================
    // Dashboards Aggregates Caching
    // ==========================================

    public function getAdminDashboardStats(): array;

    public function getUserDashboardStats(User $user): array;
}

