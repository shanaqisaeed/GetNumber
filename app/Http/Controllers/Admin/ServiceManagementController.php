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
use App\Models\TldPricing;
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
     * Display the Admin Service Management console:
     * Tab 1: خطوط (Phone Lines)
     * Tab 2: آیدی‌ها (Handles / IDs)
     * Tab 3: دامنه‌های Web3 (Web3 Domains & TLDs)
     */
    public function index(Request $request): Response
    {
        $this->serviceRegistry->ensureCatalogSeeded();

        // 1. Phone Lines Service Definition & Lines sample
        $phoneDef = ServiceDefinition::where('key', 'phone_line')->first();
        $sampleLines = PhoneNumber::with(['user', 'prefix'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'type_label' => $p->type->label(),
                'status_label' => $p->status->label(),
                'status_badge' => $p->status->badgeClasses(),
                'display_title' => $p->display_title ?: $p->effective_title,
                'user_name' => $p->user?->name,
                'edit_url' => route('admin.numbers.edit', $p->id),
            ]);

        // 2. Custom IDs Service Definition & Pricing
        $idDef = ServiceDefinition::where('key', 'custom_id')->first();
        $idPricing = [
            'regular' => 30.0000,
            'semi_rond' => 60.0000,
            'golden_rond' => 120.0000,
        ];
        if ($idDef && ! empty($idDef->pricing_config)) {
            $idPricing = array_merge($idPricing, $idDef->pricing_config);
        }

        // 3. Web3 Domains & TLDs
        $web3Def = ServiceDefinition::where('key', 'web3_email_package')->first();
        $tlds = TldPricing::orderBy('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'tld' => $t->tld,
            'base_price' => (string) $t->base_price,
            'multiplier_rond' => (string) $t->multiplier_rond,
            'multiplier_semi_rond' => (string) $t->multiplier_semi_rond,
            'is_active' => (bool) $t->is_active,
        ]);

        return Inertia::render('Admin/Services/Index', [
            'phone_lines' => [
                'definition_id' => $phoneDef?->id,
                'is_purchasable' => (bool) ($phoneDef?->is_purchasable ?? true),
                'is_active' => (bool) ($phoneDef?->is_active ?? true),
                'prefixes_url' => route('admin.prefixes.index'),
                'sample_lines' => $sampleLines,
                'total_lines' => PhoneNumber::count(),
            ],
            'custom_ids' => [
                'definition_id' => $idDef?->id,
                'is_purchasable' => (bool) ($idDef?->is_purchasable ?? true),
                'requires_approval' => (bool) ($idDef?->requires_approval_for_enterprise ?? false),
                'pricing' => $idPricing,
                'total_allocations' => ServiceResourceAllocation::where('service_key', 'custom_id')->count(),
            ],
            'web3_domains' => [
                'definition_id' => $web3Def?->id,
                'is_purchasable' => (bool) ($web3Def?->is_purchasable ?? true),
                'tlds' => $tlds,
            ],
        ]);
    }

    /**
     * Toggle purchasability of a service globally.
     */
    public function togglePurchasable(Request $request, ServiceDefinition $definition): RedirectResponse
    {
        $definition->update([
            'is_purchasable' => ! $definition->is_purchasable,
        ]);

        AuditLog::record('service_purchasability_toggled', $definition, $request->user(), [
            'service' => $definition->key,
            'is_purchasable' => $definition->is_purchasable,
        ]);

        $status = $definition->is_purchasable ? 'فعال' : 'غیرفعال';
        return back()->with('success', "امکان خرید سرویس {$definition->title} توسط کاربران {$status} گردید.");
    }

    /**
     * Update approval mode (Auto-approval vs Manual Approval) for IDs.
     */
    public function updateApprovalMode(Request $request, ServiceDefinition $definition): RedirectResponse
    {
        $validated = $request->validate([
            'requires_approval' => ['required', 'boolean'],
        ]);

        $definition->update([
            'requires_approval_for_enterprise' => $validated['requires_approval'],
        ]);

        AuditLog::record('service_approval_mode_updated', $definition, $request->user(), [
            'service' => $definition->key,
            'requires_approval' => $validated['requires_approval'],
        ]);

        $mode = $validated['requires_approval'] ? 'نیازمند تایید مدیر' : 'تایید خودکار آنی';
        return back()->with('success', "مکانیزم تایید سفارشات به «{$mode}» تغییر یافت.");
    }

    /**
     * Update pricing table for IDs.
     */
    public function updateIdPricing(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'regular' => ['required', 'numeric', 'min:0'],
            'semi_rond' => ['required', 'numeric', 'min:0'],
            'golden_rond' => ['required', 'numeric', 'min:0'],
        ]);

        $idDef = ServiceDefinition::where('key', 'custom_id')->firstOrFail();
        $pricingConfig = array_merge($idDef->pricing_config ?? [], [
            'regular' => (float) $validated['regular'],
            'semi_rond' => (float) $validated['semi_rond'],
            'golden_rond' => (float) $validated['golden_rond'],
        ]);

        $idDef->update(['pricing_config' => $pricingConfig]);

        AuditLog::record('id_pricing_updated', $idDef, $request->user(), $pricingConfig);

        return back()->with('success', 'جدول تعرفه و نرخ‌گذاری آیدی‌ها با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Store new Web3 TLD.
     */
    public function storeTld(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tld' => ['required', 'string', 'min:2', 'max:20', 'unique:tld_pricing,tld', 'regex:/^[a-z0-9]+$/i'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'multiplier_rond' => ['required', 'numeric', 'min:1'],
            'multiplier_semi_rond' => ['required', 'numeric', 'min:1'],
        ], [
            'tld.required' => 'وارد کردن پسوند الزامی است.',
            'tld.unique' => 'این پسوند قبلاً تعریف شده است.',
        ]);

        $cleanTld = strtolower(ltrim(trim($validated['tld']), '.'));

        TldPricing::create([
            'tld' => $cleanTld,
            'base_price' => $validated['base_price'],
            'multiplier_rond' => $validated['multiplier_rond'],
            'multiplier_semi_rond' => $validated['multiplier_semi_rond'],
            'is_active' => true,
        ]);

        return back()->with('success', "پسوند جدید .{$cleanTld} با موفقیت افزوده شد.");
    }

    /**
     * Update existing Web3 TLD pricing and multipliers.
     */
    public function updateTld(Request $request, TldPricing $tld): RedirectResponse
    {
        $validated = $request->validate([
            'base_price' => ['required', 'numeric', 'min:0'],
            'multiplier_rond' => ['required', 'numeric', 'min:1'],
            'multiplier_semi_rond' => ['required', 'numeric', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        $tld->update($validated);

        return back()->with('success', "تعرفه پسوند .{$tld->tld} با موفقیت به‌روزرسانی گردید.");
    }

    /**
     * Delete a TLD.
     */
    public function destroyTld(Request $request, TldPricing $tld): RedirectResponse
    {
        $tldName = $tld->tld;
        $tld->delete();

        return back()->with('success', "پسوند .{$tldName} حذف شد.");
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
     * Update a subscriber's service allocation.
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
