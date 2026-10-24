<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Display a listing of system audit logs with full filtering.
     */
    public function index(Request $request): Response
    {
        $query = AuditLog::with('user');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->where('id', (int) $search)
                      ->orWhere('entity_id', (int) $search);
                }
                $q->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('username', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('action')) {
            $action = $request->input('action');
            if (str_ends_with($action, '*')) {
                $prefix = rtrim($action, '*');
                $query->where('action', 'like', "{$prefix}%");
            } else {
                $query->where('action', $action);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $logs = $query->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'action_badge' => $log->action_badge,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'username' => $log->user->username,
                    'is_admin' => $log->user->isAdmin(),
                ] : null,
                'entity_type' => $this->simplifyEntityType($log->entity_type),
                'entity_id' => $log->entity_id,
                'payload' => $log->payload,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->format('Y/m/d H:i:s'),
                'created_at_human' => $log->created_at->diffForHumans(),
            ]);

        $stats = [
            'total' => AuditLog::count(),
            'today' => AuditLog::whereDate('created_at', today())->count(),
            'number_events' => AuditLog::where('action', 'like', 'number_%')->count(),
            'security_events' => AuditLog::where(function ($q) {
                $q->where('action', 'like', 'token_%')
                  ->orWhere('action', 'like', 'user_%');
            })->count(),
        ];

        $actionOptions = [
            ['value' => '', 'label' => 'همه رخدادها'],
            ['value' => 'user_*', 'label' => 'عملیات کاربران (ایجاد، ویرایش، تعلیق)'],
            ['value' => 'number_*', 'label' => 'مدیریت خطوط (تولید، تخصیص، آزادسازی)'],
            ['value' => 'token_*', 'label' => 'کلیدهای وب‌سرویس (صدور، تعویض، ابطال)'],
            ['value' => 'request_*', 'label' => 'درخواست‌های سرشماره (تأیید و رد)'],
            ['value' => 'message_*', 'label' => 'عملیات پیام‌ها'],
            ['value' => 'user_created', 'label' => 'ایجاد کاربر جدید'],
            ['value' => 'user_status_changed', 'label' => 'تغییر وضعیت حساب کاربر'],
            ['value' => 'user_updated', 'label' => 'ویرایش مشخصات کاربر'],
            ['value' => 'number_created', 'label' => 'تولید/ثبت خط جدید'],
            ['value' => 'number_assigned', 'label' => 'تخصیص خط به کاربر'],
            ['value' => 'number_released', 'label' => 'آزادسازی خط'],
            ['value' => 'number_status_changed', 'label' => 'تغییر وضعیت خط'],
            ['value' => 'request_approved', 'label' => 'تأیید درخواست سرشماره'],
            ['value' => 'request_rejected', 'label' => 'رد درخواست سرشماره'],
            ['value' => 'token_created', 'label' => 'صدور کلید وب‌سرویس'],
            ['value' => 'token_rotated', 'label' => 'تعویض کلید وب‌سرویس'],
            ['value' => 'token_revoked', 'label' => 'ابطال کلید وب‌سرویس'],
            ['value' => 'message_deleted', 'label' => 'حذف پیام از سامانه'],
        ];

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $request->only(['search', 'action', 'user_id', 'date_from', 'date_to']),
            'stats' => $stats,
            'actionOptions' => $actionOptions,
        ]);
    }

    /**
     * Show raw audit log details as JSON.
     */
    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user');

        return response()->json([
            'id' => $auditLog->id,
            'action' => $auditLog->action,
            'action_label' => $auditLog->action_label,
            'user' => $auditLog->user ? [
                'id' => $auditLog->user->id,
                'name' => $auditLog->user->name,
                'username' => $auditLog->user->username,
            ] : null,
            'entity_type' => $auditLog->entity_type,
            'entity_id' => $auditLog->entity_id,
            'payload' => $auditLog->payload,
            'ip_address' => $auditLog->ip_address,
            'created_at' => $auditLog->created_at->toIso8601String(),
        ]);
    }

    /**
     * Convert full class names into clean Persian entity names.
     */
    protected function simplifyEntityType(?string $type): ?string
    {
        if (! $type) {
            return null;
        }

        return match ($type) {
            'App\Models\PhoneNumber' => 'خط سازمانی',
            'App\Models\User' => 'کاربر سامانه',
            'App\Models\NumberRequest' => 'درخواست سرشماره',
            'App\Models\ApiToken' => 'کلید وب‌سرویس',
            'App\Models\Message' => 'پیامک',
            default => class_basename($type),
        };
    }
}
