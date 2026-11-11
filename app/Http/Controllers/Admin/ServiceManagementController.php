<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Services\ServiceRegistryInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceManagementController extends Controller
{
    public function __construct(
        protected ServiceRegistryInterface $serviceRegistry,
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Display the Admin Multi-Service Catalog, Tariff Pricing & Subscriber Service Management console.
     */
    public function index(Request $request): Response
    {
        // Ensure all users with legacy phone numbers have synced allocations
        $definitions = ServiceDefinition::query()
            ->with(['plans' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(function (ServiceDefinition $def) {
                $defaultConfig = config("service_policies.services.{$def->key}", []);
                $pricingConfig = array_replace_recursive(
                    $defaultConfig['pricing_config'] ?? [],
                    $def->pricing_config ?? []
                );
                $validationPolicies = array_replace_recursive(
                    $defaultConfig['validation_policies'] ?? [],
                    $def->validation_policies ?? []
                );

                return [
                    'id' => $def->id,
                    'key' => $def->key,
                    'category' => $def->category,
                    'channel_type' => $def->channel_type,
                    'icon' => $def->icon,
                    'title' => $def->title,
                    'subtitle' => $def->subtitle,
                    'description' => $def->description,
                    'unit_label' => $def->unit_label,
                    'is_active' => (bool) $def->is_active,
                    'is_purchasable' => (bool) $def->is_purchasable,
                    'requires_approval_for_enterprise' => (bool) $def->requires_approval_for_enterprise,
                    'available_for_roles' => $def->available_for_roles ?? ['individual', 'enterprise'],
                    'pricing_config' => $pricingConfig,
                    'validation_policies' => $validationPolicies,
                    'allocations_count' => ServiceResourceAllocation::where('service_key', $def->key)->where('status', 'active')->count(),
                    'orders_count' => ServiceOrder::where('service_definition_id', $def->id)->count(),
                    'plans' => $def->plans->map(fn (ServicePlan $plan) => [
                        'id' => $plan->id,
                        'service_definition_id' => $plan->service_definition_id,
                        'code' => $plan->code,
                        'title' => $plan->title,
                        'description' => $plan->description,
                        'target_tier' => $plan->target_tier,
                        'billing_mode' => $plan->billing_mode,
                        'base_price' => (string) $plan->base_price,
                        'quota_units' => (int) $plan->quota_units,
                        'is_active' => (bool) $plan->is_active,
                        'metadata' => $plan->metadata ?? [],
                    ])->values()->all(),
                ];
            });

        // Subscriber Allocations query with filters
        $allocQuery = ServiceResourceAllocation::query()
            ->with(['user:id,name,username,account_tier,organization_name', 'serviceDefinition', 'subResources']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $allocQuery->where(function ($q) use ($search) {
                $q->where('resource_identifier', 'like', "%{$search}%")
                    ->orWhere('display_title', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%")
                            ->orWhere('organization_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('service_key')) {
            $allocQuery->where('service_key', $request->input('service_key'));
        }

        if ($request->filled('status')) {
            $allocQuery->where('status', $request->input('status'));
        }

        if ($request->filled('user_id')) {
            $allocQuery->where('user_id', (int) $request->input('user_id'));
        }

        $allocations = $allocQuery->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ServiceResourceAllocation $alloc) => [
                'id' => $alloc->id,
                'uuid' => $alloc->uuid,
                'user_id' => $alloc->user_id,
                'user' => $alloc->user ? [
                    'id' => $alloc->user->id,
                    'name' => $alloc->user->name,
                    'username' => $alloc->user->username,
                    'account_tier' => $alloc->user->effectiveServiceTier(),
                    'organization_name' => $alloc->user->organization_name,
                ] : null,
                'service_key' => $alloc->service_key,
                'service_title' => $alloc->serviceDefinition?->title ?? $alloc->service_key,
                'channel_type' => $alloc->channel_type,
                'resource_identifier' => $alloc->resource_identifier,
                'display_title' => $alloc->display_title,
                'account_tier' => $alloc->account_tier,
                'tier_badge' => $alloc->tier_badge,
                'tier_label' => __('catalog.tiers_badges.' . $alloc->tier_badge),
                'status' => $alloc->status,
                'total_quota' => (int) $alloc->total_quota,
                'used_quota' => (int) $alloc->used_quota,
                'remaining_quota' => $alloc->remainingQuota(),
                'sub_resources' => $alloc->subResources->map(fn ($sub) => [
                    'id' => $sub->id,
                    'sub_identifier' => $sub->sub_identifier,
                    'full_address' => $sub->full_address,
                    'display_name' => $sub->display_name,
                    'status' => $sub->status,
                ])->values()->all(),
                'allocated_at' => $alloc->allocated_at?->format('Y/m/d H:i') ?? $alloc->created_at?->format('Y/m/d H:i'),
            ]);

        // Recent Service Orders
        $orders = ServiceOrder::query()
            ->with(['user:id,name,username,account_tier,organization_name', 'serviceDefinition', 'servicePlan'])
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'user' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'username' => $order->user->username,
                    'account_tier' => $order->user->effectiveServiceTier(),
                    'organization_name' => $order->user->organization_name,
                ] : null,
                'service_key' => $order->serviceDefinition?->key,
                'service_title' => $order->serviceDefinition?->title,
                'plan_title' => $order->servicePlan?->title,
                'requested_identifier' => $order->requested_identifier,
                'account_tier' => $order->account_tier,
                'fulfillment_mode' => $order->fulfillment_mode,
                'status' => $order->status,
                'unit_price' => (string) $order->unit_price,
                'discount_amount' => (string) $order->discount_amount,
                'final_amount' => (string) $order->final_amount,
                'created_at' => $order->created_at?->format('Y/m/d H:i'),
            ]);

        $usersList = User::query()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'username', 'account_tier', 'organization_name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'account_tier' => $u->effectiveServiceTier(),
                'organization_name' => $u->organization_name,
            ]);

        $stats = [
            'total_services' => ServiceDefinition::count(),
            'active_services' => ServiceDefinition::where('is_active', true)->count(),
            'active_allocations' => ServiceResourceAllocation::where('status', 'active')->count(),
            'phone_allocations' => ServiceResourceAllocation::where('service_key', 'phone_line')->where('status', 'active')->count(),
            'custom_id_allocations' => ServiceResourceAllocation::where('service_key', 'custom_id')->where('status', 'active')->count(),
            'web3_allocations' => ServiceResourceAllocation::where('service_key', 'web3_email_package')->where('status', 'active')->count(),
            'total_orders' => ServiceOrder::count(),
            'pending_orders' => ServiceOrder::where('status', 'pending_approval')->count(),
        ];

        return Inertia::render('Admin/Services/Index', [
            'definitions' => $definitions,
            'allocations' => $allocations,
            'orders' => $orders,
            'users_list' => $usersList,
            'stats' => $stats,
            'filters' => $request->only(['search', 'service_key', 'status', 'user_id', 'tab']),
        ]);
    }

    /**
     * Update a ServiceDefinition's flags, tariff pricing_config, and validation_policies.
     */
    public function updateDefinition(Request $request, ServiceDefinition $definition): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'is_purchasable' => ['required', 'boolean'],
            'requires_approval_for_enterprise' => ['required', 'boolean'],
            'available_for_roles' => ['required', 'array', 'min:1'],
            'available_for_roles.*' => ['string', 'in:individual,enterprise'],
            'pricing_config' => ['nullable', 'array'],
            'validation_policies' => ['nullable', 'array'],
        ]);

        $currentPricing = $definition->pricing_config ?? [];
        $newPricing = isset($validated['pricing_config'])
            ? array_replace_recursive($currentPricing, $validated['pricing_config'])
            : $currentPricing;

        $currentPolicies = $definition->validation_policies ?? [];
        $newPolicies = isset($validated['validation_policies'])
            ? array_replace_recursive($currentPolicies, $validated['validation_policies'])
            : $currentPolicies;

        $definition->update([
            'is_active' => (bool) $validated['is_active'],
            'is_purchasable' => (bool) $validated['is_purchasable'],
            'requires_approval_for_enterprise' => (bool) $validated['requires_approval_for_enterprise'],
            'available_for_roles' => array_values(array_unique($validated['available_for_roles'])),
            'pricing_config' => $newPricing,
            'validation_policies' => $newPolicies,
        ]);

        // If phone_line shortcode_length_prices were updated, synchronize SystemSetting org tariffs
        if ($definition->key === 'phone_line' && ! empty($newPricing['shortcode_length_prices'])) {
            foreach ([4, 5, 6, 7, 8] as $len) {
                if (isset($newPricing['shortcode_length_prices'][$len])) {
                    $regularPrice = (string) $newPricing['shortcode_length_prices'][$len];
                    SystemSetting::set("org_number_price_{$len}_regular", $regularPrice);
                }
            }
        }

        AuditLog::record(
            action: 'admin.service_definition.updated',
            entity: $definition,
            actor: $request->user(),
            payload: [
                'service_key' => $definition->key,
                'is_active' => $definition->is_active,
                'is_purchasable' => $definition->is_purchasable,
                'pricing_config' => $newPricing,
            ]
        );

        return back()->with('success', __('admin.services.toast_definition_updated', ['service' => $definition->title]));
    }

    /**
     * Store a new ServicePlan (tariff & quota package).
     */
    public function storePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'service_definition_id' => ['required', 'integer', 'exists:service_definitions,id'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:service_plans,code'],
            'target_tier' => ['required', 'string', 'in:individual,enterprise,all'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'quota_units' => ['required', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['boolean'],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = ServicePlan::create([
            'service_definition_id' => (int) $validated['service_definition_id'],
            'code' => strtolower(trim($validated['code'])),
            'target_tier' => $validated['target_tier'],
            'billing_mode' => 'one_time',
            'base_price' => number_format((float) $validated['base_price'], 4, '.', ''),
            'quota_units' => (int) $validated['quota_units'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'metadata' => [
                'custom_title' => $validated['title'] ?? null,
                'custom_description' => $validated['description'] ?? null,
            ],
            'sort_order' => 50,
        ]);

        AuditLog::record(
            action: 'admin.service_plan.created',
            entity: $plan,
            actor: $request->user(),
            payload: [
                'code' => $plan->code,
                'base_price' => $plan->base_price,
                'quota_units' => $plan->quota_units,
            ]
        );

        return back()->with('success', __('admin.services.toast_plan_created'));
    }

    /**
     * Update an existing ServicePlan's tariff price, quota units, tier, or active status.
     */
    public function updatePlan(Request $request, ServicePlan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'target_tier' => ['required', 'string', 'in:individual,enterprise,all'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'quota_units' => ['required', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $plan->update([
            'target_tier' => $validated['target_tier'],
            'base_price' => number_format((float) $validated['base_price'], 4, '.', ''),
            'quota_units' => (int) $validated['quota_units'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        AuditLog::record(
            action: 'admin.service_plan.updated',
            entity: $plan,
            actor: $request->user(),
            payload: [
                'code' => $plan->code,
                'base_price' => $plan->base_price,
                'quota_units' => $plan->quota_units,
                'is_active' => $plan->is_active,
            ]
        );

        return back()->with('success', __('admin.services.toast_plan_updated'));
    }

    /**
     * Delete a custom ServicePlan.
     */
    public function destroyPlan(Request $request, ServicePlan $plan): RedirectResponse
    {
        AuditLog::record(
            action: 'admin.service_plan.deleted',
            entity: $plan,
            actor: $request->user(),
            payload: ['code' => $plan->code]
        );

        $plan->delete();

        return back()->with('success', __('admin.services.toast_plan_deleted'));
    }

    /**
     * Directly allocate / provision a service resource to a subscriber (User) from Admin Panel.
     */
    public function allocateToUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'service_key' => ['required', 'string', 'in:phone_line,custom_id,web3_email_package'],
            'resource_identifier' => ['required', 'string', 'max:120'],
            'display_title' => ['nullable', 'string', 'max:100'],
            'account_tier' => ['nullable', 'string', 'in:individual,enterprise'],
            'total_quota' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        /** @var User $targetUser */
        $targetUser = User::findOrFail((int) $validated['user_id']);
        /** @var ServiceDefinition $definition */
        $definition = ServiceDefinition::where('key', $validated['service_key'])->firstOrFail();

        $rawId = trim($validated['resource_identifier']);
        $serviceKey = $definition->key;

        if ($serviceKey === 'phone_line') {
            $normalizedId = preg_replace('/\D+/', '', $rawId);
        } elseif ($serviceKey === 'custom_id') {
            $cleanHandle = strtolower(ltrim($rawId, '@'));
            $normalizedId = '@' . $cleanHandle;
        } else {
            $cleanDomain = strtolower($rawId);
            if (! str_contains($cleanDomain, '.')) {
                $cleanDomain .= '.web3';
            }
            $normalizedId = $cleanDomain;
        }

        // Check duplicate active allocation
        $exists = ServiceResourceAllocation::query()
            ->where('service_key', $serviceKey)
            ->where('resource_identifier', $normalizedId)
            ->where('status', 'active')
            ->exists();

        if ($exists) {
            return back()->with('error', __('catalog.validation.already_allocated', ['identifier' => $normalizedId]));
        }

        $tier = $validated['account_tier'] ?? $targetUser->effectiveServiceTier();
        $totalQuota = (int) ($validated['total_quota'] ?? ($serviceKey === 'web3_email_package' ? 10 : 1));
        $displayTitle = trim((string) ($validated['display_title'] ?? '')) ?: $definition->title;

        DB::transaction(function () use ($targetUser, $definition, $serviceKey, $normalizedId, $displayTitle, $tier, $totalQuota, $request) {
            $phoneNumberId = null;

            if ($serviceKey === 'phone_line') {
                $phoneType = strlen($normalizedId) === 9
                    ? PhoneNumberType::UserStandard
                    : PhoneNumberType::UserService;

                $phone = PhoneNumber::updateOrCreate(
                    ['number' => $normalizedId],
                    [
                        'phone_prefix_id' => PhonePrefix::getDefaultPrefix()?->id,
                        'type' => $phoneType,
                        'rond_tier' => 'regular',
                        'status' => PhoneNumberStatus::Allocated,
                        'display_title' => $displayTitle,
                        'accepts_incoming_sms' => true,
                        'user_id' => $targetUser->id,
                        'allocated_at' => now(),
                        'released_at' => null,
                    ]
                );
                $phoneNumberId = $phone->id;
            }

            $allocation = ServiceResourceAllocation::create([
                'user_id' => $targetUser->id,
                'service_definition_id' => $definition->id,
                'service_key' => $serviceKey,
                'channel_type' => $definition->channel_type,
                'resource_identifier' => $normalizedId,
                'display_title' => $displayTitle,
                'account_tier' => $tier,
                'tier_badge' => $tier === 'enterprise' ? 'vip_short' : 'standard',
                'status' => 'active',
                'total_quota' => $totalQuota,
                'used_quota' => $serviceKey === 'web3_email_package' ? 0 : 1,
                'phone_number_id' => $phoneNumberId,
                'capabilities' => [
                    'inbound_sms' => true,
                    'outbound_sms' => true,
                    'webhook_routing' => true,
                ],
                'metadata' => [
                    'allocated_by_admin_id' => $request->user()->id,
                ],
                'allocated_at' => now(),
            ]);

            AuditLog::record(
                action: 'admin.service_allocation.created',
                entity: $allocation,
                actor: $request->user(),
                payload: [
                    'target_user_id' => $targetUser->id,
                    'service_key' => $serviceKey,
                    'resource_identifier' => $normalizedId,
                    'total_quota' => $totalQuota,
                ]
            );
        });

        return back()->with('success', __('admin.services.toast_allocation_created', [
            'identifier' => $normalizedId,
            'user' => $targetUser->name,
        ]));
    }

    /**
     * Update a subscriber's service allocation (status, total_quota, display_title, account_tier).
     */
    public function updateAllocation(Request $request, ServiceResourceAllocation $allocation): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended,released'],
            'total_quota' => ['required', 'integer', 'min:1', 'max:100000'],
            'display_title' => ['nullable', 'string', 'max:100'],
            'account_tier' => ['nullable', 'string', 'in:individual,enterprise'],
        ]);

        $newStatus = $validated['status'];

        $allocation->update([
            'status' => $newStatus,
            'total_quota' => max((int) $allocation->used_quota, (int) $validated['total_quota']),
            'display_title' => ! empty($validated['display_title']) ? trim($validated['display_title']) : $allocation->display_title,
            'account_tier' => $validated['account_tier'] ?? $allocation->account_tier,
            'released_at' => $newStatus === 'released' ? now() : null,
        ]);

        if ($allocation->phone_number_id) {
            $phoneStatus = match ($newStatus) {
                'active' => PhoneNumberStatus::Allocated,
                'suspended' => PhoneNumberStatus::Suspended,
                default => PhoneNumberStatus::Available,
            };

            PhoneNumber::where('id', $allocation->phone_number_id)->update([
                'status' => $phoneStatus,
                'user_id' => $newStatus === 'released' ? null : $allocation->user_id,
                'display_title' => $allocation->display_title,
            ]);
        }

        AuditLog::record(
            action: 'admin.service_allocation.updated',
            entity: $allocation,
            actor: $request->user(),
            payload: [
                'status' => $allocation->status,
                'total_quota' => $allocation->total_quota,
            ]
        );

        return back()->with('success', __('admin.services.toast_allocation_updated'));
    }

    /**
     * Delete/release a subscriber's service allocation.
     */
    public function destroyAllocation(Request $request, ServiceResourceAllocation $allocation): RedirectResponse
    {
        if ($allocation->phone_number_id) {
            PhoneNumber::where('id', $allocation->phone_number_id)->update([
                'status' => PhoneNumberStatus::Available,
                'user_id' => null,
                'released_at' => now(),
            ]);
        }

        $allocation->update([
            'status' => 'released',
            'released_at' => now(),
        ]);

        AuditLog::record(
            action: 'admin.service_allocation.released',
            entity: $allocation,
            actor: $request->user(),
            payload: ['resource_identifier' => $allocation->resource_identifier]
        );

        return back()->with('success', __('admin.services.toast_allocation_released'));
    }
}

