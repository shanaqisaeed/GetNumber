<?php

namespace App\Contracts;

use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;

interface UnreadCountServiceInterface
{
    /**
     * Get unread messages count for a user (cached with Redis/Cache).
     */
    public function getUserUnreadCount(int $userId, bool $forceFresh = false): int;

    /**
     * Get unread messages count for a specific allocated line.
     */
    public function getLineUnreadCount(int $phoneNumberId, bool $forceFresh = false): int;

    /**
     * Atomically increment unread counters upon receiving a new inbound message.
     */
    public function incrementForMessage(Message $message): void;

    /**
     * Decrement unread counters when messages are read or marked as processed.
     */
    public function decrementUnread(int $userId, int $phoneNumberId, int $amount = 1): void;

    /**
     * Reconcile cached counters with true database records to prevent counter drift.
     */
    public function reconcileUserUnread(int $userId): int;

    /**
     * Reconcile all allocated lines for a user and return the array summary.
     */
    public function getUnreadSummaryForUser(User $user): array;
}