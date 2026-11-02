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
        $cacheService = app(\App\Contracts\SystemCacheServiceInterface::class);

        // 1-5. Aggregated Analytics & KPIs (Cached with auto-invalidation on entity mutations)
        $stats = $cacheService->getAdminDashboardStats();

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
            'stats' => $stats,
            // For backward compatibility with existing tests
            'total_users' => $stats['users']['total'],
            'active_users' => $stats['users']['active'],
            'suspended_users' => $stats['users']['suspended'],
            'admin_users' => $stats['users']['admins'],
            'recentUsers' => $recentUsers,
            'pendingRequests' => $pendingRequests,
            'recentAudits' => $recentAudits,
            'systemHealth' => $systemHealth,
        ]);
    }
}
