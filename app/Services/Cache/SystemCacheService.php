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
     * Guaranteed to store primitive arrays to eliminate any __PHP_Incomplete_Class serialization issues.
     */
    public function getLineConversations(PhoneNumber $phoneNumber, ?string $search = null): Collection
    {
        $cacheKey = "line:{$phoneNumber->id}:conversations";

        $raw = Cache::get($cacheKey);

        if ($raw instanceof \__PHP_Incomplete_Class || ! is_array($raw)) {
            Cache::forget($cacheKey);
            $raw = $this->buildLineConversations($phoneNumber)->all();
            Cache::put($cacheKey, $raw, self::TTL_CONVERSATIONS);
        }

        $conversations = collect($raw);

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

        if ($afterId !== null && $afterId > 0) {
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

        return $this->buildLineConversationMessages($phoneNumber, $cleanPeer);
    }

    /**
     * Invalidate and immediately re-save/prime fresh conversations for a line.
     * Fulfills: Cache invalidation and update when new inbound/outbound SMS arrives.
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
            $freshConversations = $this->buildLineConversations($phone)->all();
            Cache::put("line:{$lineId}:conversations", $freshConversations, self::TTL_CONVERSATIONS);

            if ($phone->user_id) {
                Cache::forget("user:{$phone->user_id}:dashboard_stats");
                Cache::forget("user:{$phone->user_id}:dashboard_stream");
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
            Cache::forget("user:{$phone->user_id}:dashboard_stream");
        }
    }

    /**
     * Get cached latest message ID for a line (used for zero-DB polling).
     */
    public function getLineLatestMessageId(int $phoneNumberId): int
    {
        $val = Cache::get("line:{$phoneNumberId}:latest_msg_id");
        if ($val instanceof \__PHP_Incomplete_Class || ! is_numeric($val)) {
            Cache::forget("line:{$phoneNumberId}:latest_msg_id");
            $val = (int) (Message::where('phone_number_id', $phoneNumberId)->max('id') ?? 0);
            Cache::put("line:{$phoneNumberId}:latest_msg_id", $val, 3600);
        }

        return (int) $val;
    }

    /**
     * Get global latest message ID (for admin zero-DB polling).
     */
    public function getGlobalLatestMessageId(): int
    {
        $val = Cache::get("messages:global:latest_id");
        if ($val instanceof \__PHP_Incomplete_Class || ! is_numeric($val)) {
            Cache::forget("messages:global:latest_id");
            $val = (int) (Message::max('id') ?? 0);
            Cache::put("messages:global:latest_id", $val, 3600);
        }

        return (int) $val;
    }

    // ==========================================
    // Phone Numbers & Lines Caching
    // ==========================================

    /**
     * Get user's allocated numbers.
     * Caches array of integer IDs in cache (100% immune to __PHP_Incomplete_Class),
     * and performs a sub-millisecond primary key index query on retrieval.
     */
    public function getUserAllocatedNumbers(User $user): Collection
    {
        $cacheKey = "user:{$user->id}:allocated_number_ids";

        $lineIds = Cache::get($cacheKey);

        if ($lineIds instanceof \__PHP_Incomplete_Class || ! is_array($lineIds)) {
            Cache::forget($cacheKey);
            Cache::forget("user:{$user->id}:allocated_numbers"); // Clear legacy key if poisoned
            $lineIds = $user->phoneNumbers()
                ->where('status', PhoneNumberStatus::Allocated)
                ->orderBy('number')
                ->pluck('id')
                ->all();
            Cache::put($cacheKey, $lineIds, self::TTL_USER_LINES);
        }

        if (empty($lineIds)) {
            return collect();
        }

        return PhoneNumber::with(['prefix', 'user'])
            ->whereIn('id', $lineIds)
            ->orderBy('number')
            ->get();
    }

    public function getUserActiveNumbersCount(int $userId): int
    {
        $cacheKey = "user:{$userId}:active_numbers_count";

        $val = Cache::get($cacheKey);
        if ($val instanceof \__PHP_Incomplete_Class || ! is_numeric($val)) {
            Cache::forget($cacheKey);
            $val = PhoneNumber::where('user_id', $userId)
                ->where('status', PhoneNumberStatus::Allocated)
                ->count();
            Cache::put($cacheKey, $val, self::TTL_USER_LINES);
        }

        return (int) $val;
    }

    public function getAdminNumbersStats(): array
    {
        $cacheKey = 'admin:numbers:stats';

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats)) {
            Cache::forget($cacheKey);
            $stats = [
                'total' => PhoneNumber::count(),
                'allocated' => PhoneNumber::where('status', PhoneNumberStatus::Allocated)->count(),
                'available' => PhoneNumber::where('status', PhoneNumberStatus::Available)->count(),
                'suspended' => PhoneNumber::where('status', PhoneNumberStatus::Suspended)->count(),
            ];
            Cache::put($cacheKey, $stats, self::TTL_ADMIN_STATS);
        }

        return $stats;
    }

    public function getSystemPhoneNumbersList(): Collection
    {
        $cacheKey = 'admin:system_phone_numbers_list';

        $list = Cache::get($cacheKey);
        if ($list instanceof \__PHP_Incomplete_Class || ! is_array($list)) {
            Cache::forget($cacheKey);
            $list = PhoneNumber::with(['user', 'prefix'])
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
                ])
                ->all();
            Cache::put($cacheKey, $list, self::TTL_ADMIN_STATS);
        }

        return collect($list);
    }

    public function handlePhoneNumberChanged(PhoneNumber $phoneNumber): void
    {
        if ($phoneNumber->user_id) {
            Cache::forget("user:{$phoneNumber->user_id}:allocated_number_ids");
            Cache::forget("user:{$phoneNumber->user_id}:allocated_numbers");
            Cache::forget("user:{$phoneNumber->user_id}:active_numbers_count");
            Cache::forget("user:{$phoneNumber->user_id}:dashboard_stats");
            Cache::forget("user:{$phoneNumber->user_id}:dashboard_stream");
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
        $cacheKey = 'admin:users:stats';

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats)) {
            Cache::forget($cacheKey);
            $stats = [
                'total' => User::count(),
                'active' => User::where('status', UserStatus::Active)->count(),
                'suspended' => User::where('status', UserStatus::Suspended)->count(),
                'admins' => User::where('role', UserRole::Admin)->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ];
            Cache::put($cacheKey, $stats, self::TTL_ADMIN_STATS);
        }

        return $stats;
    }

    /**
     * Get users for admin dropdowns.
     * Caches plain associative array of strings/integers, wrapping in Collection upon return.
     */
    public function getAdminUsersDropdown(): Collection
    {
        $cacheKey = 'admin:users:dropdown';

        $users = Cache::get($cacheKey);

        if ($users instanceof \__PHP_Incomplete_Class || ! is_array($users)) {
            Cache::forget($cacheKey);
            $users = User::select('id', 'name', 'username')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                ])
                ->all();
            Cache::put($cacheKey, $users, self::TTL_DROPDOWNS);
        }

        return collect($users)->map(fn ($item) => (object) $item);
    }

    public function handleUserChanged(User $user): void
    {
        Cache::forget('admin:users:stats');
        Cache::forget('admin:users:dropdown');
        Cache::forget('admin:dashboard:stats');
        Cache::forget("user:{$user->id}:dashboard_stats");
        Cache::forget("user:{$user->id}:dashboard_stream");
    }

    // ==========================================
    // Requests & Logs Caching
    // ==========================================

    public function getUserPendingRequestsCount(int $userId): int
    {
        $cacheKey = "user:{$userId}:pending_requests_count";

        $val = Cache::get($cacheKey);
        if ($val instanceof \__PHP_Incomplete_Class || ! is_numeric($val)) {
            Cache::forget($cacheKey);
            $val = NumberRequest::where('user_id', $userId)
                ->where('status', NumberRequestStatus::Pending)
                ->count();
            Cache::put($cacheKey, $val, self::TTL_USER_LINES);
        }

        return (int) $val;
    }

    public function getAdminRequestsStats(): array
    {
        $cacheKey = 'admin:requests:stats';

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats)) {
            Cache::forget($cacheKey);
            $stats = [
                'pending' => NumberRequest::where('status', NumberRequestStatus::Pending)->count(),
                'approved' => NumberRequest::where('status', NumberRequestStatus::Approved)->count(),
                'rejected' => NumberRequest::where('status', NumberRequestStatus::Rejected)->count(),
            ];
            Cache::put($cacheKey, $stats, self::TTL_ADMIN_STATS);
        }

        return $stats;
    }

    public function handleNumberRequestChanged(NumberRequest $request): void
    {
        if ($request->user_id) {
            Cache::forget("user:{$request->user_id}:pending_requests_count");
            Cache::forget("user:{$request->user_id}:dashboard_stats");
            Cache::forget("user:{$request->user_id}:dashboard_stream");
        }

        Cache::forget('admin:requests:stats');
        Cache::forget('admin:dashboard:stats');
    }

    public function getAuditLogsStats(): array
    {
        $cacheKey = 'admin:audit_logs:stats';

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats)) {
            Cache::forget($cacheKey);
            $stats = [
                'total' => AuditLog::count(),
                'today' => AuditLog::whereDate('created_at', today())->count(),
                'number_events' => AuditLog::where('action', 'like', 'number_%')->count(),
                'security_events' => AuditLog::where(function ($q) {
                    $q->where('action', 'like', 'token_%')
                      ->orWhere('action', 'like', 'user_%');
                })->count(),
            ];
            Cache::put($cacheKey, $stats, self::TTL_LOG_STATS);
        }

        return $stats;
    }

    public function getSecurityIncidentsStats(): array
    {
        $cacheKey = 'admin:security_incidents:stats';

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats)) {
            Cache::forget($cacheKey);
            $stats = [
                'total' => SecurityIncident::count(),
                'unresolved' => SecurityIncident::whereNull('resolved_at')->count(),
                'critical' => SecurityIncident::whereIn('severity', ['high', 'critical'])->whereNull('resolved_at')->count(),
                'today' => SecurityIncident::whereDate('created_at', today())->count(),
            ];
            Cache::put($cacheKey, $stats, self::TTL_LOG_STATS);
        }

        return $stats;
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
        $cacheKey = 'admin:dashboard:stats';

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats)) {
            Cache::forget($cacheKey);

            $totalUsers = User::count();
            $allocatedNumbers = PhoneNumber::where('status', PhoneNumberStatus::Allocated)->count();
            $totalNumbers = PhoneNumber::count();
            $totalMessages = Message::count();
            $deliveredMessages = Message::where('processing_status', MessageProcessingStatus::Processed)->count();

            $stats = [
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
            Cache::put($cacheKey, $stats, self::TTL_DASHBOARD);
        }

        return $stats;
    }

    public function getUserDashboardStats(User $user): array
    {
        $cacheKey = "user:{$user->id}:dashboard_stats";

        $stats = Cache::get($cacheKey);
        if ($stats instanceof \__PHP_Incomplete_Class || ! is_array($stats) || ! isset($stats['metrics']['standard_numbers'])) {
            Cache::forget($cacheKey);

            $activeCount = $this->getUserActiveNumbersCount($user->id);
            $maxQuota = (int) \App\Models\SystemSetting::get('max_lines_per_user', PhoneNumberAllocationService::DEFAULT_MAX_QUOTA_PER_USER);
            $userPhoneIds = $user->phoneNumbers()->pluck('id');

            $standardNumbers = PhoneNumber::where('user_id', $user->id)
                ->where('status', PhoneNumberStatus::Allocated)
                ->where('type', PhoneNumberType::UserStandard)
                ->count();
            $serviceNumbers = PhoneNumber::where('user_id', $user->id)
                ->where('status', PhoneNumberStatus::Allocated)
                ->where('type', PhoneNumberType::UserService)
                ->count();

            $totalMessages = Message::whereIn('phone_number_id', $userPhoneIds)->count();
            $inboundMessages = Message::whereIn('phone_number_id', $userPhoneIds)
                ->where('direction', MessageDirection::Inbound)
                ->count();
            $outboundMessages = Message::whereIn('phone_number_id', $userPhoneIds)
                ->where('direction', MessageDirection::Outbound)
                ->count();
            $todayMessages = Message::whereIn('phone_number_id', $userPhoneIds)
                ->whereDate('server_received_at', today())
                ->count();
            $unreadMessages = Message::whereIn('phone_number_id', $userPhoneIds)
                ->where('direction', MessageDirection::Inbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->count();

            $pendingRequests = $this->getUserPendingRequestsCount($user->id);
            $approvedRequests = NumberRequest::where('user_id', $user->id)
                ->where('status', NumberRequestStatus::Approved)
                ->count();

            $activeTokens = ApiToken::where('user_id', $user->id)
                ->where('status', ApiTokenStatus::Active)
                ->count();
            $totalTokens = ApiToken::where('user_id', $user->id)->count();

            $openTickets = \App\Models\Ticket::where('user_id', $user->id)
                ->where('status', '!=', \App\Enums\TicketStatus::Closed)
                ->count();
            $answeredTickets = \App\Models\Ticket::where('user_id', $user->id)
                ->where('status', \App\Enums\TicketStatus::Answered)
                ->count();

            $stats = [
                'quota' => [
                    'active' => $activeCount,
                    'max' => $maxQuota,
                    'remaining' => max(0, $maxQuota - $activeCount),
                    'percentage' => min(100, (int) round(($activeCount / max(1, $maxQuota)) * 100)),
                ],
                'metrics' => [
                    'total_messages' => $totalMessages,
                    'inbound_messages' => $inboundMessages,
                    'outbound_messages' => $outboundMessages,
                    'today_messages' => $todayMessages,
                    'unread_messages' => $unreadMessages,
                    'pending_requests' => $pendingRequests,
                    'approved_requests' => $approvedRequests,
                    'standard_numbers' => $standardNumbers,
                    'service_numbers' => $serviceNumbers,
                    'active_tokens' => $activeTokens,
                    'total_tokens' => $totalTokens,
                    'open_tickets' => $openTickets,
                    'answered_tickets' => $answeredTickets,
                ],
            ];
            Cache::put($cacheKey, $stats, self::TTL_DASHBOARD);
        }

        return $stats;
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
                    $canReplyReason = __('services.cache.one_way_line_no_reply');
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