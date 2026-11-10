<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\SecurityIncident;
use App\Models\SystemErrorLog;
use App\Services\Monitoring\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringDashboardController extends Controller
{
    public function __construct(
        protected HealthCheckService $healthCheck
    ) {}

    /**
     * Display live Monitoring and Health Oversight dashboard.
     */
    public function index(Request $request): Response
    {
        $metrics = $this->gatherMetrics();

        $recentErrors = SystemErrorLog::latest('last_seen_at')
            ->limit(15)
            ->get();

        return Inertia::render('Admin/Monitoring/Dashboard', [
            'metrics' => $metrics,
            'recentErrors' => $recentErrors,
        ]);
    }

    /**
     * Live JSON telemetry endpoint for real-time dashboard polling.
     */
    public function metrics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'metrics' => $this->gatherMetrics(),
        ]);
    }

    /**
     * Mark an error log as resolved.
     */
    public function resolveError(Request $request, SystemErrorLog $errorLog): JsonResponse|RedirectResponse
    {
        $errorLog->markAsResolved($request->user());

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', __('controllers.admin.monitoring.resolved'));
    }

    /**
     * Gather system, queue, database, and application health metrics.
     */
    protected function gatherMetrics(): array
    {
        // 1. Memory metrics
        $memUsage = memory_get_usage(true);
        $memPeak = memory_get_peak_usage(true);

        // 2. Queue metrics
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        // 3. Messages metrics
        $todayMessages = Message::whereDate('created_at', today())->count();
        $totalMessages = Message::count();

        // 4. Error counts
        $unresolvedErrors = SystemErrorLog::whereNull('resolved_at')->count();
        $criticalErrors = SystemErrorLog::where('level', 'critical')->whereNull('resolved_at')->count();
        $todayErrors = SystemErrorLog::whereDate('last_seen_at', today())->count();

        // 5. Security & Incidents
        $unresolvedIncidents = SecurityIncident::whereNull('resolved_at')->count();

        // 6. Probes
        $readiness = $this->healthCheck->checkReadiness();

        return [
            'server' => [
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'memory_usage_mb' => round($memUsage / 1024 / 1024, 2),
                'memory_peak_mb' => round($memPeak / 1024 / 1024, 2),
                'timestamp' => now()->toIso8601String(),
            ],
            'health' => $readiness,
            'queue' => [
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'status' => $failedJobs === 0 ? 'HEALTHY' : ($failedJobs > 20 ? 'CRITICAL' : 'WARNING'),
            ],
            'messages' => [
                'today_count' => $todayMessages,
                'total_count' => $totalMessages,
            ],
            'errors' => [
                'unresolved' => $unresolvedErrors,
                'critical' => $criticalErrors,
                'today' => $todayErrors,
            ],
            'security' => [
                'unresolved_incidents' => $unresolvedIncidents,
            ],
        ];
    }
}