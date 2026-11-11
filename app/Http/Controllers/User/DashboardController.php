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
                    'rond_tier' => $p->rond_tier ?? 'regular',
                    'rond_tier_label' => $p->rond_tier_label,
                    'accepts_incoming_sms' => (bool) $p->accepts_incoming_sms,
                    'prefix_flag' => $p->phonePrefix?->flag ?? '📱',
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

        $walletModel = $user->getOrCreateWallet();

        $recentTransactions = $walletModel->transactions()
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'type_label' => $t->type->label(),
                'is_credit' => $t->type->isCredit(),
                'badge_classes' => $t->type->badgeClasses(),
                'amount' => (int) round((float) $t->amount),
                'description' => $t->description ?: $t->type->label(),
                'created_at' => $t->created_at->format('Y/m/d H:i'),
                'created_ago' => $t->created_at->diffForHumans(),
            ]);

        $pendingDeposit = \App\Models\CryptoDeposit::where('user_id', $user->id)
            ->whereIn('status', [
                \App\Enums\PaymentStatus::PendingTransfer,
                \App\Enums\PaymentStatus::TxSubmitted,
                \App\Enums\PaymentStatus::Verifying,
            ])
            ->latest('id')
            ->first();

        $customIdsCount = $user->activeServiceAllocations()->where('service_key', 'custom_id')->count();
        $web3Allocations = $user->activeServiceAllocations()->where('service_key', 'web3_email_package')->get();
        $web3DomainsCount = $web3Allocations->count();
        $web3QuotaTotal = (int) $web3Allocations->sum('total_quota');
        $web3QuotaUsed = (int) $web3Allocations->sum('used_quota');

        $recentAllocations = $user->activeServiceAllocations()
            ->with('serviceDefinition')
            ->whereIn('service_key', ['custom_id', 'web3_email_package'])
            ->latest('id')
            ->limit(4)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'service_key' => $a->serviceDefinition?->key ?? $a->service_key,
                'service_title' => $a->serviceDefinition?->title ?? $a->service_key,
                'identifier' => $a->resource_identifier,
                'display_title' => $a->display_title,
                'quota_total' => $a->total_quota,
                'quota_used' => $a->used_quota,
            ]);

        return Inertia::render('Dashboard', [
            'quota' => $quota,
            'service_summary' => [
                'phone_lines_count' => $quota['active'],
                'custom_ids_count' => $customIdsCount,
                'web3_domains_count' => $web3DomainsCount,
                'web3_quota_total' => $web3QuotaTotal,
                'web3_quota_used' => $web3QuotaUsed,
                'recent_allocations' => $recentAllocations,
            ],
            'stats' => [
                'active_numbers' => $quota['active'],
                'standard_numbers' => $metrics['standard_numbers'] ?? 0,
                'service_numbers' => $metrics['service_numbers'] ?? 0,
                'total_messages' => $metrics['total_messages'],
                'inbound_messages' => $metrics['inbound_messages'] ?? 0,
                'outbound_messages' => $metrics['outbound_messages'] ?? 0,
                'today_messages' => $metrics['today_messages'] ?? 0,
                'unread_messages' => $metrics['unread_messages'],
                'pending_requests' => $metrics['pending_requests'],
                'approved_requests' => $metrics['approved_requests'] ?? 0,
                'active_tokens' => $metrics['active_tokens'] ?? 0,
                'total_tokens' => $metrics['total_tokens'] ?? 0,
                'open_tickets' => $metrics['open_tickets'] ?? 0,
                'answered_tickets' => $metrics['answered_tickets'] ?? 0,
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
                'balance' => (string) $walletModel->balance,
                'currency' => $walletModel->currency ?? 'COIN',
                'number_price_coin' => SystemSetting::getNumberPriceCoin(),
                'sms_rate' => (string) SystemSetting::get('sms_rate_per_segment', '0.0100'),
                'recent_transactions' => $recentTransactions,
                'pending_deposit' => $pendingDeposit ? [
                    'tracking_code' => $pendingDeposit->tracking_code,
                    'requested_amount' => (string) $pendingDeposit->requested_amount,
                    'coin_amount' => (string) $pendingDeposit->coin_amount,
                    'currency' => $pendingDeposit->currency,
                    'status_label' => $pendingDeposit->status->label(),
                ] : null,
            ],
            'security_overview' => [
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'connected_identities_count' => $user->identities()->count(),
                'trust_tier' => $user->trust_tier ?? 'standard',
                'web_push_subscribed' => \App\Models\PushSubscription::where('user_id', $user->id)->exists(),
            ],
        ]);
    }
}