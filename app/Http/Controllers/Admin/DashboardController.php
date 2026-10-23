<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard with core system metrics.
     */
    public function index(): Response
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', UserStatus::Active)->count(),
            'suspended_users' => User::where('status', UserStatus::Suspended)->count(),
            'admin_users' => User::where('role', UserRole::Admin)->count(),
        ];

        $recentUsers = User::latest()
            ->take(5)
            ->get(['id', 'name', 'username', 'role', 'status', 'created_at']);

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'recentUsers' => $recentUsers,
        ]);
    }
}
