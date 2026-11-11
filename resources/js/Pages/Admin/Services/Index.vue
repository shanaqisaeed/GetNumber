<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface PlanItem {
    id: number;
    service_definition_id: number;
    code: string;
    title: string;
    description: string;
    target_tier: string;
    billing_mode: string;
    base_price: string;
    quota_units: number;
    is_active: boolean;
}

interface DefinitionItem {
    id: number;
    key: string;
    category: string;
    channel_type: string;
    icon: string;
    title: string;
    subtitle: string;
    description: string;
    unit_label: string;
    is_active: boolean;
    is_purchasable: boolean;
    requires_approval_for_enterprise: boolean;
    available_for_roles: string[];
    pricing_config: Record<string, any>;
    validation_policies: Record<string, any>;
    allocations_count: number;
    orders_count: number;
    plans: PlanItem[];
}

interface AllocationItem {
    id: number;
    uuid: string;
    user_id: number;
    user: {
        id: number;
        name: string;
        username: string;
        account_tier: string;
        organization_name?: string | null;
    } | null;
    service_key: string;
    service_title: string;
    channel_type: string;
    resource_identifier: string;
    display_title: string;
    account_tier: string;
    tier_badge: string;
    tier_label: string;
    status: string;
    total_quota: number;
    used_quota: number;
    remaining_quota: number;
    sub_resources: Array<{
        id: number;
        sub_identifier: string;
        full_address: string;
        display_name: string | null;
        status: string;
    }>;
    allocated_at: string;
}

interface OrderItem {
    id: number;
    order_number: string;
    user: {
        id: number;
        name: string;
        username: string;
        account_tier: string;
        organization_name?: string | null;
    } | null;
    service_key: string;
    service_title: string;
    plan_title: string | null;
    requested_identifier: string;
    account_tier: string;
    fulfillment_mode: string;
    status: string;
    unit_price: string;
    discount_amount: string;
    final_amount: string;
    created_at: string;
}

const props = defineProps<{
    definitions: DefinitionItem[];
    allocations: {
        data: AllocationItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
        current_page: number;
        last_page: number;
    };
    orders: OrderItem[];
    users_list: Array<{
        id: number;
        name: string;
        username: string;
        account_tier: string;
        organization_name?: string | null;
    }>;
    stats: {
        total_services: number;
        active_services: number;
        active_allocations: number;
        phone_allocations: number;
        custom_id_allocations: number;
        web3_allocations: number;
        total_orders: number;
        pending_orders: number;
    };
    filters: {
        search?: string;
        service_key?: string;
        status?: string;
        user_id?: string | number;
        tab?: string;
    };
}>();

const { __ } = useTrans();

const activeTab = ref<'catalog' | 'plans' | 'allocations' | 'orders'>(
    (props.filters.tab as any) || (props.filters.user_id ? 'allocations' : 'catalog')
);

// Filters for Subscriber Allocations
const searchFilter = ref(props.filters.search || '');
const serviceFilter = ref(props.filters.service_key || '');
const statusFilter = ref(props.filters.status || '');
const userFilter = ref(props.filters.user_id ? String(props.filters.user_id) : '');

function applyAllocationFilters() {
    router.get(
        route('admin.services.index'),
        {
            tab: 'allocations',
            search: searchFilter.value || undefined,
            service_key: serviceFilter.value || undefined,
            status: statusFilter.value || undefined,
            user_id: userFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearAllocationFilters() {
    searchFilter.value = '';
    serviceFilter.value = '';
    statusFilter.value = '';
    userFilter.value = '';
    applyAllocationFilters();
}

// 1. Edit Service Definition & Tariffs Modal
const isEditDefModalOpen = ref(false);
const editingDef = ref<DefinitionItem | null>(null);
const defForm = useForm({
    is_active: true,
    is_purchasable: true,
    requires_approval_for_enterprise: false,
    available_for_roles: ['individual', 'enterprise'] as string[],
    pricing_config: {} as Record<string, any>,
    validation_policies: {} as Record<string, any>,
});

function openEditDefModal(def: DefinitionItem) {
    editingDef.value = def;
    defForm.is_active = def.is_active;
    defForm.is_purchasable = def.is_purchasable;
    defForm.requires_approval_for_enterprise = def.requires_approval_for_enterprise;
    defForm.available_for_roles = [...(def.available_for_roles || ['individual', 'enterprise'])];
    defForm.pricing_config = JSON.parse(JSON.stringify(def.pricing_config || {}));
    if (def.key === 'phone_line' && !defForm.pricing_config.shortcode_length_prices) {
        defForm.pricing_config.shortcode_length_prices = { 4: '500.0000', 5: '300.0000', 6: '150.0000', 7: '90.0000', 8: '60.0000' };
    }
    defForm.validation_policies = JSON.parse(JSON.stringify(def.validation_policies || {}));
    if (!defForm.validation_policies.individual) {
        defForm.validation_policies.individual = { min_length: 6, max_length: 24, max_active_resources: 5, discount_percent: 0 };
    }
    if (!defForm.validation_policies.enterprise) {
        defForm.validation_policies.enterprise = { min_length: 4, max_length: 32, max_active_resources: 50, discount_percent: 15 };
    }
    isEditDefModalOpen.value = true;
}

function submitEditDef() {
    if (!editingDef.value) return;
    defForm.patch(route('admin.services.definitions.update', editingDef.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditDefModalOpen.value = false;
        },
    });
}

// Quick toggle Service active or purchasable
function quickToggleDef(def: DefinitionItem, field: 'is_active' | 'is_purchasable') {
    router.patch(
        route('admin.services.definitions.update', def.id),
        {
            is_active: field === 'is_active' ? !def.is_active : def.is_active,
            is_purchasable: field === 'is_purchasable' ? !def.is_purchasable : def.is_purchasable,
            requires_approval_for_enterprise: def.requires_approval_for_enterprise,
            available_for_roles: def.available_for_roles,
            pricing_config: def.pricing_config,
            validation_policies: def.validation_policies,
        },
        { preserveScroll: true }
    );
}

// 2. Create / Edit Plan Modal
const isPlanModalOpen = ref(false);
const editingPlan = ref<PlanItem | null>(null);
const planForm = useForm({
    service_definition_id: props.definitions[0]?.id || 1,
    code: '',
    title: '',
    description: '',
    target_tier: 'individual',
    base_price: '25.0000',
    quota_units: 10,
    is_active: true,
});

function openCreatePlanModal(defId?: number) {
    editingPlan.value = null;
    planForm.reset();
    planForm.service_definition_id = defId || props.definitions[0]?.id || 1;
    planForm.target_tier = 'individual';
    planForm.base_price = '25.0000';
    planForm.quota_units = 10;
    planForm.is_active = true;
    isPlanModalOpen.value = true;
}

function openEditPlanModal(plan: PlanItem) {
    editingPlan.value = plan;
    planForm.service_definition_id = plan.service_definition_id;
    planForm.code = plan.code;
    planForm.title = plan.title;
    planForm.description = plan.description;
    planForm.target_tier = plan.target_tier;
    planForm.base_price = String(plan.base_price);
    planForm.quota_units = plan.quota_units;
    planForm.is_active = plan.is_active;
    isPlanModalOpen.value = true;
}

function submitPlanForm() {
    if (editingPlan.value) {
        planForm.patch(route('admin.services.plans.update', editingPlan.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                isPlanModalOpen.value = false;
            },
        });
    } else {
        planForm.post(route('admin.services.plans.store'), {
            preserveScroll: true,
            onSuccess: () => {
                isPlanModalOpen.value = false;
            },
        });
    }
}

function deletePlan(plan: PlanItem) {
    if (!confirm(__('admin.services.confirm_delete_plan', { code: plan.code }))) return;
    router.delete(route('admin.services.plans.destroy', plan.id), { preserveScroll: true });
}

// 3. Provision Service to Subscriber Modal
const isProvisionModalOpen = ref(false);
const provisionForm = useForm({
    user_id: props.filters.user_id ? Number(props.filters.user_id) : (props.users_list[0]?.id || 1),
    service_key: 'custom_id',
    resource_identifier: '',
    display_title: '',
    account_tier: 'individual',
    total_quota: 10,
});

function openProvisionModal(userId?: number, serviceKey: string = 'custom_id') {
    provisionForm.reset();
    provisionForm.user_id = userId || (props.filters.user_id ? Number(props.filters.user_id) : (props.users_list[0]?.id || 1));
    provisionForm.service_key = serviceKey;
    provisionForm.total_quota = serviceKey === 'web3_email_package' ? 25 : 1;
    const foundUser = props.users_list.find((u) => u.id === provisionForm.user_id);
    provisionForm.account_tier = foundUser?.account_tier || 'individual';
    isProvisionModalOpen.value = true;
}

function submitProvision() {
    provisionForm.post(route('admin.services.allocations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isProvisionModalOpen.value = false;
            activeTab.value = 'allocations';
        },
    });
}

// 4. Edit Subscriber Allocation Modal
const isEditAllocModalOpen = ref(false);
const editingAlloc = ref<AllocationItem | null>(null);
const allocForm = useForm({
    status: 'active',
    total_quota: 1,
    display_title: '',
    account_tier: 'individual',
});

function openEditAllocModal(alloc: AllocationItem) {
    editingAlloc.value = alloc;
    allocForm.status = alloc.status;
    allocForm.total_quota = alloc.total_quota;
    allocForm.display_title = alloc.display_title;
    allocForm.account_tier = alloc.account_tier;
    isEditAllocModalOpen.value = true;
}

function submitEditAlloc() {
    if (!editingAlloc.value) return;
    allocForm.patch(route('admin.services.allocations.update', editingAlloc.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditAllocModalOpen.value = false;
        },
    });
}

function releaseAllocation(alloc: AllocationItem) {
    if (!confirm(__('admin.services.confirm_release_allocation', { identifier: alloc.resource_identifier }))) return;
    router.delete(route('admin.services.allocations.destroy', alloc.id), { preserveScroll: true });
}

const allPlans = computed(() => {
    const list: Array<PlanItem & { service_title: string; service_key: string }> = [];
    for (const def of props.definitions) {
        for (const p of def.plans) {
            list.push({
                ...p,
                service_title: def.title,
                service_key: def.key,
            });
        }
    }
    return list;
});
</script>

<template>
    <Head :title="__('admin.services.head_title')" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header Card -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                        <span>{{ __('admin.services.page_title') }}</span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('admin.services.page_subtitle') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                    <button
                        type="button"
                        @click="openCreatePlanModal()"
                        class="px-4 py-2.5 rounded-2xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-bold transition-all cursor-pointer"
                    >
                        + {{ __('admin.services.add_plan_btn') }}
                    </button>
                    <button
                        type="button"
                        @click="openProvisionModal()"
                        class="px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all cursor-pointer"
                    >
                        + {{ __('admin.services.provision_to_user_btn') }}
                    </button>
                </div>
            </div>

            <!-- KPI Metrics -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.services.stat_active_services') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-indigo-600 dark:text-indigo-400">{{ stats.active_services }} / {{ stats.total_services }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-black">
                        ⚡
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.services.stat_phone_lines') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-emerald-600 dark:text-emerald-400">{{ stats.phone_allocations }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-black">
                        📱
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.services.stat_custom_ids') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-purple-600 dark:text-purple-400">{{ stats.custom_id_allocations }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center font-black">
                        @
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.services.stat_web3_packages') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-amber-600 dark:text-amber-400">{{ stats.web3_allocations }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center font-black">
                        🌐
                    </div>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="flex flex-wrap items-center gap-2 bg-white dark:bg-slate-900 p-2 rounded-2xl border border-slate-200/80 dark:border-slate-800/80">
                <button
                    type="button"
                    @click="activeTab = 'catalog'"
                    class="px-4 py-2.5 rounded-xl text-xs font-black transition-all cursor-pointer"
                    :class="activeTab === 'catalog' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                >
                    {{ __('admin.services.tab_catalog_tariffs') }}
                </button>
                <button
                    type="button"
                    @click="activeTab = 'plans'"
                    class="px-4 py-2.5 rounded-xl text-xs font-black transition-all cursor-pointer"
                    :class="activeTab === 'plans' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                >
                    {{ __('admin.services.tab_plans') }} ({{ allPlans.length }})
                </button>
                <button
                    type="button"
                    @click="activeTab = 'allocations'"
                    class="px-4 py-2.5 rounded-xl text-xs font-black transition-all cursor-pointer"
                    :class="activeTab === 'allocations' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                >
                    {{ __('admin.services.tab_subscriber_allocations') }} ({{ allocations.total }})
                </button>
                <button
                    type="button"
                    @click="activeTab = 'orders'"
                    class="px-4 py-2.5 rounded-xl text-xs font-black transition-all cursor-pointer"
                    :class="activeTab === 'orders' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                >
                    {{ __('admin.services.tab_orders') }} ({{ orders.length }})
                </button>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 1: SERVICE CATALOG, TARIFF PRICING & POLICIES                          -->
            <!-- ========================================================================= -->
            <div v-if="activeTab === 'catalog'" class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div
                    v-for="def in definitions"
                    :key="def.id"
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm flex flex-col justify-between space-y-5"
                >
                    <div class="space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <span class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 uppercase font-bold block">{{ def.key }} • {{ def.channel_type }}</span>
                                <h3 class="text-base font-black text-slate-900 dark:text-white mt-0.5">{{ def.title }}</h3>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button
                                    type="button"
                                    @click="quickToggleDef(def, 'is_active')"
                                    class="px-2.5 py-1 rounded-full text-[10px] font-bold border transition-all cursor-pointer"
                                    :class="def.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800' : 'bg-rose-50 text-rose-700 border-rose-200'"
                                >
                                    {{ def.is_active ? __('catalog.flags.active') : __('catalog.flags.inactive') }}
                                </button>
                                <button
                                    type="button"
                                    @click="quickToggleDef(def, 'is_purchasable')"
                                    class="px-2.5 py-1 rounded-full text-[10px] font-bold border transition-all cursor-pointer"
                                    :class="def.is_purchasable ? 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800' : 'bg-amber-50 text-amber-700 border-amber-200'"
                                >
                                    {{ def.is_purchasable ? __('catalog.flags.purchasable') : __('catalog.flags.not_purchasable') }}
                                </button>
                            </div>
                        </div>

                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                            {{ def.description }}
                        </p>

                        <!-- Current Tariffs Box -->
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800 space-y-2 text-xs">
                            <div class="font-black text-slate-800 dark:text-slate-200 flex items-center justify-between">
                                <span>{{ __('admin.services.current_tariffs_heading') }}</span>
                                <span class="text-[10px] font-mono text-amber-600 dark:text-amber-400">TRX / COIN</span>
                            </div>

                            <template v-if="def.key === 'phone_line'">
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_standard_9d') }}</span>
                                    <span class="font-mono font-bold">{{ def.pricing_config?.standard_9digit_base ?? '25.0000' }}</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_semi_rond') }}</span>
                                    <span class="font-mono font-bold text-blue-600">{{ def.pricing_config?.semi_rond_base ?? '50.0000' }}</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_golden_rond') }}</span>
                                    <span class="font-mono font-bold text-amber-600">{{ def.pricing_config?.golden_rond_base ?? '100.0000' }}</span>
                                </div>
                                <div class="pt-1 border-t border-slate-200/60 dark:border-slate-700/60 flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_shortcode_4_to_8') }}</span>
                                    <span class="font-mono font-bold text-purple-600" dir="ltr">
                                        {{ def.pricing_config?.shortcode_length_prices?.[4] ?? '500' }} ~ {{ def.pricing_config?.shortcode_length_prices?.[8] ?? '60' }}
                                    </span>
                                </div>
                            </template>

                            <template v-else-if="def.key === 'custom_id'">
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_custom_id_standard') }}</span>
                                    <span class="font-mono font-bold">{{ def.pricing_config?.standard_base_price ?? '15.0000' }}</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_custom_id_semi') }}</span>
                                    <span class="font-mono font-bold text-blue-600">{{ def.pricing_config?.semi_rond_price ?? '35.0000' }}</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_custom_id_golden') }}</span>
                                    <span class="font-mono font-bold text-amber-600">{{ def.pricing_config?.golden_rond_price ?? '75.0000' }}</span>
                                </div>
                                <div class="pt-1 border-t border-slate-200/60 dark:border-slate-700/60 flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_custom_id_short_4_5') }}</span>
                                    <span class="font-mono font-bold text-purple-600" dir="ltr">
                                        {{ def.pricing_config?.enterprise_short_4_price ?? '180.0000' }} / {{ def.pricing_config?.enterprise_short_5_price ?? '110.0000' }}
                                    </span>
                                </div>
                            </template>

                            <template v-else>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_web3_short_domain') }}</span>
                                    <span class="font-mono font-bold text-purple-600">+{{ def.pricing_config?.short_domain_surcharge_3_to_5 ?? '90.0000' }}</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_web3_premium_tld') }}</span>
                                    <span class="font-mono font-bold text-amber-600">+{{ def.pricing_config?.premium_tld_surcharge ?? '45.0000' }}</span>
                                </div>
                                <div class="flex justify-between text-[11px]">
                                    <span class="text-slate-500">{{ __('admin.services.rate_web3_extra_quota') }}</span>
                                    <span class="font-mono font-bold text-emerald-600">{{ def.pricing_config?.extra_quota_unit_price ?? '2.5000' }}</span>
                                </div>
                            </template>
                        </div>

                        <!-- Validation Policy Summary -->
                        <div class="grid grid-cols-2 gap-2 text-[11px]">
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800">
                                <span class="font-bold text-slate-700 dark:text-slate-300 block">{{ __('catalog.tiers.individual') }}</span>
                                <span class="text-slate-400 block mt-0.5">
                                    {{ __('admin.services.min_len_label') }}: {{ def.validation_policies?.individual?.min_length ?? 6 }} |
                                    {{ __('admin.services.max_res_label') }}: {{ def.validation_policies?.individual?.max_active_resources ?? 5 }}
                                </span>
                            </div>
                            <div class="p-2.5 rounded-xl bg-purple-50/50 dark:bg-purple-950/20 border border-purple-200/60 dark:border-purple-800/40">
                                <span class="font-bold text-purple-700 dark:text-purple-300 block">{{ __('catalog.tiers.enterprise') }}</span>
                                <span class="text-purple-600/80 dark:text-purple-400 block mt-0.5">
                                    {{ __('admin.services.min_len_label') }}: {{ def.validation_policies?.enterprise?.min_length ?? 4 }} |
                                    {{ __('admin.services.discount_label') }}: {{ def.validation_policies?.enterprise?.discount_percent ?? 15 }}%
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                        <span class="text-[11px] font-bold text-slate-400">
                            {{ def.allocations_count }} {{ __('admin.services.active_subscribers_unit') }}
                        </span>
                        <button
                            type="button"
                            @click="openEditDefModal(def)"
                            class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-colors cursor-pointer"
                        >
                            {{ __('admin.services.configure_tariffs_btn') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 2: SERVICE PLANS & QUOTA PACKAGES                                     -->
            <!-- ========================================================================= -->
            <div v-if="activeTab === 'plans'" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 overflow-hidden shadow-sm">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.services.plans_table_title') }}</h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('admin.services.plans_table_subtitle') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="openCreatePlanModal()"
                        class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold cursor-pointer"
                    >
                        + {{ __('admin.services.add_plan_btn') }}
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 font-bold border-b border-slate-100 dark:border-slate-800">
                            <tr>
                                <th class="p-3.5">{{ __('admin.services.th_plan_service') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_code') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_title') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_tier') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_quota') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_price') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_status') }}</th>
                                <th class="p-3.5 text-left">{{ __('admin.services.th_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="plan in allPlans" :key="plan.id" class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                                <td class="p-3.5 font-bold text-slate-900 dark:text-white">{{ plan.service_title }}</td>
                                <td class="p-3.5 font-mono text-indigo-600 dark:text-indigo-400" dir="ltr">{{ plan.code }}</td>
                                <td class="p-3.5">
                                    <span class="font-bold text-slate-800 dark:text-slate-200 block">{{ plan.title }}</span>
                                    <span class="text-[10px] text-slate-400">{{ plan.description }}</span>
                                </td>
                                <td class="p-3.5">
                                    <span
                                        class="px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                        :class="plan.target_tier === 'enterprise' ? 'bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'"
                                    >
                                        {{ plan.target_tier === 'enterprise' ? __('catalog.tiers.enterprise') : __('catalog.tiers.individual') }}
                                    </span>
                                </td>
                                <td class="p-3.5 font-mono font-bold">{{ plan.quota_units }}</td>
                                <td class="p-3.5 font-mono font-black text-emerald-600 dark:text-emerald-400" dir="ltr">{{ plan.base_price }} TRX</td>
                                <td class="p-3.5">
                                    <span
                                        class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                        :class="plan.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-slate-100 text-slate-500'"
                                    >
                                        {{ plan.is_active ? __('catalog.flags.active') : __('catalog.flags.inactive') }}
                                    </span>
                                </td>
                                <td class="p-3.5 text-left">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button
                                            type="button"
                                            @click="openEditPlanModal(plan)"
                                            class="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 font-bold text-[11px] cursor-pointer"
                                        >
                                            {{ __('admin.users.edit') }}
                                        </button>
                                        <button
                                            type="button"
                                            @click="deletePlan(plan)"
                                            class="px-2.5 py-1 rounded-lg bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 font-bold text-[11px] cursor-pointer"
                                        >
                                            {{ __('admin.services.delete_btn') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 3: SUBSCRIBER SERVICE ALLOCATIONS MANAGEMENT                          -->
            <!-- ========================================================================= -->
            <div v-if="activeTab === 'allocations'" class="space-y-4">
                <!-- Filter Bar -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                        <div class="lg:col-span-2">
                            <input
                                v-model="searchFilter"
                                type="text"
                                @keyup.enter="applyAllocationFilters"
                                :placeholder="__('admin.services.search_allocations_placeholder')"
                                class="form-input w-full !text-xs"
                            />
                        </div>
                        <div>
                            <select v-model="serviceFilter" @change="applyAllocationFilters" class="form-select w-full !text-xs">
                                <option value="">{{ __('admin.services.all_services') }}</option>
                                <option v-for="def in definitions" :key="def.key" :value="def.key">{{ def.title }}</option>
                            </select>
                        </div>
                        <div>
                            <select v-model="statusFilter" @change="applyAllocationFilters" class="form-select w-full !text-xs">
                                <option value="">{{ __('admin.users.all_statuses') }}</option>
                                <option value="active">{{ __('catalog.flags.active') }}</option>
                                <option value="suspended">{{ __('admin.users.status_suspended') }}</option>
                                <option value="released">{{ __('admin.services.status_released') }}</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="applyAllocationFilters"
                                class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold cursor-pointer flex-1"
                            >
                                {{ __('admin.users.apply_filters') }}
                            </button>
                            <button
                                v-if="searchFilter || serviceFilter || statusFilter || userFilter"
                                type="button"
                                @click="clearAllocationFilters"
                                class="px-3 py-2.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 text-xs font-bold cursor-pointer"
                            >
                                ✕
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Allocations Table -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 font-bold border-b border-slate-100 dark:border-slate-800">
                                <tr>
                                    <th class="p-3.5">{{ __('admin.services.th_subscriber') }}</th>
                                    <th class="p-3.5">{{ __('admin.services.th_service_type') }}</th>
                                    <th class="p-3.5">{{ __('admin.services.th_identifier') }}</th>
                                    <th class="p-3.5">{{ __('admin.services.th_tier') }}</th>
                                    <th class="p-3.5">{{ __('admin.services.th_quota') }}</th>
                                    <th class="p-3.5">{{ __('admin.services.th_plan_status') }}</th>
                                    <th class="p-3.5">{{ __('admin.services.th_date') }}</th>
                                    <th class="p-3.5 text-left">{{ __('admin.services.th_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="alloc in allocations.data" :key="alloc.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                    <td class="p-3.5">
                                        <div v-if="alloc.user">
                                            <span class="font-bold text-slate-900 dark:text-white block">{{ alloc.user.name }}</span>
                                            <span class="text-[10px] font-mono text-slate-400" dir="ltr">@{{ alloc.user.username }}</span>
                                            <span v-if="alloc.user.organization_name" class="text-[10px] text-purple-600 dark:text-purple-400 block">
                                                🏢 {{ alloc.user.organization_name }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="p-3.5 font-bold text-slate-800 dark:text-slate-200">
                                        {{ alloc.service_title }}
                                    </td>
                                    <td class="p-3.5">
                                        <span class="font-mono font-black text-sm text-indigo-600 dark:text-indigo-400 block" dir="ltr">
                                            {{ alloc.resource_identifier }}
                                        </span>
                                        <span class="text-[10px] text-slate-400">{{ alloc.display_title }}</span>
                                    </td>
                                    <td class="p-3.5">
                                        <span
                                            class="px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                            :class="alloc.account_tier === 'enterprise' ? 'bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'"
                                        >
                                            {{ alloc.account_tier === 'enterprise' ? __('catalog.tiers.enterprise') : __('catalog.tiers.individual') }}
                                        </span>
                                    </td>
                                    <td class="p-3.5 font-mono">
                                        <span class="font-bold text-slate-900 dark:text-white">{{ alloc.used_quota }} / {{ alloc.total_quota }}</span>
                                    </td>
                                    <td class="p-3.5">
                                        <span
                                            class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                            :class="{
                                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300': alloc.status === 'active',
                                                'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300': alloc.status === 'suspended',
                                                'bg-slate-100 text-slate-500': alloc.status === 'released',
                                            }"
                                        >
                                            {{ alloc.status === 'active' ? __('catalog.flags.active') : (alloc.status === 'suspended' ? __('admin.users.status_suspended') : __('admin.services.status_released')) }}
                                        </span>
                                    </td>
                                    <td class="p-3.5 font-mono text-[11px] text-slate-400" dir="ltr">
                                        {{ alloc.allocated_at }}
                                    </td>
                                    <td class="p-3.5 text-left">
                                        <div class="inline-flex items-center gap-1.5">
                                            <button
                                                type="button"
                                                @click="openEditAllocModal(alloc)"
                                                class="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 font-bold text-[11px] cursor-pointer"
                                            >
                                                {{ __('admin.users.edit') }}
                                            </button>
                                            <button
                                                v-if="alloc.status !== 'released'"
                                                type="button"
                                                @click="releaseAllocation(alloc)"
                                                class="px-2.5 py-1 rounded-lg bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 font-bold text-[11px] cursor-pointer"
                                            >
                                                {{ __('admin.services.release_btn') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="allocations.data.length === 0">
                                    <td colspan="8" class="py-10 text-center text-slate-400">
                                        {{ __('admin.services.no_allocations_found') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 4: SERVICE ORDERS                                                     -->
            <!-- ========================================================================= -->
            <div v-if="activeTab === 'orders'" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 font-bold border-b border-slate-100 dark:border-slate-800">
                            <tr>
                                <th class="p-3.5">{{ __('admin.services.th_order_num') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_subscriber') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_service_type') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_identifier') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_price') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_plan_status') }}</th>
                                <th class="p-3.5">{{ __('admin.services.th_date') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr v-for="order in orders" :key="order.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="p-3.5 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">{{ order.order_number }}</td>
                                <td class="p-3.5">
                                    <span class="font-bold text-slate-900 dark:text-white block">{{ order.user?.name }}</span>
                                    <span class="text-[10px] font-mono text-slate-400" dir="ltr">@{{ order.user?.username }}</span>
                                </td>
                                <td class="p-3.5">
                                    <span class="font-bold block">{{ order.service_title }}</span>
                                    <span v-if="order.plan_title" class="text-[10px] text-slate-400">{{ order.plan_title }}</span>
                                </td>
                                <td class="p-3.5 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">{{ order.requested_identifier }}</td>
                                <td class="p-3.5 font-mono font-black text-emerald-600 dark:text-emerald-400" dir="ltr">{{ order.final_amount }} TRX</td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                        {{ order.status }}
                                    </span>
                                </td>
                                <td class="p-3.5 font-mono text-[11px] text-slate-400" dir="ltr">{{ order.created_at }}</td>
                            </tr>
                            <tr v-if="orders.length === 0">
                                <td colspan="7" class="py-10 text-center text-slate-400">
                                    {{ __('admin.services.no_orders_found') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- MODAL 1: CONFIGURE SERVICE DEFINITION, TARIFFS & VALIDATION POLICIES      -->
        <!-- ========================================================================= -->
        <div v-if="isEditDefModalOpen && editingDef" @click.self="isEditDefModalOpen = false" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-sm flex items-start sm:items-center justify-center p-3 sm:p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-2xl rounded-3xl shadow-2xl p-6 max-h-[calc(100dvh-2rem)] flex flex-col my-auto">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
                    <div>
                        <h3 class="text-base font-black text-slate-900 dark:text-white">{{ editingDef.title }}</h3>
                        <span class="text-xs text-slate-400 font-mono">{{ editingDef.key }}</span>
                    </div>
                    <button type="button" @click="isEditDefModalOpen = false" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitEditDef" class="space-y-5 overflow-y-auto flex-1 py-4 text-xs">
                    <!-- Status Toggles -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-800">
                        <label class="flex items-center gap-2 cursor-pointer font-bold">
                            <input v-model="defForm.is_active" type="checkbox" class="rounded text-indigo-600" />
                            <span>{{ __('admin.services.flag_is_active') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer font-bold">
                            <input v-model="defForm.is_purchasable" type="checkbox" class="rounded text-indigo-600" />
                            <span>{{ __('admin.services.flag_is_purchasable') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer font-bold">
                            <input v-model="defForm.requires_approval_for_enterprise" type="checkbox" class="rounded text-indigo-600" />
                            <span>{{ __('admin.services.flag_requires_approval') }}</span>
                        </label>
                    </div>

                    <!-- Tariff Pricing Config per Service -->
                    <div class="p-4 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-200/70 dark:border-indigo-800/50 space-y-3">
                        <h4 class="font-black text-indigo-900 dark:text-indigo-300 text-xs">{{ __('admin.services.modal_tariff_section_title') }}</h4>

                        <template v-if="editingDef.key === 'phone_line'">
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_standard_9d') }}</label>
                                    <input v-model="defForm.pricing_config.standard_9digit_base" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_semi_rond') }}</label>
                                    <input v-model="defForm.pricing_config.semi_rond_base" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_golden_rond') }}</label>
                                    <input v-model="defForm.pricing_config.golden_rond_base" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                            </div>

                            <div class="pt-2 border-t border-indigo-200/50 dark:border-indigo-800/40">
                                <span class="font-bold text-slate-700 dark:text-slate-300 block mb-2">{{ __('admin.services.shortcode_tariffs_by_length') }}</span>
                                <div class="grid grid-cols-5 gap-2">
                                    <div v-for="len in [4, 5, 6, 7, 8]" :key="len">
                                        <label class="text-[10px] font-bold text-slate-500 block mb-1 text-center">{{ len }} {{ __('admin.requests.digits_unit', { length: len }) }}</label>
                                        <input v-model="defForm.pricing_config.shortcode_length_prices[len]" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center !px-2" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="editingDef.key === 'custom_id'">
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_custom_id_standard') }}</label>
                                    <input v-model="defForm.pricing_config.standard_base_price" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_custom_id_semi') }}</label>
                                    <input v-model="defForm.pricing_config.semi_rond_price" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_custom_id_golden') }}</label>
                                    <input v-model="defForm.pricing_config.golden_rond_price" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_custom_id_4char') }}</label>
                                    <input v-model="defForm.pricing_config.enterprise_short_4_price" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_custom_id_5char') }}</label>
                                    <input v-model="defForm.pricing_config.enterprise_short_5_price" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_web3_short_domain') }}</label>
                                    <input v-model="defForm.pricing_config.short_domain_surcharge_3_to_5" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_web3_premium_tld') }}</label>
                                    <input v-model="defForm.pricing_config.premium_tld_surcharge" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="form-label mb-1">{{ __('admin.services.rate_web3_extra_quota') }}</label>
                                    <input v-model="defForm.pricing_config.extra_quota_unit_price" type="number" step="0.0001" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Tier Validation Policies -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-800 space-y-2.5">
                            <h5 class="font-black text-slate-800 dark:text-slate-200">{{ __('catalog.tiers.individual') }}</h5>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-slate-500 block mb-1">{{ __('admin.services.min_len_label') }}</label>
                                    <input v-model.number="defForm.validation_policies.individual.min_length" type="number" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-slate-500 block mb-1">{{ __('admin.services.max_res_label') }}</label>
                                    <input v-model.number="defForm.validation_policies.individual.max_active_resources" type="number" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-purple-50/40 dark:bg-purple-950/20 border border-purple-200 dark:border-purple-800/50 space-y-2.5">
                            <h5 class="font-black text-purple-800 dark:text-purple-300">{{ __('catalog.tiers.enterprise') }}</h5>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="text-[10px] text-slate-500 block mb-1">{{ __('admin.services.min_len_label') }}</label>
                                    <input v-model.number="defForm.validation_policies.enterprise.min_length" type="number" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-slate-500 block mb-1">{{ __('admin.services.max_res_label') }}</label>
                                    <input v-model.number="defForm.validation_policies.enterprise.max_active_resources" type="number" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-purple-600 font-bold block mb-1">{{ __('admin.services.discount_label') }} (%)</label>
                                    <input v-model.number="defForm.validation_policies.enterprise.discount_percent" type="number" min="0" max="90" dir="ltr" class="form-input w-full font-mono text-center" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
                        <button type="button" @click="isEditDefModalOpen = false" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold cursor-pointer">
                            {{ __('admin.users.cancel') }}
                        </button>
                        <button type="submit" :disabled="defForm.processing" class="px-6 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold cursor-pointer">
                            {{ __('admin.users.save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- MODAL 2: CREATE / EDIT SERVICE PLAN                                       -->
        <!-- ========================================================================= -->
        <div v-if="isPlanModalOpen" @click.self="isPlanModalOpen = false" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-md rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ editingPlan ? __('admin.services.edit_plan_modal_title') : __('admin.services.create_plan_modal_title') }}
                    </h3>
                    <button type="button" @click="isPlanModalOpen = false" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitPlanForm" class="space-y-3.5 text-xs">
                    <div v-if="!editingPlan">
                        <label class="form-label mb-1">{{ __('admin.services.th_plan_service') }}</label>
                        <select v-model="planForm.service_definition_id" class="form-select w-full">
                            <option v-for="def in definitions" :key="def.id" :value="def.id">{{ def.title }}</option>
                        </select>
                    </div>

                    <div v-if="!editingPlan">
                        <label class="form-label mb-1">{{ __('admin.services.th_plan_code') }}</label>
                        <input v-model="planForm.code" type="text" dir="ltr" placeholder="web3_pack_200" required class="form-input w-full font-mono text-left" />
                    </div>

                    <div>
                        <label class="form-label mb-1">{{ __('admin.services.th_plan_tier') }}</label>
                        <select v-model="planForm.target_tier" class="form-select w-full">
                            <option value="individual">{{ __('catalog.tiers.individual') }}</option>
                            <option value="enterprise">{{ __('catalog.tiers.enterprise') }}</option>
                            <option value="all">{{ __('admin.services.tier_all') }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label mb-1">{{ __('admin.services.th_plan_price') }} (TRX)</label>
                            <input v-model="planForm.base_price" type="number" step="0.0001" min="0" dir="ltr" required class="form-input w-full font-mono text-center" />
                        </div>
                        <div>
                            <label class="form-label mb-1">{{ __('admin.services.th_plan_quota') }}</label>
                            <input v-model.number="planForm.quota_units" type="number" min="1" dir="ltr" required class="form-input w-full font-mono text-center" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer pt-1">
                        <input v-model="planForm.is_active" type="checkbox" class="rounded text-indigo-600" />
                        <span class="font-bold">{{ __('admin.services.flag_is_active') }}</span>
                    </label>

                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
                        <button type="button" @click="isPlanModalOpen = false" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold cursor-pointer">
                            {{ __('admin.users.cancel') }}
                        </button>
                        <button type="submit" :disabled="planForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold cursor-pointer">
                            {{ __('admin.users.save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- MODAL 3: DIRECT PROVISION SERVICE TO SUBSCRIBER                           -->
        <!-- ========================================================================= -->
        <div v-if="isProvisionModalOpen" @click.self="isProvisionModalOpen = false" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-md rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.services.provision_modal_title') }}</h3>
                    <button type="button" @click="isProvisionModalOpen = false" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitProvision" class="space-y-3.5 text-xs">
                    <div>
                        <label class="form-label mb-1">{{ __('admin.services.th_subscriber') }}</label>
                        <select v-model="provisionForm.user_id" class="form-select w-full">
                            <option v-for="u in users_list" :key="u.id" :value="u.id">
                                {{ u.name }} (@{{ u.username }}) — {{ u.account_tier === 'enterprise' ? __('catalog.tiers.enterprise') : __('catalog.tiers.individual') }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label mb-1">{{ __('admin.services.th_service_type') }}</label>
                        <select v-model="provisionForm.service_key" class="form-select w-full">
                            <option v-for="def in definitions" :key="def.key" :value="def.key">{{ def.title }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label mb-1">{{ __('admin.services.th_identifier') }}</label>
                        <input
                            v-model="provisionForm.resource_identifier"
                            type="text"
                            dir="ltr"
                            required
                            :placeholder="provisionForm.service_key === 'phone_line' ? '9125558899 / 4050' : (provisionForm.service_key === 'custom_id' ? '@goldensource' : 'goldensource.web3')"
                            class="form-input w-full font-mono text-left"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label mb-1">{{ __('admin.services.th_tier') }}</label>
                            <select v-model="provisionForm.account_tier" class="form-select w-full">
                                <option value="individual">{{ __('catalog.tiers.individual') }}</option>
                                <option value="enterprise">{{ __('catalog.tiers.enterprise') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label mb-1">{{ __('admin.services.th_quota') }}</label>
                            <input v-model.number="provisionForm.total_quota" type="number" min="1" dir="ltr" class="form-input w-full font-mono text-center" />
                        </div>
                    </div>

                    <div>
                        <label class="form-label mb-1">{{ __('catalog.schemas.common.display_title_label') }}</label>
                        <input v-model="provisionForm.display_title" type="text" class="form-input w-full" />
                    </div>

                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
                        <button type="button" @click="isProvisionModalOpen = false" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold cursor-pointer">
                            {{ __('admin.users.cancel') }}
                        </button>
                        <button type="submit" :disabled="provisionForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold cursor-pointer">
                            {{ __('admin.services.provision_submit_btn') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- MODAL 4: EDIT SUBSCRIBER ALLOCATION                                       -->
        <!-- ========================================================================= -->
        <div v-if="isEditAllocModalOpen && editingAlloc" @click.self="isEditAllocModalOpen = false" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-md rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.services.edit_alloc_modal_title') }}</h3>
                        <span class="text-xs font-mono text-indigo-600 dark:text-indigo-400" dir="ltr">{{ editingAlloc.resource_identifier }}</span>
                    </div>
                    <button type="button" @click="isEditAllocModalOpen = false" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitEditAlloc" class="space-y-3.5 text-xs">
                    <div>
                        <label class="form-label mb-1">{{ __('admin.services.th_plan_status') }}</label>
                        <select v-model="allocForm.status" class="form-select w-full">
                            <option value="active">{{ __('catalog.flags.active') }}</option>
                            <option value="suspended">{{ __('admin.users.status_suspended') }}</option>
                            <option value="released">{{ __('admin.services.status_released') }}</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label mb-1">{{ __('admin.services.th_tier') }}</label>
                            <select v-model="allocForm.account_tier" class="form-select w-full">
                                <option value="individual">{{ __('catalog.tiers.individual') }}</option>
                                <option value="enterprise">{{ __('catalog.tiers.enterprise') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label mb-1">{{ __('admin.services.th_quota') }}</label>
                            <input v-model.number="allocForm.total_quota" type="number" min="1" dir="ltr" class="form-input w-full font-mono text-center" />
                        </div>
                    </div>

                    <div>
                        <label class="form-label mb-1">{{ __('catalog.schemas.common.display_title_label') }}</label>
                        <input v-model="allocForm.display_title" type="text" class="form-input w-full" />
                    </div>

                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end gap-2">
                        <button type="button" @click="isEditAllocModalOpen = false" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold cursor-pointer">
                            {{ __('admin.users.cancel') }}
                        </button>
                        <button type="submit" :disabled="allocForm.processing" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold cursor-pointer">
                            {{ __('admin.users.save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>

