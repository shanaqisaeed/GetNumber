<?php

namespace App\Services;

use App\Contracts\UnreadCountServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UnreadCountService implements UnreadCountServiceInterface
{
    protected const CACHE_TTL_SECONDS = 3600; // 1 hour TTL fallback

    public function getUserUnreadCount(int $userId, bool $forceFresh = false): int
    {
        $cacheKey = "unread:user:{$userId}";

        if (! $forceFresh && Cache::has($cacheKey)) {
            return (int) Cache::get($cacheKey, 0);
        }

        return $this->reconcileUserUnread($userId);
    }

    public function getLineUnreadCount(int $phoneNumberId, bool $forceFresh = false): int
    {
        $cacheKey = "unread:line:{$phoneNumberId}";

        if (! $forceFresh && Cache::has($cacheKey)) {
            return (int) Cache::get($cacheKey, 0);
        }

        $count = (int) Message::where('phone_number_id', $phoneNumberId)
            ->where('direction', MessageDirection::Inbound)
            ->where('processing_status', MessageProcessingStatus::Received)
            ->count();

        Cache::put($cacheKey, $count, self::CACHE_TTL_SECONDS);

        return $count;
    }

    public function incrementForMessage(Message $message): void
    {
        if ($message->direction !== MessageDirection::Inbound) {
            return;
        }

        $phoneNumberId = $message->phone_number_id;
        $userId = $message->phoneNumber?->user_id;

        // Line counter increment
        $lineKey = "unread:line:{$phoneNumberId}";
        if (Cache::has($lineKey)) {
            Cache::increment($lineKey);
        } else {
            $this->getLineUnreadCount($phoneNumberId, forceFresh: true);
        }

        // User counter increment
        if ($userId) {
            $userKey = "unread:user:{$userId}";
            if (Cache::has($userKey)) {
                Cache::increment($userKey);
            } else {
                $this->reconcileUserUnread($userId);
            }
        }
    }

    public function decrementUnread(int $userId, int $phoneNumberId, int $amount = 1): void
    {
        if ($amount <= 0) {
            return;
        }

        // Decrement line
        $lineKey = "unread:line:{$phoneNumberId}";
        if (Cache::has($lineKey)) {
            $currentLine = (int) Cache::get($lineKey, 0);
            $newLine = max(0, $currentLine - $amount);
            Cache::put($lineKey, $newLine, self::CACHE_TTL_SECONDS);
        } else {
            $this->getLineUnreadCount($phoneNumberId, forceFresh: true);
        }

        // Decrement user
        $userKey = "unread:user:{$userId}";
        if (Cache::has($userKey)) {
            $currentUser = (int) Cache::get($userKey, 0);
            $newUser = max(0, $currentUser - $amount);
            Cache::put($userKey, $newUser, self::CACHE_TTL_SECONDS);
        } else {
            $this->reconcileUserUnread($userId);
        }
    }

    public function reconcileUserUnread(int $userId): int
    {
        $count = (int) Message::whereHas('phoneNumber', fn ($q) => $q->where('user_id', $userId))
            ->where('direction', MessageDirection::Inbound)
            ->where('processing_status', MessageProcessingStatus::Received)
            ->count();

        Cache::put("unread:user:{$userId}", $count, self::CACHE_TTL_SECONDS);

        return $count;
    }

    public function getUnreadSummaryForUser(User $user): array
    {
        $lines = $user->phoneNumbers()
            ->where('status', PhoneNumberStatus::Allocated)
            ->get();

        $unreadByLine = [];
        $total = 0;

        foreach ($lines as $line) {
            $lineUnread = $this->getLineUnreadCount($line->id);
            $unreadByLine[$line->id] = $lineUnread;
            $total += $lineUnread;
        }

        // Cache the total
        Cache::put("unread:user:{$user->id}", $total, self::CACHE_TTL_SECONDS);

        return [
            'unread_total' => $total,
            'unread_by_line' => $unreadByLine,
        ];
    }
}

