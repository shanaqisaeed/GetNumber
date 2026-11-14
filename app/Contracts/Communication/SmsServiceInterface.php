<?php

namespace App\Contracts\Communication;

use App\Models\PhoneNumber;
use App\Models\User;

interface SmsServiceInterface
{
    /**
     * Send SMS from an allocated phone number to one or more destinations.
     * Deducts cost immediately from user wallet with a wallet_transactions entry.
     *
     * @param PhoneNumber $fromNumber
     * @param array<string>|string $destinations
     * @param string $body
     * @param User $senderUser
     * @return array{
     *     sent_count: int,
     *     failed_count: int,
     *     total_cost: float,
     *     results: array<int, array{destination: string, status: string, message_id?: int, reason?: string}>
     * }
     */
    public function send(PhoneNumber $fromNumber, array|string $destinations, string $body, User $senderUser): array;

    /**
     * Get real-time delivery status of a message.
     *
     * @param int $messageId
     * @return array{message_id: int, status: string, delivered_at: ?string, details: array}
     */
    public function getDeliveryStatus(int $messageId): array;

    /**
     * Calculate cost and segment parts of an SMS body for a given line.
     *
     * @param string $body
     * @param PhoneNumber|null $fromNumber
     * @param int $recipientCount
     * @return array{
     *     parts_count: int,
     *     characters_count: int,
     *     encoding: string,
     *     tariff_per_part: float,
     *     total_cost: float,
     *     currency: string
     * }
     */
    public function calculateCost(string $body, ?PhoneNumber $fromNumber = null, int $recipientCount = 1): array;
}

