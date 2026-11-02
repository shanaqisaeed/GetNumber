<?php

namespace App\Services\Cache;

use App\Contracts\SystemCacheServiceInterface;
use App\Enums\ApiTokenStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\PhoneNumberAllocationService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemCacheService implements SystemCacheServiceInterface
{
    // Default TTL Constants
    public const TTL_CONVERSATIONS = 3600;     // 1 Hour (auto-refreshed on new SMS)
    public const TTL_USER_LINES = 3600;        // 1 Hour (invalidated on allocation/release)
    public const TTL_ADMIN_STATS = 300;        // 5 Minutes (invalidated on entity changes)
    public const TTL_DASHBOARD = 60;           // 60 Seconds (invalidated on entity events)
    public const TTL_LOG_STATS = 120;          // 2 Minutes
    public const TTL_DROPDOWNS = 600;          // 10 Minutes

    // ==========================================
    // SMS & Conversation Caching
    // ==========================================

    /**
     * Get or calculate cached conversations for a phone number.
     */
    public function getLineConversations(PhoneNumber $phoneNumber, ?string $search = null): Collection
    {
        $cacheKey = "line:{$phoneNumber->id}:conversations";

        $conversations = Cache::remember($cacheKey, self::TTL_CONVERSATIONS, function () use ($phoneNumber) {
            return $this->buildLineConversations($phoneNumber);
        });

        if (! empty($search)) {
            return $this->filterConversations($conversations, $phoneNumber, $search);
        }

        return $conversations;
    }

    /**
     * Get or calculate cached messages for a specific conversation peer.
     */
    public function getLineConversationMessages(PhoneNumber $phoneNumber, string $peer, ?int $afterId = null): Collection
    {
        $cleanPeer = PhoneNumberNormalizer::resolveToStoredNumber($peer);
        $cacheKey = "line:{$phoneNumber->id}:peer:{$cleanPeer}:messages";

        if ($afterId === null || $afterId === 0) {
            return Cache::remember($cacheKey, self::TTL_CONVERSATIONS, function () use ($phoneNumber, $cleanPeer) {
                return $this->buildLineConversationMessages($phoneNumber, $cleanPeer);
            });
        }

        // Fast in-memory filter if full list is already cached
        $cached = Cache::get($cacheKey);
        if ($cached instanceof Collection) {
            $delta = $cached->where('id', '>', $afterId)->values();
            if ($delta->isNotEmpty()) {
                return $delta;
            }
        }

        // Fallback delta query
        return Message::where('phone_number_id', $phoneNumber->id)
            ->where(function ($q) use ($cleanPeer, $peer) {
                $q->where('peer_number', $cleanPeer)
                  ->orWhere('peer_number', $peer)
                  ->orWhere('sender', $cleanPeer)
                  ->orWhere('destination_number', $cleanPeer);
            })
            ->where('id', '>', $afterId)
            ->latest('server_received_at')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Invalidate and immediately re-save/prime fresh conversations and messages for a line.
     * Fulfills: "وقتی پیامک جدید میاد ما باید پیامک اون شماره که پیامک اومده بهش پاک کنیم جدید سیو کنیم"
     */
    public function handleMessageCreated(Message $message): void
    {
        $lineId = $message->phone_number_id;
        if (! $lineId) {
            return;
        }

        $peer = $message->peer_number ?: ($message->direction === MessageDirection::Outbound ? $message->destination_number : $message->sender);
        $cleanPeer = $peer ? PhoneNumberNormalizer::resolveToStoredNumber($peer) : null;

        // 1. Invalidate stale caches
        Cache::forget("line:{$lineId}:conversations");
        if ($cleanPeer) {
            Cache::forget("line:{$lineId}:peer:{$cleanPeer}:messages");
        }

        // 2. Update real-time latest message trackers (eliminates database polling queries)
        Cache::put("line:{$lineId}:latest_msg_id", $message->id, 86400);
        Cache::put("messages:global:latest_id", $message->id, 86400);
        Cache::forget('admin:messages:stats');
        Cache::forget('admin:dashboard:stats');

        // 3. Immediately prime & save fresh state for this phone number
        $phone = $message->phoneNumber ?: PhoneNumber::with('user')->find($lineId);
        if ($phone) {
            $freshConversations = $this->buildLineConversations($phone);
            Cache::put("line:{$lineId}:conversations", $freshConversations, self::TTL_CONVERSATIONS);

            if ($cleanPeer) {
                $freshChat = $this->buildLineConversationMessages($phone, $cleanPeer);
                Cache::put("line:{$lineId}:peer:{$cleanPeer}:messages", $freshChat, self::TTL_CONVERSATIONS);
            }

            if ($phone->user_id) {
                Cache::forget("user:{$phone->user_id}:dashboard_stats");
            }
        }
    }

    /**
     * Invalidate cached messages when a message is deleted.
     */
    public function handleMessageDeleted(Message $message): void
    {
        $lineId = $message->phone_number_id;
        if (! $lineId) {
            return;
        }

        $peer = $message->peer_number ?: ($message->direction === MessageDirection::Outbound ? $message->destination_number : $message->sender);
        $cleanPeer = $peer ? PhoneNumberNormalizer::resolveToStoredNumber($peer) : null;

        Cache::forget("line:{$lineId}:conversations");
        if ($cleanPeer) {
            Cache::forget("line:{$lineId}:peer:{$cleanPeer}:messages");
        }

        Cache::forget("line:{$lineId}:latest_msg_id");
        Cache::forget("messages:global:latest_id");
        Cache::forget('admin:messages:stats');
        Cache::forget('admin:dashboard:stats');

        $phone = $message->phoneNumber ?: PhoneNumber::find($lineId);
        if ($phone && $phone->user_id) {
            Cache::forget("user:{$phone->user_id}:dashboard_stats");
        }
    }

    /**
     * Get cached latest message ID for a line (used for zero-DB polling).
     */
    public function getLineLatestMessageId(int $phoneNumberId): int
    {
        return (int) Cache::remember("line:{$phoneNumberId}:latest_msg_id", 3600, function () use ($phoneNumberId) {
            return (int) (Message::where('phone_number_id', $phoneNumberId)->max('id') ?? 0);
        });
    }

    /**
     * Get global latest message ID (for admin zero-DB polling).
     */
    public function getGlobalLatestMessageId(): int
    {
        return (int) Cache::remember("messages:global:latest_id", 3600, function () {
            return (int) (Message::max('id') ?? 0);
        });
    }

    // ==========================================
    // Phone Numbers & Lines Caching
    // ==========================================

    public function getUserAllocatedNumbers(User $user): Collection
    {
        return Cache::remember("user:{$user->id}:allocated_numbers", self::TTL_USER_LINES, function () use ($user) {
            return $user->phoneNumbers()
                ->where('status', PhoneNumberStatus::Allocated)
                ->orderBy('number')
                ->get();
        });
    }

    public function getUserActiveNumbersCount(int $userId): int
    {
        return (int) Cache::remember("user:{$userId}:active_numbers_count", self::TTL_USER_LINES, function () use ($userId) {
            return PhoneNumber::where('user_id', $userId)
                ->where('status', PhoneNumberStatus::Allocated)
                ->count();
        });
    }

    public function getAdminNumbersStats(): array
    {
        return Cache::remember('admin:numbers:stats', self::TTL_ADMIN_STATS, function () {
            return [
                'total' => PhoneNumber::count(),
                'allocated' => PhoneNumber::where('status', PhoneNumberStatus::Allocated)->count(),
                'available' => PhoneNumber::where('status', PhoneNumberStatus::Available)->count(),
                'suspended' => PhoneNumber::where('status', PhoneNumberStatus::Suspended)->count(),
            ];
        });
    }

    public function getSystemPhoneNumbersList(): Collection
    {
        return Cache::remember('admin:system_phone_numbers_list', self::TTL_ADMIN_STATS, function () {
            return PhoneNumber::with(['user', 'prefix'])
                ->orderBy('number')
                ->get()
                ->map(fn (PhoneNumber $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'formatted' => $p->formatted_number,
                    'title' => $p->effective_title,
                    'type' => $p->type->value,
                    'type_label' => $p->type->label(),
                    'user' => $p->user ? [
                        'id' => $p->user->id,
                        'name' => $p->user->name,
                        'username' => $p->user->username,
                    ] : null,
                ]);
        });
    }

    public function handlePhoneNumberChanged(PhoneNumber $phoneNumber): void
    {
        if ($phoneNumber->user_id) {
            Cache::forget("user:{$phoneNumber->user_id}:allocated_numbers");
            Cache::forget("user:{$phoneNumber->user_id}:active_numbers_count");
            Cache::forget("user:{$phoneNumber->user_id}:dashboard_stats");
        }

        Cache::forget('admin:numbers:stats');
        Cache::forget('admin:system_phone_numbers_list');
        Cache::forget('admin:dashboard:stats');
    }

    // ==========================================
    // Users Caching
    // ==========================================

    public function getAdminUsersStats(): array
    {
        return Cache::remember('admin:users:stats', self::TTL_ADMIN_STATS, function () {
            return [
                'total' => User::count(),
                'active' => User::where('status', UserStatus::Active)->count(),
                'suspended' => User::where('status', UserStatus::Suspended)->count(),
                'admins' => User::where('role', UserRole::Admin)->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ];
        });
    }

    public function getAdminUsersDropdown(): Collection
    {
        return Cache::remember('admin:users:dropdown', self::TTL_DROPDOWNS, function () {
            return User::select('id', 'name', 'username')
                ->orderBy('name')
                ->get();
        });
    }

    public function handleUserChanged(User $user): void
    {
        Cache::forget('admin:users:stats');
        Cache::forget('admin:users:dropdown');
        Cache::forget('admin:dashboard:stats');
        Cache::forget("user:{$user->id}:dashboard_stats");
    }

    // ==========================================
    // Requests & Logs Caching
    // ==========================================

    public function getUserPendingRequestsCount(int $userId): int
    {
        return (int) Cache::remember("user:{$userId}:pending_requests_count", self::TTL_USER_LINES, function () use ($userId) {
            return NumberRequest::where('user_id', $userId)
                ->where('status', NumberRequestStatus::Pending)
                ->count();
        });
    }

    public function getAdminRequestsStats(): array
    {
        return Cache::remember('admin:requests:stats', self::TTL_ADMIN_STATS, function () {
            return [
                'pending' => NumberRequest::where('status', NumberRequestStatus::Pending)->count(),
                'approved' => NumberRequest::where('status', NumberRequestStatus::Approved)->count(),
                'rejected' => NumberRequest::where('status', NumberRequestStatus::Rejected)->count(),
            ];
        });
    }

    public function handleNumberRequestChanged(NumberRequest $request): void
    {
        if ($request->user_id) {
            Cache::forget("user:{$request->user_id}:pending_requests_count");
            Cache::forget("user:{$request->user_id}:dashboard_stats");
        }

        Cache::forget('admin:requests:stats');
        Cache::forget('admin:dashboard:stats');
    }

    public function getAuditLogsStats(): array
    {
        return Cache::remember('admin:audit_logs:stats', self::TTL_LOG_STATS, function () {
            return [
                'total' => AuditLog::count(),
                'today' => AuditLog::whereDate('created_at', today())->count(),
                'number_events' => AuditLog::where('action', 'like', 'number_%')->count(),
                'security_events' => AuditLog::where(function ($q) {
                    $q->where('action', 'like', 'token_%')
                      ->orWhere('action', 'like', 'user_%');
                })->count(),
            ];
        });
    }

    public function getSecurityIncidentsStats(): array
    {
        return Cache::remember('admin:security_incidents:stats', self::TTL_LOG_STATS, function () {
            return [
                'total' => SecurityIncident::count(),
                'unresolved' => SecurityIncident::whereNull('resolved_at')->count(),
                'critical' => SecurityIncident::whereIn('severity', ['high', 'critical'])->whereNull('resolved_at')->count(),
                'today' => SecurityIncident::whereDate('created_at', today())->count(),
            ];
        });
    }

    public function handleSecurityIncidentChanged(SecurityIncident $incident): void
    {
        Cache::forget('admin:security_incidents:stats');
    }

    // ==========================================
    // Dashboards Aggregates Caching
    // ==========================================

    public function getAdminDashboardStats(): array
    {
        return Cache::remember('admin:dashboard:stats', self::TTL_DASHBOARD, function () {
            $totalUsers = User::count();
            $allocatedNumbers = PhoneNumber::where('status', PhoneNumberStatus::Allocated)->count();
            $totalNumbers = PhoneNumber::count();
            $totalMessages = Message::count();
            $deliveredMessages = Message::where('processing_status', MessageProcessingStatus::Processed)->count();

            return [
                'users' => [
                    'total' => $totalUsers,
                    'active' => User::where('status', UserStatus::Active)->count(),
                    'suspended' => User::where('status', UserStatus::Suspended)->count(),
                    'admins' => User::where('role', UserRole::Admin)->count(),
                    'new_today' => User::whereDate('created_at', today())->count(),
                ],
                'numbers' => [
                    'total' => $totalNumbers,
                    'allocated' => $allocatedNumbers,
                    'available' => PhoneNumber::where('status', PhoneNumberStatus::Available)->count(),
                    'standard' => PhoneNumber::where('type', PhoneNumberType::UserStandard)->count(),
                    'service' => PhoneNumber::where('type', PhoneNumberType::UserService)->count(),
                    'platform' => PhoneNumber::where('type', PhoneNumberType::PlatformService)->count(),
                    'utilization_rate' => $totalNumbers > 0 ? round(($allocatedNumbers / $totalNumbers) * 100, 1) : 0,
                ],
                'messages' => [
                    'total' => $totalMessages,
                    'today' => Message::whereDate('server_received_at', today())->count(),
                    'inbound' => Message::where('direction', MessageDirection::Inbound)->count(),
                    'outbound' => Message::where('direction', MessageDirection::Outbound)->count(),
                    'delivered' => $deliveredMessages,
                    'failed' => Message::where('processing_status', MessageProcessingStatus::Failed)->count(),
                    'success_rate' => $totalMessages > 0 ? round(($deliveredMessages / $totalMessages) * 100, 1) : 100,
                ],
                'requests' => [
                    'pending' => NumberRequest::where('status', NumberRequestStatus::Pending)->count(),
                    'approved' => NumberRequest::where('status', NumberRequestStatus::Approved)->count(),
                    'rejected' => NumberRequest::where('status', NumberRequestStatus::Rejected)->count(),
                ],
                'tokens' => [
                    'total' => ApiToken::count(),
                    'active' => ApiToken::where('status', ApiTokenStatus::Active)->count(),
                    'revoked' => ApiToken::where('status', ApiTokenStatus::Revoked)->count(),
                ],
            ];
        });
    }

    public function getUserDashboardStats(User $user): array
    {
        return Cache::remember("user:{$user->id}:dashboard_stats", self::TTL_DASHBOARD, function () use ($user) {
            $activeCount = $this->getUserActiveNumbersCount($user->id);
            $maxQuota = PhoneNumberAllocationService::DEFAULT_MAX_QUOTA_PER_USER;
            $userPhoneIds = $user->phoneNumbers()->pluck('id');

            $totalMessages = Message::whereIn('phone_number_id', $userPhoneIds)->count();
            $unreadMessages = Message::whereIn('phone_number_id', $userPhoneIds)
                ->where('direction', MessageDirection::Inbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->count();
            $pendingRequests = $this->getUserPendingRequestsCount($user->id);

            return [
                'quota' => [
                    'active' => $activeCount,
                    'max' => $maxQuota,
                    'remaining' => max(0, $maxQuota - $activeCount),
                    'percentage' => min(100, (int) round(($activeCount / max(1, $maxQuota)) * 100)),
                ],
                'metrics' => [
                    'total_messages' => $totalMessages,
                    'unread_messages' => $unreadMessages,
                    'pending_requests' => $pendingRequests,
                ],
            ];
        });
    }

    // ==========================================
    // Internal Helper Builders
    // ==========================================

    protected function buildLineConversations(PhoneNumber $phoneNumber): Collection
    {
        $peerNumbers = Message::where('phone_number_id', $phoneNumber->id)
            ->whereNotNull('peer_number')
            ->select('peer_number')
            ->distinct()
            ->pluck('peer_number');

        if ($peerNumbers->isEmpty()) {
            return collect();
        }

        $peerRecords = PhoneNumber::with('user')
            ->whereIn('number', $peerNumbers)
            ->get()
            ->keyBy('number');

        $defaultPrefix = PhonePrefix::getDefaultPrefixString();
        $conversations = collect();

        foreach ($peerNumbers as $peer) {
            $latestMessage = Message::where('phone_number_id', $phoneNumber->id)
                ->where('peer_number', $peer)
                ->latest('server_received_at')
                ->latest('id')
                ->first();

            if (! $latestMessage) {
                continue;
            }

            $peerRecord = $peerRecords->get($peer);
            $peerTitle = $peerRecord?->display_title ?: ($peerRecord?->user?->name ?: ($peerRecord?->effective_title ?? "{$defaultPrefix} {$peer}"));

            $totalCount = Message::where('phone_number_id', $phoneNumber->id)
                ->where('peer_number', $peer)
                ->count();

            $unreadCount = Message::where('phone_number_id', $phoneNumber->id)
                ->where('peer_number', $peer)
                ->where('direction', MessageDirection::Inbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->count();

            $isOwn = $peerRecord && $peerRecord->user_id === $phoneNumber->user_id;
            $isCommercial = $peerRecord ? $peerRecord->type->isService() : false;

            $canReply = true;
            $canReplyReason = null;
            if ($peerRecord) {
                if (! $peerRecord->accepts_incoming_sms) {
                    $canReply = false;
                    $canReplyReason = 'این سرشماره تجاری صرفاً یک‌طرفه بوده و امکان دریافت پیامک یا پاسخ را ندارد.';
                } else {
                    $check = $peerRecord->canReceiveMessagesFrom($phoneNumber, $phoneNumber->user);
                    if (! $check['allowed']) {
                        $canReply = false;
                        $canReplyReason = $check['reason'];
                    }
                }
            }

            $conversations->push([
                'peer_number' => $peer,
                'peer_formatted' => $peerRecord ? $peerRecord->formatted_number : "{$defaultPrefix} {$peer}",
                'peer_title' => $peerTitle,
                'peer_type' => $peerRecord?->type->value,
                'peer_type_label' => $peerRecord?->type->label(),
                'peer_badge_class' => $peerRecord?->type->badgeClasses(),
                'is_own_number' => $isOwn,
                'is_commercial' => $isCommercial,
                'accepts_incoming_sms' => $peerRecord ? (bool) $peerRecord->accepts_incoming_sms : true,
                'can_reply' => $canReply,
                'can_reply_reason' => $canReplyReason,
                'latest_message' => [
                    'id' => $latestMessage->id,
                    'body' => $latestMessage->body,
                    'direction' => $latestMessage->direction->value,
                    'server_received_at' => $latestMessage->server_received_at->toIso8601String(),
                    'server_received_at_formatted' => $latestMessage->server_received_at->diffForHumans(),
                ],
                'messages_count' => $totalCount,
                'unread_count' => $unreadCount,
            ]);
        }

        return $conversations->sortByDesc(fn ($c) => $c['latest_message']['server_received_at'])->values();
    }

    protected function buildLineConversationMessages(PhoneNumber $phoneNumber, string $cleanPeer): Collection
    {
        return Message::where('phone_number_id', $phoneNumber->id)
            ->where(function ($q) use ($cleanPeer) {
                $q->where('peer_number', $cleanPeer)
                  ->orWhere('sender', $cleanPeer)
                  ->orWhere('destination_number', $cleanPeer);
            })
            ->latest('server_received_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();
    }

    protected function filterConversations(Collection $conversations, PhoneNumber $phoneNumber, string $search): Collection
    {
        $s = mb_strtolower(trim($search));

        return $conversations->filter(function ($conv) use ($s, $phoneNumber) {
            $matchesPeer = str_contains((string) $conv['peer_number'], $s);
            $matchesTitle = str_contains(mb_strtolower((string) $conv['peer_title']), $s);
            $matchesLatest = str_contains(mb_strtolower((string) $conv['latest_message']['body']), $s);

            if ($matchesPeer || $matchesTitle || $matchesLatest) {
                return true;
            }

            // Check message bodies for match
            return Message::where('phone_number_id', $phoneNumber->id)
                ->where('peer_number', $conv['peer_number'])
                ->where('body', 'like', "%{$s}%")
                ->exists();
        })->values();
    }
}

