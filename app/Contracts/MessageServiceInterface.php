<?php

namespace App\Contracts;

use App\Models\ApiToken;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface MessageServiceInterface
{
    /**
     * Records an inbound message received via API or external source.
     * Guarantees idempotency via source_system_id and client_message_id.
     *
     * @param array{
     *     destination_number: string,
     *     sender: string,
     *     body: string,
     *     source_time?: string|null,
     *     source_system_id?: string|null,
     *     client_message_id?: string|null
     * } $data
     * @return array{message: Message, is_duplicate: bool}
     */
    public function recordInboundMessage(array $data, ?User $actor = null, ?ApiToken $token = null): array;

    /**
     * Retrieve paginated messages for a user with optional filtering.
     */
    public function getUserMessages(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Poll new messages arrived after a specific message ID for a user.
     */
    public function pollUserMessages(User $user, int $afterMessageId, ?int $phoneNumberId = null, int $limit = 30): Collection;

    /**
     * Retrieve paginated messages for administration overview.
     */
    public function getAllMessages(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Poll new messages for administration.
     */
    public function pollAllMessages(int $afterMessageId, array $filters = [], int $limit = 30): Collection;

    /**
     * Send SMS from an allocated phone number to one or more recipient numbers.
     * Supports single SMS, bulk SMS, and enforces non-9-digit service line rules.
     *
     * @param PhoneNumber $fromNumber
     * @param array<string> $destinationNumbers
     * @param string $body
     * @param User $senderUser
     * @return array{sent_count: int, failed_count: int, results: array}
     */
    public function sendSms(PhoneNumber $fromNumber, array $destinationNumbers, string $body, User $senderUser): array;

    /**
     * Retrieve conversation threads for a specific allocated line (Mobile SMS style).
     */
    public function getConversations(PhoneNumber $phoneNumber, ?string $search = null): Collection;

    /**
     * Retrieve all messages in a specific conversation thread between this line and a peer.
     */
    public function getConversationMessages(PhoneNumber $phoneNumber, string $peerNumber, ?int $afterId = null): Collection;

    /**
     * Retrieve all conversation threads for administration oversight (system-wide dialogues).
     */
    public function getAdminConversations(array $filters = []): Collection;

    /**
     * Retrieve messages for a specific conversation in administration.
     */
    public function getAdminConversationMessages(int $phoneNumberId, string $peerNumber, ?int $afterId = null): Collection;

    /**
     * Delete a message (Admin action).
     */
    public function deleteMessage(Message $message): void;
}
