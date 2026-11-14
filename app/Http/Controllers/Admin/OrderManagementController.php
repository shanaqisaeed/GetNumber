<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Services\ServiceRegistryInterface;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServiceResourceAllocation;
use App\Models\User;
use App\Models\Web3Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrderManagementController extends Controller
{
    public function __construct(
        protected ServiceRegistryInterface $serviceRegistry
    ) {}

    /**
     * Display unified orders review console.
     */
    public function index(Request $request): Response
    {
        $query = ServiceOrder::with(['user.wallet', 'serviceDefinition', 'servicePlan']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('requested_identifier', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('account_id', 'like', "%{$search}%")
                         ->orWhere('id', is_numeric($search) ? (int) $search : -1);
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('service_key')) {
            $serviceKey = $request->input('service_key');
            $query->whereHas('serviceDefinition', fn ($dq) => $dq->where('key', $serviceKey));
        }

        $orders = $query->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'user' => [
                    'id' => $order->user->id,
                    'account_id' => $order->user->account_id,
                    'name' => $order->user->name,
                    'username' => $order->user->username,
                    'wallet_balance' => (string) ($order->user->wallet?->balance ?? '0.0000'),
                ],
                'service_key' => $order->serviceDefinition?->key,
                'service_title' => $order->serviceDefinition?->title ?: $order->serviceDefinition?->key,
                'requested_identifier' => $order->requested_identifier,
                'status' => $order->status,
                'status_label' => $this->getStatusLabel($order->status),
                'status_badge' => $this->getStatusBadge($order->status),
                'unit_price' => (string) $order->unit_price,
                'final_amount' => (string) $order->final_amount,
                'held_amount' => (string) $order->held_amount,
                'rejection_reason' => $order->rejection_reason,
                'admin_note' => $order->admin_note,
                'reviewed_at' => $order->reviewed_at?->format('Y/m/d H:i'),
                'created_at' => $order->created_at->format('Y/m/d H:i'),
                'created_at_human' => $order->created_at->diffForHumans(),
            ]);

        $pendingCount = ServiceOrder::whereIn('status', ['pending', 'held', 'reviewing'])->count();
        $services = ServiceDefinition::orderBy('sort_order')->get(['id', 'key', 'title']);

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders,
            'pending_count' => $pendingCount,
            'filters' => $request->only(['search', 'status', 'service_key']),
            'services' => $services,
            'statuses' => [
                ['value' => 'pending', 'label' => 'در انتظار بررسی'],
                ['value' => 'completed', 'label' => 'تایید و فعال‌شده'],
                ['value' => 'rejected', 'label' => 'رد شده'],
                ['value' => 'referred', 'label' => 'ارجاع جهت اصلاح'],
            ],
        ]);
    }

    /**
     * Approve order and provision resource.
     */
    public function approve(Request $request, ServiceOrder $order): RedirectResponse
    {
        $admin = $request->user();

        if (in_array($order->status, ['completed', 'approved'], true)) {
            return back()->with('error', 'این سفارش قبلاً تایید و نهایی شده است.');
        }

        DB::transaction(function () use ($order, $admin) {
            $user = $order->user;
            $serviceKey = $order->serviceDefinition?->key;

            if ($serviceKey === 'phone_line') {
                $cleanNumber = preg_replace('/\D/', '', $order->requested_identifier);
                PhoneNumber::updateOrCreate(
                    ['number' => $cleanNumber],
                    [
                        'user_id' => $user->id,
                        'status' => \App\Enums\PhoneNumberStatus::Allocated,
                        'display_title' => $order->requested_identifier,
                        'allocated_at' => now(),
                    ]
                );
            } elseif ($serviceKey === 'web3_domain') {
                $parts = explode('.', $order->requested_identifier, 2);
                $domainName = $parts[0];
                $tld = $parts[1] ?? 'web3';

                Web3Domain::updateOrCreate(
                    ['domain_name' => $domainName, 'tld' => $tld],
                    [
                        'user_id' => $user->id,
                        'tier_badge' => 'regular',
                        'status' => 'active',
                        'max_sub_emails' => 100,
                        'registered_at' => now(),
                    ]
                );
            }

            // Provision resource allocation record
            ServiceResourceAllocation::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'service_key' => $serviceKey,
                    'resource_identifier' => $order->requested_identifier,
                ],
                [
                    'service_definition_id' => $order->service_definition_id,
                    'service_order_id' => $order->id,
                    'account_tier' => $order->account_tier,
                    'status' => 'active',
                    'allocated_at' => now(),
                ]
            );

            $order->update([
                'status' => 'completed',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'fulfilled_at' => now(),
                'admin_note' => 'تایید و فعال‌سازی خودکار توسط مدیر سیستم.',
            ]);

            AuditLog::record('order_approved', $order, $admin, [
                'order_number' => $order->order_number,
                'identifier' => $order->requested_identifier,
                'user_id' => $user->id,
            ]);
        });

        return back()->with('success', "سفارش شماره {$order->order_number} با موفقیت تایید و سرویس فعال شد.");
    }

    /**
     * Reject order with reason.
     */
    public function reject(Request $request, ServiceOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'ثبت دلیل رد سفارش الزامی است.',
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($order, $admin, $validated) {
            // Refund wallet if held or charged
            if (bccomp((string) $order->held_amount, '0.0000', 4) > 0) {
                app(\App\Contracts\WalletServiceInterface::class)->refund(
                    user: $order->user,
                    amount: (string) $order->held_amount,
                    referenceId: 'REF-' . $order->order_number,
                    description: "بازگشت وجه سفارش رد شده {$order->order_number}"
                );
            }

            $order->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'],
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record('order_rejected', $order, $admin, [
                'order_number' => $order->order_number,
                'reason' => $validated['reason'],
            ]);
        });

        return back()->with('success', "سفارش شماره {$order->order_number} رد گردید و وجه به کیف‌پول کاربر بازگردانده شد.");
    }

    /**
     * Refer order back for modification.
     */
    public function refer(Request $request, ServiceOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ], [
            'note.required' => 'ثبت توضیحات ارجاع به کاربر الزامی است.',
        ]);

        $admin = $request->user();

        $order->update([
            'status' => 'referred',
            'admin_note' => $validated['note'],
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        AuditLog::record('order_referred', $order, $admin, [
            'order_number' => $order->order_number,
            'note' => $validated['note'],
        ]);

        return back()->with('success', "سفارش شماره {$order->order_number} با موفقیت جهت اصلاح به کاربر ارجاع گردید.");
    }

    protected function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending', 'held', 'reviewing' => 'در انتظار بررسی',
            'completed', 'approved' => 'فعال و واگذارشده',
            'rejected' => 'رد شده',
            'referred' => 'ارجاع جهت اصلاح',
            default => $status,
        };
    }

    protected function getStatusBadge(string $status): string
    {
        return match ($status) {
            'pending', 'held', 'reviewing' => 'bg-amber-500/15 text-amber-500 border border-amber-500/30',
            'completed', 'approved' => 'bg-emerald-500/15 text-emerald-500 border border-emerald-500/30',
            'rejected' => 'bg-rose-500/15 text-rose-500 border border-rose-500/30',
            'referred' => 'bg-blue-500/15 text-blue-500 border border-blue-500/30',
            default => 'bg-slate-500/15 text-slate-500 border border-slate-500/30',
        };
    }
}

