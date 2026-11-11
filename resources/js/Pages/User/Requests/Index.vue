<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface AllocatedNumber {
    id: number;
    number: string;
    formatted_number: string;
}

interface NumberRequestItem {
    id: number;
    phone_prefix_id?: number | null;
    prefix?: {
        id: number;
        prefix: string;
        country_name?: string;
        flag?: string;
    } | null;
    requested_length: number;
    service_title: string;
    description: string;
    preferred_number: string | null;
    is_rond?: boolean;
    rond_tier?: string;
    held_amount?: string;
    hold_reference_id?: string;
    accepts_incoming_sms: boolean;
    status: 'pending' | 'approved' | 'rejected';
    status_label: string;
    status_badge: string;
    rejection_reason: string | null;
    allocated_number: AllocatedNumber | null;
    reviewer_name: string | null;
    reviewed_at: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PoolNumberItem {
    id: number;
    number: string;
    length: number;
    rond_tier: string;
    rond_pattern?: string;
}

export interface PrefixOption {
    id: number;
    prefix: string;
    clean_prefix: string;
    name: string;
    country_name?: string;
    country_code?: string;
    flag?: string;
    is_default: boolean;
}

const props = defineProps<{
    requests: {
        data: NumberRequestItem[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
    };
    stats: {
        total: number;
        pending: number;
        approved: number;
        rejected: number;
    };
    org_tariffs?: Record<number, { regular: string; rond: string }>;
    wallet?: { balance: string };
    available_pool_numbers?: PoolNumberItem[];
    active_prefixes?: PrefixOption[];
    service_catalog?: Array<Record<string, any>>;
    enterprise_orders?: Array<Record<string, any>>;
    account_tier?: string;
    organization_name?: string | null;
    enterprise_contract_code?: string | null;
}>();

// Enterprise Tier & Organization Profile Form
const tierForm = useForm({
    account_tier: props.account_tier || 'individual',
    organization_name: props.organization_name || '',
});

const toggleEnterpriseTier = (targetTier: 'individual' | 'enterprise') => {
    tierForm.account_tier = targetTier;
    tierForm.post(route('enterprise.tier.update'), {
        preserveScroll: true,
    });
};

// Enterprise Multi-Service Order Modal State
const isEnterpriseServiceModalOpen = ref(false);
const activeEnterpriseServiceKey = ref<'custom_id' | 'web3_email_package'>('custom_id');
const activeEnterpriseService = computed(() =>
    (props.service_catalog || []).find((s) => s.key === activeEnterpriseServiceKey.value) || null
);

const entOrderForm = useForm({
    service_key: 'custom_id',
    plan_code: 'id_enterprise_short',
    order_context: 'enterprise',
    handle: '',
    base_domain: '',
    tld: 'dao',
    initial_sub_accounts: 'ceo, info, finance, legal',
    display_title: '',
});

const entValidationLoading = ref(false);
const entValidationResult = ref<Record<string, any> | null>(null);

const openEnterpriseServiceModal = (serviceKey: 'custom_id' | 'web3_email_package') => {
    activeEnterpriseServiceKey.value = serviceKey;
    entOrderForm.reset();
    entOrderForm.clearErrors();
    entOrderForm.service_key = serviceKey;
    entOrderForm.order_context = 'enterprise';
    entOrderForm.plan_code = serviceKey === 'custom_id' ? 'id_enterprise_short' : 'web3_ent_pack_100';
    entOrderForm.tld = 'dao';
    entValidationResult.value = null;
    isEnterpriseServiceModalOpen.value = true;
};

const closeEnterpriseServiceModal = () => {
    isEnterpriseServiceModalOpen.value = false;
    entValidationResult.value = null;
};

const validateEnterpriseOrder = async () => {
    entValidationLoading.value = true;
    entValidationResult.value = null;
    try {
        const res = await axios.post(route('services.validate'), {
            service_key: entOrderForm.service_key,
            plan_code: entOrderForm.plan_code,
            order_context: 'enterprise',
            handle: entOrderForm.handle,
            base_domain: entOrderForm.base_domain,
            tld: entOrderForm.tld,
            initial_sub_accounts: entOrderForm.initial_sub_accounts,
            display_title: entOrderForm.display_title,
        });
        entValidationResult.value = res.data;
    } catch (e: any) {
        if (e.response?.data) {
            entValidationResult.value = e.response.data;
        }
    } finally {
        entValidationLoading.value = false;
    }
};

const submitEnterpriseServiceOrder = () => {
    entOrderForm.post(route('services.orders.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeEnterpriseServiceModal();
            showToast(__('dashboard.op_success'));
        },
    });
};

// Filter states
const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');

const handleFilter = () => {
    router.get(
        route('requests.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilter = () => {
    search.value = '';
    statusFilter.value = '';
    handleFilter();
};

// Create Request Modal State
const isCreateModalOpen = ref(false);
const createStep = ref<1 | 2 | 3 | 4>(1);
const availableLengths = [4, 5, 6, 7, 8];
const politicalTermsAccepted = ref(false);
const countrySearch = ref('');

const createForm = useForm({
    phone_prefix_id: null as number | null,
    requested_length: 4,
    service_title: '',
    description: '',
    preferred_number: '',
    accepts_incoming_sms: true,
});

const selectedPrefix = computed(() => {
    if (!props.active_prefixes || props.active_prefixes.length === 0) return null;
    if (createForm.phone_prefix_id) {
        return props.active_prefixes.find((p) => p.id === createForm.phone_prefix_id) || props.active_prefixes[0];
    }
    return props.active_prefixes.find((p) => p.is_default) || props.active_prefixes[0];
});

const filteredPrefixes = computed(() => {
    if (!props.active_prefixes) return [];
    if (!countrySearch.value.trim()) return props.active_prefixes;
    const q = countrySearch.value.trim().toLowerCase();
    return props.active_prefixes.filter(
        (p) =>
            (p.country_name && p.country_name.toLowerCase().includes(q)) ||
            (p.name && p.name.toLowerCase().includes(q)) ||
            p.prefix.toLowerCase().includes(q) ||
            (p.country_code && p.country_code.toLowerCase().includes(q))
    );
});

// Detect rondness for preferred number
const isNumberRond = (num: string): boolean => {
    if (!num || num.length < 4) return false;
    // All identical: 1111, 77777
    if (/^(\d)\1+$/.test(num)) return true;
    // Multiples of thousands: 1000, 50000
    if (/^[1-9]0{3,}$/.test(num)) return true;
    // Pairs: 1122, 1212
    if (num.length === 4 && (num.slice(0, 2) === num.slice(2, 4) || (num[0] === num[1] && num[2] === num[3]))) return true;
    // Sequential: 1234, 4321
    if (num === '1234' || num === '2345' || num === '3456' || num === '4321' || num === '5432' || num === '6543') return true;
    // Mirror: 1221, 12321
    if (num === num.split('').reverse().join('')) return true;
    return false;
};

const currentIsRond = computed(() => {
    if (!createForm.preferred_number) return false;
    return isNumberRond(createForm.preferred_number);
});

const currentPrice = computed(() => {
    const len = createForm.requested_length;
    const tariffs = props.org_tariffs;
    if (tariffs && tariffs[len]) {
        return currentIsRond.value ? tariffs[len].rond : tariffs[len].regular;
    }
    return currentIsRond.value ? '250.0000' : '100.0000';
});

const userBalance = computed(() => Number(props.wallet?.balance || 0));
const hasInsufficientBalance = computed(() => userBalance.value < Number(currentPrice.value));

const poolNumbersForCurrentLength = computed(() => {
    if (!props.available_pool_numbers) return [];
    return props.available_pool_numbers.filter((p) => p.length === createForm.requested_length);
});

const pickPoolNumber = (pNum: string) => {
    createForm.preferred_number = pNum;
};

const openCreateModal = () => {
    createStep.value = 1;
    politicalTermsAccepted.value = false;
    countrySearch.value = '';
    createForm.reset();
    createForm.phone_prefix_id = (props.active_prefixes?.find((p) => p.is_default) || props.active_prefixes?.[0])?.id || null;
    createForm.requested_length = 4;
    createForm.accepts_incoming_sms = true;
    createForm.clearErrors();
    isCreateModalOpen.value = true;
};

const closeCreateModal = () => {
    isCreateModalOpen.value = false;
};

const goToStep2 = () => {
    if (!createForm.phone_prefix_id && selectedPrefix.value) {
        createForm.phone_prefix_id = selectedPrefix.value.id;
    }
    createStep.value = 2;
};

const goToStep3 = () => {
    if (createForm.preferred_number && createForm.preferred_number.length !== createForm.requested_length) {
        createForm.setError('preferred_number', __('user.requests.length_error', { length: createForm.requested_length }));
        return;
    }
    createForm.clearErrors('preferred_number');
    createStep.value = 3;
};

const goToStep4 = () => {
    createForm.clearErrors();
    let hasErr = false;
    if (!createForm.service_title.trim()) {
        createForm.setError('service_title', __('user.requests.service_title_error'));
        hasErr = true;
    }
    if (!createForm.description.trim()) {
        createForm.setError('description', __('user.requests.description_error'));
        hasErr = true;
    }
    if (!politicalTermsAccepted.value) {
        hasErr = true;
    }
    if (hasErr) return;
    createStep.value = 4;
};

const submitCreate = () => {
    if (!politicalTermsAccepted.value || hasInsufficientBalance.value) return;
    createForm.post(route('requests.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateModalOpen.value = false;
            createForm.reset();
            createStep.value = 1;
        },
    });
};

// Details Modal State
const selectedRequest = ref<NumberRequestItem | null>(null);
const isDetailsModalOpen = ref(false);

const openDetailsModal = (req: NumberRequestItem) => {
    selectedRequest.value = req;
    isDetailsModalOpen.value = true;
};

const closeDetailsModal = () => {
    isDetailsModalOpen.value = false;
    selectedRequest.value = null;
};

// Cancel Modal State
const requestToCancel = ref<NumberRequestItem | null>(null);
const isCancelModalOpen = ref(false);
const cancelProcessing = ref(false);

const openCancelModal = (req: NumberRequestItem) => {
    requestToCancel.value = req;
    isCancelModalOpen.value = true;
};

const closeCancelModal = () => {
    isCancelModalOpen.value = false;
    requestToCancel.value = null;
};

const confirmCancel = () => {
    if (!requestToCancel.value) return;

    cancelProcessing.value = true;
    router.delete(route('requests.destroy', requestToCancel.value.id), {
        preserveScroll: true,
        onFinish: () => {
            cancelProcessing.value = false;
            closeCancelModal();
        },
    });
};

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;
const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
};

const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        showToast(__('user.requests.copied'));
    } catch {
        showToast(__('user.requests.copied'));
    }
};

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isCreateModalOpen.value) closeCreateModal();
        if (isDetailsModalOpen.value) closeDetailsModal();
        if (isCancelModalOpen.value) closeCancelModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <UserLayout>
        <Head :title="__('catalog.enterprise_hub_title')" />

        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4 scale-95" enter-to-class="opacity-100 translate-y-0 scale-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 -translate-y-2 scale-95">
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
                >
                    <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-4">
            <!-- 1. Isolated Enterprise Command Header & Tier Policy Controls -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                        <span class="wallet-title">{{ __('catalog.enterprise_hub_title') }}</span>
                    </div>
                    <span
                        class="wallet-chip"
                        :class="account_tier === 'enterprise'
                            ? 'bg-amber-500/20 border-amber-500/40 text-amber-600 dark:text-amber-300'
                            : 'bg-slate-500/15 border-slate-500/30 text-slate-600 dark:text-slate-300'"
                    >
                        {{ account_tier === 'enterprise' ? __('catalog.tiers.enterprise_active_badge') : __('catalog.tiers.individual_active_badge') }}
                    </span>
                </div>

                <div class="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    {{ __('catalog.enterprise_hub_subtitle') }}
                </div>

                <!-- Enterprise Organization & Tier Switcher Row -->
                <div class="mt-3 p-3 rounded-2xl bg-slate-100/80 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2 flex-1 min-w-[200px]">
                        <input
                            type="text"
                            v-model="tierForm.organization_name"
                            :placeholder="__('catalog.ui.organization_name_placeholder')"
                            class="glass-input !py-1.5 !px-3 !text-xs !rounded-xl flex-1 bg-white dark:bg-slate-900"
                        />
                        <span v-if="enterprise_contract_code" class="text-[11px] font-mono px-2.5 py-1 rounded-lg bg-amber-500/15 text-amber-600 dark:text-amber-300 font-bold" dir="ltr">
                            {{ enterprise_contract_code }}
                        </span>
                    </div>

                    <button
                        v-if="account_tier !== 'enterprise'"
                        type="button"
                        @click="toggleEnterpriseTier('enterprise')"
                        :disabled="tierForm.processing"
                        class="glass-btn-primary !w-auto !py-1.5 !px-4 !text-xs !rounded-xl"
                    >
                        {{ __('catalog.tiers.switch_to_enterprise') }}
                    </button>
                    <button
                        v-else
                        type="button"
                        @click="toggleEnterpriseTier('individual')"
                        :disabled="tierForm.processing"
                        class="glass-pill !py-1.5 !px-3.5 !text-xs font-bold text-slate-600 dark:text-slate-300"
                    >
                        {{ __('catalog.tiers.switch_to_individual') }}
                    </button>
                </div>

                <!-- 3 Exclusive Enterprise Service Offerings -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10">
                    <!-- Service 1: 4-8 Digit Organizational Lines -->
                    <div class="p-3.5 rounded-2xl bg-slate-100/90 dark:bg-slate-900/70 border border-purple-500/30 flex flex-col justify-between space-y-3">
                        <div class="space-y-1.5">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-600 dark:text-purple-400">
                                {{ __('catalog.ui.enterprise_exclusive_badge') }}
                            </span>
                            <h3 class="text-xs font-black text-slate-900 dark:text-white">
                                {{ __('catalog.services.phone_line.enterprise_title') }}
                            </h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                {{ __('catalog.services.phone_line.enterprise_desc') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openCreateModal"
                            class="glass-btn-primary !w-full !py-2 !text-xs !rounded-xl"
                        >
                            {{ __('user.requests.create_btn') }}
                        </button>
                    </div>

                    <!-- Service 2: Short 4+ Char Enterprise Custom IDs -->
                    <div class="p-3.5 rounded-2xl bg-slate-100/90 dark:bg-slate-900/70 border border-indigo-500/30 flex flex-col justify-between space-y-3">
                        <div class="space-y-1.5">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-600 dark:text-indigo-400">
                                {{ __('catalog.ui.min_id_length_rule', { min: 4 }) }}
                            </span>
                            <h3 class="text-xs font-black text-slate-900 dark:text-white">
                                {{ __('catalog.services.custom_id.enterprise_title') }}
                            </h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                {{ __('catalog.services.custom_id.enterprise_desc') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openEnterpriseServiceModal('custom_id')"
                            class="glass-btn-primary !w-full !py-2 !text-xs !rounded-xl"
                        >
                            {{ __('catalog.ui.submit_enterprise_order') }}
                        </button>
                    </div>

                    <!-- Service 3: High-Volume Web3 Organizational Email Packages -->
                    <div class="p-3.5 rounded-2xl bg-slate-100/90 dark:bg-slate-900/70 border border-amber-500/30 flex flex-col justify-between space-y-3">
                        <div class="space-y-1.5">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400">
                                50 / 100 / 500 Quota (.dao, .org3, .web3)
                            </span>
                            <h3 class="text-xs font-black text-slate-900 dark:text-white">
                                {{ __('catalog.services.web3_email_package.enterprise_title') }}
                            </h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                {{ __('catalog.services.web3_email_package.enterprise_desc') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openEnterpriseServiceModal('web3_email_package')"
                            class="glass-btn-primary !w-full !py-2 !text-xs !rounded-xl"
                        >
                            {{ __('catalog.ui.submit_enterprise_order') }}
                        </button>
                    </div>
                </div>

                <!-- Stats 3-Column Pill Row -->
                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 text-center">
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-amber-600 dark:text-amber-400 block font-bold">{{ __('user.requests.stat_pending') }}</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.pending }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 block font-bold">{{ __('user.requests.stat_approved') }}</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.approved }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-rose-600 dark:text-rose-400 block font-bold">{{ __('user.requests.stat_rejected') }}</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.rejected }}</span>
                    </div>
                </div>
            </div>

            <!-- Recent Enterprise Multi-Service Orders -->
            <div v-if="enterprise_orders && enterprise_orders.length > 0" class="glass-card !p-4 space-y-2.5 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <h3 class="text-xs font-black text-slate-900 dark:text-white">
                    {{ __('catalog.ui.enterprise_orders_history') }}
                </h3>
                <div class="space-y-2">
                    <div
                        v-for="ord in enterprise_orders"
                        :key="ord.id"
                        class="p-2.5 rounded-xl bg-slate-100/80 dark:bg-slate-950/60 border border-slate-200/80 dark:border-white/10 flex flex-wrap items-center justify-between gap-2 text-xs"
                    >
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-[11px] text-slate-400" dir="ltr">{{ ord.order_number }}</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ ord.service_title }}</span>
                            <span class="font-mono font-black text-indigo-600 dark:text-indigo-400" dir="ltr">{{ ord.requested_identifier }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400" dir="ltr">{{ ord.final_amount }} TRX</span>
                            <span class="text-[10px] text-slate-400">{{ ord.created_at }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Search & Filter Bar -->
            <div class="glass-card !p-3 flex items-center gap-2 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="relative flex-1">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        :placeholder="__('user.requests.search_placeholder')"
                        class="glass-input !py-2 !pr-8 !pl-3 !text-xs !rounded-xl w-full bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                    />
                    <svg class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <select
                    v-model="statusFilter"
                    @change="handleFilter"
                    class="form-select !py-2 !text-xs !rounded-xl w-auto"
                >
                    <option value="">{{ __('user.requests.all_statuses') }}</option>
                    <option value="pending">{{ __('user.requests.stat_pending') }}</option>
                    <option value="approved">{{ __('user.requests.stat_approved') }}</option>
                    <option value="rejected">{{ __('user.requests.stat_rejected') }}</option>
                </select>

                <button
                    v-if="search || statusFilter"
                    type="button"
                    @click="resetFilter"
                    class="w-8 h-8 rounded-xl glass-pill !p-0 flex items-center justify-center text-rose-500 hover:bg-rose-500/10"
                    :title="__('user.requests.clear')"
                >
                    ✕
                </button>
            </div>

            <!-- 3. Requests List Cards -->
            <div v-if="requests.data.length > 0" class="space-y-3">
                <div
                    v-for="req in requests.data"
                    :key="req.id"
                    class="glass-card !p-4 hover:border-purple-500/40 transition-all space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                >
                    <!-- Header: Service Title & Status Badge -->
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center flex-wrap gap-2 min-w-0">
                            <span v-if="req.prefix" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-500/15 border border-indigo-500/30 text-indigo-700 dark:text-indigo-400 shrink-0">
                                {{ req.prefix.flag }} {{ req.prefix.prefix }}
                            </span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 border border-purple-500/30 text-purple-700 dark:text-purple-400 shrink-0">
                                {{ __('user.requests.digits_unit', { count: req.requested_length }) }}
                            </span>
                            <span
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full border shrink-0"
                                :class="req.accepts_incoming_sms ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 border-slate-500/30 text-slate-500'"
                            >
                                {{ req.accepts_incoming_sms ? __('user.requests.twoway_badge') : __('user.requests.oneway_badge') }}
                            </span>
                            <span
                                v-if="req.held_amount && Number(req.held_amount) > 0"
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-700 dark:text-amber-400 shrink-0"
                            >
                                {{ __('user.requests.held_amount', { amount: req.held_amount }) }}
                            </span>
                            <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                                {{ req.service_title }}
                            </span>
                        </div>

                        <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border shadow-xs shrink-0" :class="req.status_badge">
                            {{ req.status_label }}
                        </span>
                    </div>

                    <!-- Description Snippet -->
                    <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2 leading-relaxed">
                        {{ req.description }}
                    </p>

                    <!-- Allocated Number Box if Approved -->
                    <div
                        v-if="req.status === 'approved' && req.allocated_number"
                        class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-500/25 flex items-center justify-between"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400">{{ __('user.requests.allocated_number') }}</span>
                            <span dir="ltr" class="font-mono font-black text-xs text-slate-900 dark:text-white">
                                {{ req.allocated_number.formatted_number }}
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="copyToClipboard(req.allocated_number.formatted_number)"
                            class="p-1 text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300"
                            :title="__('user.requests.copy')"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <!-- Rejection Reason if Rejected -->
                    <div
                        v-else-if="req.status === 'rejected' && req.rejection_reason"
                        class="p-2.5 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/20 text-rose-700 dark:text-rose-300 text-[11px] leading-relaxed"
                    >
                        <span class="font-bold">{{ __('user.requests.rejection_reason') }}</span> {{ req.rejection_reason }}
                    </div>

                    <!-- Footer: Date & Actions -->
                    <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-[11px]">
                        <span class="text-slate-500 dark:text-slate-400 font-mono">{{ req.created_at }}</span>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="openDetailsModal(req)"
                                class="font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300"
                            >{{ __('user.requests.details') }}</button>

                            <button
                                v-if="req.status === 'pending'"
                                type="button"
                                @click="openCancelModal(req)"
                                class="font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300"
                            >{{ __('user.requests.cancel') }}</button>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="requests.links && requests.links.length > 3" class="flex justify-center gap-1 pt-2">
                    <Link
                        v-for="(link, i) in requests.links"
                        :key="i"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                        :class="[
                            link.active ? 'bg-purple-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-400',
                            !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="glass-card !p-8 flex flex-col items-center justify-center text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="w-14 h-14 rounded-3xl bg-purple-500/15 border border-purple-500/30 text-purple-600 dark:text-purple-400 mx-auto flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">{{ __('user.requests.no_requests_title') }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                        {{ __('user.requests.no_requests_desc') }}
                    </p>
                </div>
                <div class="w-full flex justify-center pt-2">
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-xl inline-flex items-center justify-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>{{ __('user.requests.create_first') }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 4. Create Request Modal (4-Step Flow) -->
        <teleport to="body">
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="isCreateModalOpen"
                    class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                    @click.self="closeCreateModal"
                >
                    <div
                        class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-lg shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                    >
                        <!-- Modal Top Header -->
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 dark:border-white/10">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-2xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                                    🏢
                                </div>
                                <div>
                                    <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('user.requests.modal_title') }}</h3>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('user.requests.modal_subtitle') }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="closeCreateModal"
                                class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer"
                                :aria-label="__('user.requests.close')"
                            >
                                ✕
                            </button>
                        </div>

                        <!-- 4-Step Progress Stepper Indicator -->
                        <div class="flex items-center justify-between px-1 pt-1 text-[11px] font-bold">
                            <!-- Step 1 -->
                            <div
                                @click="createStep > 1 && (createStep = 1)"
                                class="flex items-center gap-1.5"
                                :class="createStep > 1 ? 'cursor-pointer' : ''"
                            >
                                <div
                                    class="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-black transition-all"
                                    :class="createStep === 1
                                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                                        : (createStep > 1 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                                >
                                    <span v-if="createStep > 1">✓</span>
                                    <span v-else>1</span>
                                </div>
                                <span :class="createStep === 1 ? 'text-purple-600 dark:text-purple-400 font-black' : (createStep > 1 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">{{ __('user.requests.step1_tab') }}</span>
                            </div>

                            <div class="flex-1 mx-1.5 h-0.5" :class="createStep > 1 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-white/10'"></div>

                            <!-- Step 2 -->
                            <div
                                @click="createStep > 2 && (createStep = 2)"
                                class="flex items-center gap-1.5"
                                :class="createStep > 2 ? 'cursor-pointer' : ''"
                            >
                                <div
                                    class="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-black transition-all"
                                    :class="createStep === 2
                                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                                        : (createStep > 2 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                                >
                                    <span v-if="createStep > 2">✓</span>
                                    <span v-else>2</span>
                                </div>
                                <span :class="createStep === 2 ? 'text-purple-600 dark:text-purple-400 font-black' : (createStep > 2 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">{{ __('user.requests.step2_tab') }}</span>
                            </div>

                            <div class="flex-1 mx-1.5 h-0.5" :class="createStep > 2 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-white/10'"></div>

                            <!-- Step 3 -->
                            <div
                                @click="createStep > 3 && (createStep = 3)"
                                class="flex items-center gap-1.5"
                                :class="createStep > 3 ? 'cursor-pointer' : ''"
                            >
                                <div
                                    class="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-black transition-all"
                                    :class="createStep === 3
                                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                                        : (createStep > 3 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                                >
                                    <span v-if="createStep > 3">✓</span>
                                    <span v-else>3</span>
                                </div>
                                <span :class="createStep === 3 ? 'text-purple-600 dark:text-purple-400 font-black' : (createStep > 3 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">{{ __('user.requests.step3_tab') }}</span>
                            </div>

                            <div class="flex-1 mx-1.5 h-0.5" :class="createStep > 3 ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-white/10'"></div>

                            <!-- Step 4 -->
                            <div class="flex items-center gap-1.5">
                                <div
                                    class="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-black transition-all"
                                    :class="createStep === 4
                                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/30'
                                        : 'bg-slate-100 dark:bg-white/10 text-slate-400'"
                                >
                                    <span>4</span>
                                </div>
                                <span :class="createStep === 4 ? 'text-purple-600 dark:text-purple-400 font-black' : 'text-slate-400'">{{ __('user.requests.step4_tab') }}</span>
                            </div>
                        </div>

                        <!-- ============================================== -->
                        <!-- STEP 1: Country & Prefix Selection             -->
                        <!-- ============================================== -->
                        <div v-if="createStep === 1" class="space-y-4">
                            <div>
                                <h4 class="font-bold text-xs text-slate-800 dark:text-slate-200">
                                    {{ __('user.requests.step1_title') }}
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ __('user.requests.step1_desc') }}
                                </p>
                            </div>

                            <!-- Search Input -->
                            <div class="relative">
                                <input
                                    type="text"
                                    v-model="countrySearch"
                                    :placeholder="__('user.requests.step1_search')"
                                    class="form-input !py-2 !px-3 !pr-9 !text-xs"
                                />
                                <span class="absolute right-3 top-2.5 text-slate-400 text-xs">🔍</span>
                            </div>

                            <!-- Country Cards Grid -->
                            <div class="max-h-64 overflow-y-auto space-y-1.5 pr-0.5">
                                <div
                                    v-for="p in filteredPrefixes"
                                    :key="p.id"
                                    @click="createForm.phone_prefix_id = p.id"
                                    class="p-3 rounded-2xl border transition-all cursor-pointer flex items-center justify-between"
                                    :class="createForm.phone_prefix_id === p.id
                                        ? 'bg-purple-50/80 dark:bg-purple-950/40 border-purple-500 shadow-sm'
                                        : 'bg-slate-50/70 hover:bg-slate-100 dark:bg-white/5 dark:hover:bg-white/10 border-slate-200 dark:border-white/10'"
                                >
                                    <div class="flex items-center gap-3">
                                        <span class="text-2xl leading-none">{{ p.flag || '🌐' }}</span>
                                        <div>
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-black text-xs text-slate-900 dark:text-white">
                                                    {{ p.country_name || p.name }}
                                                </span>
                                                <span v-if="p.country_code" class="text-[9px] font-mono px-1 py-0.2 rounded bg-slate-200/80 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                                                    {{ p.country_code }}
                                                </span>
                                            </div>
                                            <span class="text-[11px] font-mono font-bold text-purple-600 dark:text-purple-400" dir="ltr">
                                                {{ p.prefix }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="w-5 h-5 rounded-full flex items-center justify-center border transition-all"
                                        :class="createForm.phone_prefix_id === p.id
                                            ? 'border-purple-600 bg-purple-600 text-white'
                                            : 'border-slate-300 dark:border-slate-600'"
                                    >
                                        <span v-if="createForm.phone_prefix_id === p.id" class="text-[10px]">✓</span>
                                    </div>
                                </div>

                                <div v-if="filteredPrefixes.length === 0" class="text-center py-6 text-xs text-slate-400">
                                    {{ __('user.requests.no_prefixes_found') }}
                                </div>
                            </div>

                            <!-- Selected Country Banner & Footer -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <div class="text-xs font-bold text-slate-600 dark:text-slate-400 flex items-center gap-1.5">
                                    <span>{{ __('user.requests.current_selection') }}</span>
                                    <span class="text-purple-600 dark:text-purple-400 font-black">
                                        {{ selectedPrefix?.flag }} {{ selectedPrefix?.country_name || selectedPrefix?.name }} ({{ selectedPrefix?.prefix }})
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    @click="goToStep2"
                                    :disabled="!selectedPrefix"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-1.5 shadow-md shadow-purple-600/25 cursor-pointer disabled:opacity-40"
                                >
                                    <span>{{ __('user.requests.continue_step2') }}</span>
                                    <span>←</span>
                                </button>
                            </div>
                        </div>

                        <!-- ============================================== -->
                        <!-- STEP 2: Length & Number Selection              -->
                        <!-- ============================================== -->
                        <div v-else-if="createStep === 2" class="space-y-4">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <div>
                                    <h4 class="font-bold text-xs text-slate-800 dark:text-slate-200">
                                        {{ __('user.requests.step2_title') }}
                                    </h4>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                        {{ __('user.requests.step2_desc') }}
                                    </p>
                                </div>
                                <div class="px-2.5 py-1 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 text-xs font-bold border border-purple-500/20">
                                    {{ selectedPrefix?.flag }} {{ selectedPrefix?.prefix }}
                                </div>
                            </div>

                            <!-- Length Chips -->
                            <div>
                                <label class="form-label">{{ __('user.requests.length_label') }}</label>
                                <div class="grid grid-cols-5 gap-1.5">
                                    <button
                                        v-for="l in availableLengths"
                                        :key="l"
                                        type="button"
                                        @click="createForm.requested_length = l; if (createForm.preferred_number && createForm.preferred_number.length !== l) createForm.preferred_number = '';"
                                        class="py-2.5 rounded-2xl text-xs font-mono font-bold transition-all border cursor-pointer"
                                        :class="[
                                            createForm.requested_length === l
                                                ? 'bg-purple-600 border-purple-500 text-white shadow-md shadow-purple-600/25'
                                                : 'bg-slate-50 hover:bg-slate-100 dark:bg-white/5 dark:hover:bg-white/10 border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300'
                                        ]"
                                    >
                                        {{ __('user.requests.digits_unit', { count: l }) }}
                                    </button>
                                </div>
                            </div>

                            <!-- Pool Suggestions for Selected Length -->
                            <div class="p-3.5 rounded-2xl bg-purple-50/60 dark:bg-purple-950/20 border border-purple-200/60 dark:border-purple-500/20 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-black text-purple-800 dark:text-purple-300">
                                        {{ __('user.requests.pool_numbers_title', { length: createForm.requested_length }) }}
                                    </span>
                                    <span class="text-[10px] text-purple-600 dark:text-purple-400 font-bold">
                                        {{ __('user.requests.available_count', { count: poolNumbersForCurrentLength.length }) }}
                                    </span>
                                </div>

                                <div v-if="poolNumbersForCurrentLength.length > 0" class="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto pt-0.5">
                                    <button
                                        v-for="pool in poolNumbersForCurrentLength"
                                        :key="pool.id"
                                        type="button"
                                        @click="pickPoolNumber(pool.number)"
                                        class="px-2.5 py-1.5 rounded-xl text-xs font-mono font-bold border transition-all cursor-pointer flex items-center gap-1"
                                        :class="createForm.preferred_number === pool.number
                                            ? 'bg-purple-600 text-white border-purple-600 shadow-sm'
                                            : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-white/10 hover:border-purple-400 hover:bg-purple-50/40'"
                                    >
                                        <span>{{ pool.number }}</span>
                                        <span v-if="pool.rond_tier && pool.rond_tier !== 'regular'" class="text-[10px] text-amber-500" :title="__('user.requests.rond_number_title')">★</span>
                                    </button>
                                </div>
                                <div v-else class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed py-1">
                                    {{ __('user.requests.no_pool_numbers') }}
                                </div>
                            </div>

                            <!-- Preferred Number Input -->
                            <div>
                                <label class="form-label flex items-center justify-between">
                                    <span>{{ __('user.requests.preferred_number_label') }}</span>
                                    <span class="font-mono text-[10px] text-slate-400 font-bold">
                                        {{ createForm.preferred_number ? createForm.preferred_number.length : 0 }} / {{ __('user.requests.digits_unit', { count: createForm.requested_length }) }}
                                    </span>
                                </label>
                                <div dir="ltr" class="flex items-center gap-1.5 p-1 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                    <div class="h-10 px-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono font-black text-xs flex items-center gap-1 text-slate-800 dark:text-slate-200 shrink-0">
                                        <span>{{ selectedPrefix?.flag }}</span>
                                        <span>{{ selectedPrefix?.prefix || '+85' }}</span>
                                    </div>
                                    <span class="text-slate-400 font-bold select-none">-</span>
                                    <input
                                        type="text"
                                        v-model="createForm.preferred_number"
                                        :maxlength="createForm.requested_length"
                                        :placeholder="__('user.requests.placeholder_preferred')"
                                        dir="ltr"
                                        class="w-full h-10 px-3 rounded-xl bg-white dark:bg-slate-900 font-mono font-black text-sm text-center tracking-widest text-slate-900 dark:text-white border border-slate-200 dark:border-slate-700 focus:border-purple-500 outline-none"
                                    />
                                    <button
                                        v-if="createForm.preferred_number"
                                        type="button"
                                        @click="createForm.preferred_number = ''"
                                        class="w-7 h-7 rounded-lg text-slate-400 hover:text-rose-500 flex items-center justify-center text-xs"
                                        :title="__('user.requests.clear_number')"
                                    >
                                        ✕
                                    </button>
                                </div>
                                <p v-if="createForm.errors.preferred_number" class="text-xs text-rose-500 font-bold mt-1">
                                    {{ createForm.errors.preferred_number }}
                                </p>
                            </div>

                            <!-- Live Price Estimation Box -->
                            <div class="p-3.5 rounded-2xl bg-slate-100/90 dark:bg-slate-800/80 border border-slate-200 dark:border-white/10 flex items-center justify-between text-xs">
                                <div>
                                    <span class="text-slate-500 dark:text-slate-400 block text-[11px]">{{ __('user.requests.estimated_tariff') }}</span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="font-black text-sm text-purple-600 dark:text-purple-400 font-mono">
                                            {{ __('user.requests.coins_trx', { amount: currentPrice }) }}
                                        </span>
                                        <span
                                            class="px-2 py-0.5 rounded-md text-[10px] font-black border"
                                            :class="currentIsRond
                                                ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30'
                                                : 'bg-slate-200/80 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600'"
                                        >
                                            {{ currentIsRond ? __('user.requests.rond_pattern') : __('user.requests.regular_pattern') }}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-left text-[11px] text-slate-500 dark:text-slate-400">
                                    {{ __('user.requests.length_digits', { length: createForm.requested_length }) }}
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="createStep = 1"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
                                >{{ __('user.requests.back_to_country') }}</button>
                                <button
                                    type="button"
                                    @click="goToStep3"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-1.5 shadow-md shadow-purple-600/25 cursor-pointer"
                                >
                                    <span>{{ __('user.requests.continue_step3') }}</span>
                                    <span>←</span>
                                </button>
                            </div>
                        </div>

                        <!-- ============================================== -->
                        <!-- STEP 3: Details & Non-Political Declaration    -->
                        <!-- ============================================== -->
                        <div v-else-if="createStep === 3" class="space-y-4">
                            <div>
                                <h4 class="font-bold text-xs text-slate-800 dark:text-slate-200">
                                    {{ __('user.requests.step3_title') }}
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ __('user.requests.step3_desc') }}
                                </p>
                            </div>

                            <!-- Service Title -->
                            <div>
                                <label class="form-label">
                                    {{ __('user.requests.service_title_label') }} <span class="text-rose-500">*</span>:
                                </label>
                                <input
                                    type="text"
                                    v-model="createForm.service_title"
                                    :placeholder="__('user.requests.service_title_placeholder')"
                                    required
                                    class="form-input !py-2.5 !text-xs"
                                />
                                <p v-if="createForm.errors.service_title" class="text-xs text-rose-500 font-bold mt-1">
                                    {{ createForm.errors.service_title }}
                                </p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="form-label">
                                    {{ __('user.requests.description_label') }} <span class="text-rose-500">*</span>:
                                </label>
                                <textarea
                                    v-model="createForm.description"
                                    rows="2"
                                    :placeholder="__('user.requests.description_placeholder')"
                                    required
                                    class="form-textarea !py-2 !text-xs resize-none"
                                ></textarea>
                                <p v-if="createForm.errors.description" class="text-xs text-rose-500 font-bold mt-1">
                                    {{ createForm.errors.description }}
                                </p>
                            </div>

                            <!-- Incoming SMS Option -->
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="createForm.accepts_incoming_sms"
                                        class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-white/20 text-purple-600 focus:ring-purple-500/40 cursor-pointer"
                                    />
                                    <div class="space-y-0.5 select-none">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                                            {{ __('user.requests.incoming_sms_label') }}
                                        </span>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                            {{ __('user.requests.incoming_sms_desc') }}
                                        </p>
                                    </div>
                                </label>
                            </div>

                            <!-- Strict Non-Political Agreement Box -->
                            <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-xs space-y-2.5">
                                <div class="flex items-start gap-2 text-amber-800 dark:text-amber-300 font-bold">
                                    <span class="text-base shrink-0 mt-0.5">⚠️</span>
                                    <p class="text-[11px] leading-relaxed">
                                        {{ __('user.requests.commitment_p1') }}
                                    </p>
                                </div>

                                <label class="flex items-start gap-2.5 cursor-pointer pt-2 border-t border-amber-500/20">
                                    <input
                                        type="checkbox"
                                        v-model="politicalTermsAccepted"
                                        required
                                        class="mt-0.5 w-4 h-4 rounded border-amber-400 text-purple-600 focus:ring-purple-500/40 cursor-pointer"
                                    />
                                    <span class="text-[11px] text-slate-800 dark:text-slate-200 font-bold select-none leading-relaxed">
                                        {{ __('user.requests.commitment_checkbox') }}
                                    </span>
                                </label>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="createStep = 2"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
                                >{{ __('user.requests.back_to_number') }}</button>
                                <button
                                    type="button"
                                    @click="goToStep4"
                                    :disabled="!politicalTermsAccepted || !createForm.service_title.trim() || !createForm.description.trim()"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-1.5 shadow-md shadow-purple-600/25 cursor-pointer disabled:opacity-40"
                                >
                                    <span>{{ __('user.requests.continue_step4') }}</span>
                                    <span>←</span>
                                </button>
                            </div>
                        </div>

                        <!-- ============================================== -->
                        <!-- STEP 4: Review, Price & Wallet Payment         -->
                        <!-- ============================================== -->
                        <div v-else-if="createStep === 4" class="space-y-4">
                            <div>
                                <h4 class="font-bold text-xs text-slate-800 dark:text-slate-200">
                                    {{ __('user.requests.step4_title') }}
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ __('user.requests.step4_desc') }}
                                </p>
                            </div>

                            <!-- Order Summary Card -->
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-white/10 text-xs space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.country_prefix_label') }}</span>
                                    <span class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                                        <span>{{ selectedPrefix?.flag }}</span>
                                        <span>{{ selectedPrefix?.country_name || selectedPrefix?.name }}</span>
                                        <span dir="ltr" class="font-mono text-purple-600 dark:text-purple-400 font-bold">({{ selectedPrefix?.prefix }})</span>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.length_prefix_label') }}</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        {{ __('user.requests.digits_unit', { count: createForm.requested_length }) }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.chosen_number_label') }}</span>
                                    <span dir="ltr" class="font-mono font-black text-purple-600 dark:text-purple-400">
                                        {{ createForm.preferred_number ? (selectedPrefix?.prefix + ' - ' + createForm.preferred_number) : __('user.requests.smart_allocation') }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.business_title_label') }}</span>
                                    <span class="font-bold text-slate-800 dark:text-slate-200 truncate max-w-[200px]">
                                        {{ createForm.service_title }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.incoming_label') }}</span>
                                    <span class="font-bold" :class="createForm.accepts_incoming_sms ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500'">
                                        {{ createForm.accepts_incoming_sms ? __('user.requests.incoming_enabled') : __('user.requests.incoming_disabled') }}
                                    </span>
                                </div>
                            </div>

                            <!-- Financial Invoice Card -->
                            <div class="p-4 rounded-2xl bg-purple-500/10 border border-purple-500/25 space-y-2.5 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600 dark:text-slate-300 font-bold">{{ __('user.requests.final_cost_label') }}</span>
                                    <div class="text-left">
                                        <span class="text-base font-black text-purple-600 dark:text-purple-400 font-mono">
                                            {{ __('user.requests.coins_trx', { amount: currentPrice }) }}
                                        </span>
                                        <span class="block text-[10px] text-slate-500">
                                            ({{ currentIsRond ? __('user.requests.rond_tariff') : __('user.requests.regular_tariff') }})
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between pt-2 border-t border-purple-500/15">
                                    <span class="text-slate-600 dark:text-slate-400">{{ __('user.requests.wallet_balance_label') }}</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        {{ userBalance.toFixed(2) }} {{ __('user.wallet.unit') }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-1 border-t border-purple-500/15">
                                    <span class="text-slate-600 dark:text-slate-400">{{ __('user.requests.balance_after_label') }}</span>
                                    <span
                                        class="font-mono font-bold"
                                        :class="hasInsufficientBalance ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'"
                                    >
                                        {{ (userBalance - Number(currentPrice)).toFixed(2) }} {{ __('user.wallet.unit') }}
                                    </span>
                                </div>

                                <div class="pt-1.5 text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed bg-white/70 dark:bg-black/30 p-2.5 rounded-xl border border-purple-500/15">
                                    🔒 {{ __('user.requests.hold_notice', { amount: currentPrice }) }}
                                </div>

                                <div v-if="hasInsufficientBalance" class="pt-1 text-rose-600 dark:text-rose-400 font-bold text-[11px] flex items-center justify-between">
                                    <span>⚠️ {{ __('user.requests.insufficient_balance') }}</span>
                                    <Link :href="route('profile.edit')" class="underline hover:text-rose-700">{{ __('user.requests.recharge_link') }}</Link>
                                </div>
                            </div>

                            <!-- Final Submit Buttons -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="createStep = 3"
                                    :disabled="createForm.processing"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
                                >{{ __('user.requests.back_to_info') }}</button>
                                <button
                                    type="button"
                                    @click="submitCreate"
                                    :disabled="!politicalTermsAccepted || createForm.processing || hasInsufficientBalance"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-6 !text-xs !rounded-2xl inline-flex items-center justify-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed shadow-md shadow-purple-600/25 cursor-pointer"
                                >
                                    <svg
                                        v-if="createForm.processing"
                                        class="animate-spin h-3.5 w-3.5 text-white"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <span>
                                        {{ createForm.processing ? __('user.requests.submitting_payment') : __('user.requests.confirm_payment_btn') }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>

        <!-- 5. Details Modal -->
        <teleport to="body">
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="isDetailsModalOpen && selectedRequest"
                    class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                    @click.self="closeDetailsModal"
                >
                    <div
                        class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                    >
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 dark:border-white/10">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-2xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                                    🔍
                                </div>
                                <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('user.requests.modal_details_title', { id: selectedRequest.id }) }}</h3>
                            </div>
                            <button
                                type="button"
                                @click="closeDetailsModal"
                                class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors"
                            >
                                ✕
                            </button>
                        </div>

                        <div class="space-y-3 text-xs">
                            <div v-if="selectedRequest.prefix" class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.detail_prefix_country') }}</span>
                                <span class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                    <span>{{ selectedRequest.prefix.flag }}</span>
                                    <span>{{ selectedRequest.prefix.country_name }}</span>
                                    <span dir="ltr" class="font-mono text-purple-600 font-black">({{ selectedRequest.prefix.prefix }})</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.detail_title') }}</span>
                                <span class="font-bold text-slate-900 dark:text-white">{{ selectedRequest.service_title }}</span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.detail_length') }}</span>
                                <span class="font-mono font-bold text-purple-600 dark:text-purple-400">{{ __('user.requests.digits_unit', { count: selectedRequest.requested_length }) }}</span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.detail_incoming') }}</span>
                                <span
                                    class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border"
                                    :class="selectedRequest.accepts_incoming_sms ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 border-slate-500/30 text-slate-500'"
                                >
                                    {{ selectedRequest.accepts_incoming_sms ? __('user.requests.detail_incoming_twoway') : __('user.requests.detail_incoming_oneway') }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.detail_status') }}</span>
                                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border" :class="selectedRequest.status_badge">
                                    {{ selectedRequest.status_label }}
                                </span>
                            </div>
                            <div>
                                <span class="text-slate-500 dark:text-slate-400 block mb-1 font-semibold">{{ __('user.requests.detail_description') }}</span>
                                <p class="p-3 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300 leading-relaxed text-xs">
                                    {{ selectedRequest.description }}
                                </p>
                            </div>
                            <div v-if="selectedRequest.reviewer_name" class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">{{ __('user.requests.detail_reviewer') }}</span>
                                <span class="font-bold text-slate-900 dark:text-white">{{ selectedRequest.reviewer_name }}</span>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="closeDetailsModal"
                                class="px-5 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                            >{{ __('user.requests.close') }}</button>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>

        <!-- 6. Cancel Confirmation Modal -->
        <teleport to="body">
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="isCancelModalOpen"
                    class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                    @click.self="closeCancelModal"
                >
                    <div
                        class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 text-center bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                    >
                        <div class="w-12 h-12 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 mx-auto flex items-center justify-center text-lg">
                            ⚠️
                        </div>

                        <div class="space-y-1.5">
                            <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('user.requests.cancel_modal_title') }}</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('user.requests.cancel_modal_desc') }}
                            </p>
                        </div>

                        <div class="flex items-center justify-center gap-2.5 pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="closeCancelModal"
                                :disabled="cancelProcessing"
                                class="px-5 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                            >{{ __('user.requests.cancel') }}</button>
                            <button
                                type="button"
                                @click="confirmCancel"
                                :disabled="cancelProcessing"
                                class="px-5 py-2.5 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/30 transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                            >
                                <svg
                                    v-if="cancelProcessing"
                                    class="animate-spin h-3.5 w-3.5 text-white"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>{{ cancelProcessing ? __('user.requests.canceling') : __('user.requests.confirm_cancel') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>

        <!-- 7. Dedicated Enterprise Multi-Service Order Modal -->
        <div
            v-if="isEnterpriseServiceModalOpen && activeEnterpriseService"
            class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/75 backdrop-blur-md"
            @click.self="closeEnterpriseServiceModal"
        >
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-lg shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-amber-500/30 text-slate-900 dark:text-white rounded-3xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <div>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-500">
                            {{ __('catalog.ui.enterprise_exclusive_badge') }}
                        </span>
                        <h3 class="font-black text-sm mt-1">{{ activeEnterpriseService.enterprise_title }}</h3>
                    </div>
                    <button type="button" @click="closeEnterpriseServiceModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-white">✕</button>
                </div>

                <form @submit.prevent="submitEnterpriseServiceOrder" class="space-y-4">
                    <div v-if="activeEnterpriseServiceKey === 'custom_id'" class="space-y-2">
                        <label class="form-label">{{ __('catalog.schemas.custom_id.handle_label') }}</label>
                        <div class="flex items-center gap-2" dir="ltr">
                            <span class="px-3 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 font-mono font-black text-indigo-500">@</span>
                            <input
                                type="text"
                                v-model="entOrderForm.handle"
                                @input="entValidationResult = null"
                                placeholder="gold"
                                class="form-input !py-2.5 !text-xs font-mono flex-1"
                            />
                        </div>
                        <p class="text-[11px] text-slate-500">{{ __('catalog.services.custom_id.enterprise_desc') }}</p>
                    </div>

                    <div v-if="activeEnterpriseServiceKey === 'web3_email_package'" class="space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <div class="sm:col-span-2">
                                <label class="form-label">{{ __('catalog.schemas.web3_email.base_domain_label') }}</label>
                                <input
                                    type="text"
                                    dir="ltr"
                                    v-model="entOrderForm.base_domain"
                                    @input="entValidationResult = null"
                                    placeholder="gld"
                                    class="form-input !py-2.5 !text-xs font-mono"
                                />
                            </div>
                            <div>
                                <label class="form-label">{{ __('catalog.schemas.web3_email.tld_label') }}</label>
                                <select dir="ltr" v-model="entOrderForm.tld" class="form-select !py-2.5 !text-xs font-mono w-full">
                                    <option value="dao">.dao</option>
                                    <option value="org3">.org3</option>
                                    <option value="corp">.corp</option>
                                    <option value="web3">.web3</option>
                                    <option value="trx">.trx</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="form-label">{{ __('catalog.schemas.web3_email.plan_code_label') }}</label>
                            <select v-model="entOrderForm.plan_code" class="form-select !py-2.5 !text-xs w-full">
                                <option
                                    v-for="plan in activeEnterpriseService.plans"
                                    :key="plan.code"
                                    :value="plan.code"
                                >
                                    {{ plan.title }} — {{ plan.final_price }} TRX ({{ plan.quota_units }} Quota)
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">{{ __('catalog.schemas.web3_email.initial_sub_accounts_label') }}</label>
                            <input
                                type="text"
                                dir="ltr"
                                v-model="entOrderForm.initial_sub_accounts"
                                class="form-input !py-2 !text-xs font-mono"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="form-label">{{ __('catalog.schemas.common.display_title_label') }}</label>
                        <input
                            type="text"
                            v-model="entOrderForm.display_title"
                            :placeholder="__('catalog.schemas.common.display_title_placeholder')"
                            class="form-input !py-2 !text-xs"
                        />
                    </div>

                    <div class="flex items-center justify-between">
                        <button
                            type="button"
                            @click="validateEnterpriseOrder"
                            :disabled="entValidationLoading"
                            class="glass-pill !py-2 !px-4 !text-xs font-bold text-amber-600 dark:text-amber-400 border border-amber-500/30"
                        >
                            {{ entValidationLoading ? __('catalog.ui.validating') : __('catalog.ui.validate_button') }}
                        </button>
                    </div>

                    <div
                        v-if="entValidationResult"
                        class="p-3.5 rounded-2xl border text-xs space-y-2"
                        :class="entValidationResult.can_proceed
                            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-200'
                            : 'bg-rose-500/10 border-rose-500/30 text-rose-800 dark:text-rose-200'"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-black text-sm" dir="ltr">{{ entValidationResult.normalized_identifier }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/20">{{ entValidationResult.tier_label }}</span>
                        </div>
                        <div v-if="entValidationResult.errors?.length" class="space-y-1">
                            <p v-for="(err, i) in entValidationResult.errors" :key="i" class="text-[11px] font-bold text-rose-500">• {{ err }}</p>
                        </div>
                        <div v-else class="space-y-1 pt-1 border-t border-emerald-500/20">
                            <div class="flex justify-between">
                                <span>{{ __('catalog.ui.unit_price') }}</span>
                                <span class="font-mono font-bold" dir="ltr">{{ entValidationResult.unit_price }} TRX</span>
                            </div>
                            <div v-if="Number(entValidationResult.discount_amount) > 0" class="flex justify-between text-amber-500">
                                <span>{{ __('catalog.ui.enterprise_discount', { percent: entValidationResult.metadata?.discount_percent || 0 }) }}</span>
                                <span class="font-mono font-bold" dir="ltr">-{{ entValidationResult.discount_amount }} TRX</span>
                            </div>
                            <div class="flex justify-between font-black text-sm pt-1">
                                <span>{{ __('catalog.ui.final_payable') }}</span>
                                <span class="font-mono" dir="ltr">{{ entValidationResult.final_amount }} TRX</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeEnterpriseServiceModal" class="glass-pill !py-2 !px-4 !text-xs">
                            {{ __('user.requests.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="entOrderForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl"
                        >
                            {{ entOrderForm.processing ? __('catalog.ui.processing_order') : __('catalog.ui.submit_enterprise_order') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </UserLayout>
</template>
