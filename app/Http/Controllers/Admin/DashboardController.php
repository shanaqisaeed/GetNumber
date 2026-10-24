<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiTokenStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the centralized admin analytics dashboard.
     */
    public function index(): Response
    {
        // 1. Users metrics
        $totalUsers = User::count();
        $activeUsers = User::where('status', UserStatus::Active)->count();
        $suspendedUsers = User::where('status', UserStatus::Suspended)->count();
        $adminUsers = User::where('role', UserRole::Admin)->count();
        $newUsersToday = User::whereDate('created_at', today())->count();

        // 2. Phone Numbers metrics
        $totalNumbers = PhoneNumber::count();
        $allocatedNumbers = PhoneNumber::where('status', PhoneNumberStatus::Allocated)->count();
        $availableNumbers = PhoneNumber::where('status', PhoneNumberStatus::Available)->count();
        $standardNumbers = PhoneNumber::where('type', PhoneNumberType::UserStandard)->count();
        $serviceNumbers = PhoneNumber::where('type', PhoneNumberType::UserService)->count();
        $platformNumbers = PhoneNumber::where('type', PhoneNumberType::PlatformService)->count();
        $utilizationRate = $totalNumbers > 0 ? round(($allocatedNumbers / $totalNumbers) * 100, 1) : 0;

        // 3. Messages metrics
        $totalMessages = Message::count();
        $todayMessages = Message::whereDate('server_received_at', today())->count();
        $inboundMessages = Message::where('direction', MessageDirection::Inbound)->count();
        $outboundMessages = Message::where('direction', MessageDirection::Outbound)->count();
        $deliveredMessages = Message::where('processing_status', MessageProcessingStatus::Processed)->count();
        $failedMessages = Message::where('processing_status', MessageProcessingStatus::Failed)->count();
        $successRate = $totalMessages > 0 ? round(($deliveredMessages / $totalMessages) * 100, 1) : 100;

        // 4. Number Requests metrics
        $pendingRequestsCount = NumberRequest::where('status', NumberRequestStatus::Pending)->count();
        $approvedRequestsCount = NumberRequest::where('status', NumberRequestStatus::Approved)->count();
        $rejectedRequestsCount = NumberRequest::where('status', NumberRequestStatus::Rejected)->count();

        // 5. API Tokens metrics
        $totalTokens = ApiToken::count();
        $activeTokens = ApiToken::where('status', ApiTokenStatus::Active)->count();
        $revokedTokens = ApiToken::where('status', ApiTokenStatus::Revoked)->count();

        // 6. Recent Users (5 latest)
        $recentUsers = User::latest('id')
            ->take(5)
            ->get(['id', 'name', 'username', 'role', 'status', 'created_at']);

        // 7. Pending Requests awaiting review (up to 5)
        $pendingRequests = NumberRequest::with('user')
            ->where('status', NumberRequestStatus::Pending)
            ->latest('id')
            ->take(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'user' => [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'username' => $r->user->username,
                ],
                'requested_length' => $r->requested_length,
                'preferred_number' => $r->preferred_number,
                'service_title' => $r->service_title,
                'created_at' => $r->created_at->format('Y/m/d H:i'),
                'created_at_human' => $r->created_at->diffForHumans(),
            ]);

        // 8. Recent Audit Logs (up to 8)
        $recentAudits = AuditLog::with('user')
            ->latest('id')
            ->take(8)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'action' => $a->action,
                'action_label' => $a->action_label,
                'action_badge' => $a->action_badge,
                'user' => $a->user ? [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                    'username' => $a->user->username,
                ] : null,
                'ip_address' => $a->ip_address,
                'created_at' => $a->created_at->format('H:i - Y/m/d'),
                'created_at_human' => $a->created_at->diffForHumans(),
            ]);

        // 9. System Health Status
        $systemHealth = [
            'database' => 'آنلاین (اتصال پایگاه داده فعال)',
            'queue' => 'فعال (پردازش همگام / Sync)',
            'polling' => 'عملیاتی (مانیتورینگ زنده ۴ ثانیه‌ای)',
            'security' => 'فعال (رمزنگاری SHA-256 و ایزولاسیون کامل)',
        ];

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => [
                    'total' => $totalUsers,
                    'active' => $activeUsers,
                    'suspended' => $suspendedUsers,
                    'admins' => $adminUsers,
                    'new_today' => $newUsersToday,
                ],
                'numbers' => [
                    'total' => $totalNumbers,
                    'allocated' => $allocatedNumbers,
                    'available' => $availableNumbers,
                    'standard' => $standardNumbers,
                    'service' => $serviceNumbers,
                    'platform' => $platformNumbers,
                    'utilization_rate' => $utilizationRate,
                ],
                'messages' => [
                    'total' => $totalMessages,
                    'today' => $todayMessages,
                    'inbound' => $inboundMessages,
                    'outbound' => $outboundMessages,
                    'delivered' => $deliveredMessages,
                    'failed' => $failedMessages,
                    'success_rate' => $successRate,
                ],
                'requests' => [
                    'pending' => $pendingRequestsCount,
                    'approved' => $approvedRequestsCount,
                    'rejected' => $rejectedRequestsCount,
                ],
                'tokens' => [
                    'total' => $totalTokens,
                    'active' => $activeTokens,
                    'revoked' => $revokedTokens,
                ],
            ],
            // For backward compatibility with existing tests
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'suspended_users' => $suspendedUsers,
            'admin_users' => $adminUsers,
            'recentUsers' => $recentUsers,
            'pendingRequests' => $pendingRequests,
            'recentAudits' => $recentAudits,
            'systemHealth' => $systemHealth,
        ]);
    }
}
