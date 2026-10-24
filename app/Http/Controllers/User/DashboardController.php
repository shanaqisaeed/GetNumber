<?php

namespace App\Http\Controllers\User;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\NumberRequest;
use App\Models\PhonePrefix;
use App\Services\PhoneNumberAllocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the mobile-experience user dashboard with real quota, line stats, and recent lines.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. Quota calculation
        $activeNumbersCount = $user->phoneNumbers()
            ->where('status', PhoneNumberStatus::Allocated)
            ->count();

        $maxQuota = PhoneNumberAllocationService::DEFAULT_MAX_QUOTA_PER_USER;
        $remainingQuota = max(0, $maxQuota - $activeNumbersCount);
        $quotaPercentage = min(100, (int) round(($activeNumbersCount / max(1, $maxQuota)) * 100));

        // 2. Aggregate metrics
        $userPhoneIds = $user->phoneNumbers()->pluck('id');

        $totalMessagesCount = Message::whereIn('phone_number_id', $userPhoneIds)->count();

        $unreadMessagesCount = Message::whereIn('phone_number_id', $userPhoneIds)
            ->where('direction', MessageDirection::Inbound)
            ->where('processing_status', MessageProcessingStatus::Received)
            ->count();

        $pendingRequestsCount = NumberRequest::where('user_id', $user->id)
            ->where('status', NumberRequestStatus::Pending)
            ->count();

        // 3. Recent allocated lines
        $recentNumbers = $user->phoneNumbers()
            ->where('status', PhoneNumberStatus::Allocated)
            ->with('phonePrefix')
            ->latest('allocated_at')
            ->latest('id')
            ->limit(4)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title,
                'type' => $p->type->value,
                'type_label' => $p->type->label(),
                'allocated_at' => $p->allocated_at?->format('Y/m/d H:i') ?? $p->created_at->format('Y/m/d H:i'),
                'allocated_ago' => $p->allocated_at?->diffForHumans() ?? $p->created_at->diffForHumans(),
            ]);

        // 4. Real unread message sender summaries (who sent them and how many unread)
        $unreadMessages = Message::whereIn('phone_number_id', $userPhoneIds)
            ->where('direction', MessageDirection::Inbound)
            ->where('processing_status', MessageProcessingStatus::Received)
            ->with('phoneNumber')
            ->selectRaw('sender, phone_number_id, count(*) as count, max(server_received_at) as latest_received_at')
            ->groupBy('sender', 'phone_number_id')
            ->orderByDesc('latest_received_at')
            ->get()
            ->map(fn ($g) => [
                'sender' => $g->sender,
                'phone_number_id' => $g->phone_number_id,
                'destination_number' => $g->phoneNumber?->formatted_number ?? '',
                'count' => (int) $g->count,
                'received_at' => $g->latest_received_at ? \Carbon\Carbon::parse($g->latest_received_at)->diffForHumans() : '',
            ]);

        return Inertia::render('Dashboard', [
            'quota' => [
                'active' => $activeNumbersCount,
                'max' => $maxQuota,
                'remaining' => $remainingQuota,
                'percentage' => $quotaPercentage,
            ],
            'stats' => [
                'active_numbers' => $activeNumbersCount,
                'total_messages' => $totalMessagesCount,
                'unread_messages' => $unreadMessagesCount,
                'pending_requests' => $pendingRequestsCount,
            ],
            'recent_numbers' => $recentNumbers,
            'unread_messages' => $unreadMessages,
            'default_prefix' => PhonePrefix::getDefaultPrefixString(),
        ]);
    }
}
