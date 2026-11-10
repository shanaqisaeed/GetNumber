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
use App\Models\SystemSetting;
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
        $cacheService = app(\App\Contracts\SystemCacheServiceInterface::class);

        // 1. Quota & Metrics (Cached)
        $cachedDashboard = $cacheService->getUserDashboardStats($user);
        $quota = $cachedDashboard['quota'];
        $metrics = $cachedDashboard['metrics'];

        // 2. Recent allocated lines & unread summaries (Cached for 60s)
        $dashboardData = \Illuminate\Support\Facades\Cache::remember("user:{$user->id}:dashboard_stream", 60, function () use ($user) {
            $userPhoneIds = $user->phoneNumbers()->pluck('id');

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

            $unreadMessages = Message::whereIn('phone_number_id', $userPhoneIds)
                ->where('direction', MessageDirection::Inbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->with('phoneNumber.prefix')
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

            return [
                'recentNumbers' => $recentNumbers,
                'unreadMessages' => $unreadMessages,
            ];
        });

        return Inertia::render('Dashboard', [
            'quota' => $quota,
            'stats' => [
                'active_numbers' => $quota['active'],
                'total_messages' => $metrics['total_messages'],
                'unread_messages' => $metrics['unread_messages'],
                'pending_requests' => $metrics['pending_requests'],
            ],
            'recent_numbers' => $dashboardData['recentNumbers'],
            'unread_messages' => $dashboardData['unreadMessages'],
            'default_prefix' => PhonePrefix::getDefaultPrefixString(),
            'active_prefixes' => PhonePrefix::active()->get([
                'id',
                'prefix',
                'clean_prefix',
                'name',
                'country_name',
                'country_code',
                'flag',
                'sub_prefixes',
                'price_regular',
                'price_semi_rond',
                'price_golden_rond',
                'is_default',
            ]),
            'wallet' => [
                'balance' => (string) $user->getOrCreateWallet()->balance,
                'number_price_coin' => SystemSetting::getNumberPriceCoin(),
            ],
        ]);
    }
}