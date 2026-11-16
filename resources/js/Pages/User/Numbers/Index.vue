<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import UserLayout from '@/Layouts/UserLayout.vue';
import AllocateNumberModal, { type PrefixItem } from '@/Components/AllocateNumberModal.vue';
import VoiceCallModal from '@/Components/VoiceCallModal.vue';
import { __ } from '@/Composables/useTrans';

interface PhoneNumberItem {
    id: number;
    number: string;
    formatted_number: string;
    effective_title: string;
    display_title: string | null;
    rond_tier?: string;
    rond_tier_label?: string;
    rond_pattern?: string | null;
    type: string;
    type_label: string;
    type_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    allocated_at: string | null;
    created_at: string;
}

interface SubResourceItem {
    id: number;
    sub_identifier: string;
    full_address: string;
    display_name: string | null;
    status: string;
}

interface ServiceAllocationItem {
    id: number;
    uuid: string;
    service_key: 'phone_line' | 'custom_id' | 'web3_email_package' | string;
    service_title: string;
    channel_type: string;
    resource_identifier: string;
    display_title: string;
    account_tier: string;
    tier_badge: string;
    tier_label: string;
    total_quota: number;
    used_quota: number;
    remaining_quota: number;
    phone_number_id: number | null;
    capabilities: Record<string, boolean>;
    metadata: Record<string, any>;
    sub_resources: SubResourceItem[];
    allocated_at: string | null;
}

interface ServicePlanItem {
    id: number;
    code: string;
    title: string;
    description: string;
    target_tier: string;
    billing_mode: string;
    base_price: string;
    final_price: string;
    discount_percent: number;
    quota_units: number;
    metadata: Record<string, any>;
}

interface ServiceCatalogItem {
    id: number;
    key: string;
    category: string;
    channel_type: string;
    icon: string;
    title: string;
    short_title: string;
    subtitle: string;
    description: string;
    enterprise_title: string;
    enterprise_desc: string;
    unit_label: string;
    is_active: boolean;
    is_purchasable: boolean;
    requires_approval_for_enterprise: boolean;
    available_for_roles: string[];
    is_authorized_for_user: boolean;
    active_policy: Record<string, any>;
    tier_policies: {
        individual: Record<string, any>;
        enterprise: Record<string, any>;
    };
    form_schema: {
        version: string;
        fields: Array<Record<string, any>>;
    };
    plans: ServicePlanItem[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedData<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    current_page: number;
    last_page: number;
}

const props = defineProps<{
    numbers: PaginatedData<PhoneNumberItem>;
    service_catalog?: ServiceCatalogItem[];
    service_allocations?: ServiceAllocationItem[];
    account_tier?: 'individual' | 'enterprise' | string;
    organization_name?: string | null;
    filters: {
        search?: string;
        type?: string;
        service_key?: string;
    };
    quota: {
        active: number;
        max: number;
    };
    wallet?: {
        balance: string;
        number_price_coin?: string;
    };
    default_prefix?: string;
    active_prefixes?: PrefixItem[];
}>();

const activeTab = ref<'all' | 'phone_line' | 'custom_id'>('all');

// Modals State for Voice Dialer and Custom ID Allocation
const isVoiceModalOpen = ref(false);
const activeDialerLines = computed(() => {
    return props.numbers.data.map((n) => ({
        id: n.id,
        number: n.number,
        formatted_number: n.formatted_number,
        display_title: n.display_title || n.effective_title || '',
    }));
});

const openVoiceDialer = () => {
    isVoiceModalOpen.value = true;
};

const openVoiceDialerWithLine = (_line?: any) => {
    isVoiceModalOpen.value = true;
};

const allocations = computed(() => props.service_allocations || []);
const customIdAllocations = computed(() => allocations.value.filter((a) => a.service_key === 'custom_id'));

const isPhoneLinePurchasable = computed(() => {
    const srv = props.service_catalog?.find(s => s.key === 'phone_line');
    return srv ? (srv.is_active !== false && srv.is_purchasable !== false) : true;
});

const isCustomIdPurchasable = computed(() => {
    const srv = props.service_catalog?.find(s => s.key === 'custom_id');
    return srv ? (srv.is_active !== false && srv.is_purchasable !== false) : true;
});

// Search & Filter state
const search = ref(props.filters.search || '');
const typeFilter = ref(props.filters.type || '');

const filteredCustomIds = computed(() => {
    let list = customIdAllocations.value;
    if (search.value.trim()) {
        const q = search.value.toLowerCase().trim().replace(/^@/, '');
        list = list.filter((item) => {
            const idMatch = item.resource_identifier.toLowerCase().replace(/^@/, '').includes(q);
            const titleMatch = item.display_title ? item.display_title.toLowerCase().includes(q) : false;
            return idMatch || titleMatch;
        });
    }
    return list;
});

const handleFilter = () => {
    router.get(
        route('numbers.index'),
        {
            search: search.value || undefined,
            type: typeFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const clearFilter = () => {
    search.value = '';
    typeFilter.value = '';
    handleFilter();
};

// Legacy 9-Digit Number Allocation Modal State
const isAllocateModalOpen = ref(false);
const openAllocateModal = () => {
    isAllocateModalOpen.value = true;
};
const closeAllocateModal = () => {
    isAllocateModalOpen.value = false;
};

// Dedicated Custom ID Modal State
const isCustomIdModalOpen = ref(false);
const customIdHandle = ref('');
const customIdDisplayTitle = ref('');
const customIdValidation = ref<{
    loading: boolean;
    valid: boolean;
    available?: boolean;
    message?: string;
    rarity_tier?: string;
    rarity_label?: string;
    rarity_badge?: string;
    price?: string;
}>({ loading: false, valid: false });
const customIdSubmitting = ref(false);
const customIdError = ref('');

let customIdTimer: ReturnType<typeof setTimeout> | null = null;
const validateCustomIdInput = () => {
    if (customIdTimer) clearTimeout(customIdTimer);
    const clean = customIdHandle.value.replace(/^@/, '').trim();
    if (!clean) {
        customIdValidation.value = { loading: false, valid: false };
        return;
    }
    if (clean.length < 6) {
        customIdValidation.value = {
            loading: false,
            valid: false,
            available: false,
            message: __('user.numbers.custom_id_min_length'),
        };
        return;
    }
    customIdValidation.value.loading = true;
    customIdTimer = setTimeout(async () => {
        try {
            const res = await axios.post(route('api.validate.custom_id'), {
                handle: clean,
                context: 'services',
            });
            customIdValidation.value = {
                loading: false,
                valid: Boolean(res.data.available),
                available: Boolean(res.data.available),
                message: res.data.message,
                rarity_tier: res.data.rarity_tier,
                rarity_label: res.data.rarity_label,
                rarity_badge: res.data.rarity_badge,
                price: res.data.price,
            };
        } catch (e: any) {
            customIdValidation.value = {
                loading: false,
                valid: false,
                available: false,
                message: e.response?.data?.message || __('user.numbers.custom_id_validation_error'),
            };
        }
    }, 250);
};

const openCustomIdModal = () => {
    customIdHandle.value = '';
    customIdDisplayTitle.value = '';
    customIdValidation.value = { loading: false, valid: false };
    customIdError.value = '';
    isCustomIdModalOpen.value = true;
};

const closeCustomIdModal = () => {
    isCustomIdModalOpen.value = false;
};

const submitCustomIdOrder = () => {
    if (!customIdValidation.value.valid || customIdSubmitting.value) return;
    customIdSubmitting.value = true;
    customIdError.value = '';

    const clean = customIdHandle.value.replace(/^@/, '').trim();
    router.post(route('services.orders.store'), {
        service_key: 'custom_id',
        order_context: 'services',
        payload: {
            service_key: 'custom_id',
            handle: clean,
            display_title: customIdDisplayTitle.value || `@${clean}`,
        },
    }, {
        preserveScroll: true,
        onSuccess: () => {
            customIdSubmitting.value = false;
            closeCustomIdModal();
            showToast(__('user.numbers.custom_id_registered_success'));
        },
        onError: (errs) => {
            customIdSubmitting.value = false;
            customIdError.value = Object.values(errs)[0] || __('user.numbers.custom_id_submit_error');
        },
    });
};

const releaseServiceAllocation = (allocation: ServiceAllocationItem) => {
    router.delete(route('services.allocations.destroy', allocation.uuid), {
        preserveScroll: true,
    });
};

// Edit Title Modal State
const isEditTitleModalOpen = ref(false);
const selectedNumberForTitle = ref<PhoneNumberItem | null>(null);
const titleForm = useForm({
    display_title: '',
});

const openEditTitleModal = (item: PhoneNumberItem) => {
    selectedNumberForTitle.value = item;
    titleForm.display_title = item.display_title || '';
    isEditTitleModalOpen.value = true;
};

const closeEditTitleModal = () => {
    isEditTitleModalOpen.value = false;
    selectedNumberForTitle.value = null;
    titleForm.reset();
};

const submitTitleUpdate = () => {
    if (!selectedNumberForTitle.value) return;
    titleForm.put(route('numbers.update', selectedNumberForTitle.value.id), {
        onSuccess: () => closeEditTitleModal(),
    });
};

// Release Modal State
const isReleaseModalOpen = ref(false);
const numberToRelease = ref<PhoneNumberItem | null>(null);
const releaseProcessing = ref(false);

const openReleaseModal = (item: PhoneNumberItem) => {
    numberToRelease.value = item;
    isReleaseModalOpen.value = true;
};

const closeReleaseModal = () => {
    isReleaseModalOpen.value = false;
    numberToRelease.value = null;
};

const confirmRelease = () => {
    if (!numberToRelease.value) return;

    releaseProcessing.value = true;
    router.delete(route('numbers.destroy', numberToRelease.value.id), {
        onFinish: () => {
            releaseProcessing.value = false;
            closeReleaseModal();
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

const copyText = (text: string) => {
    navigator.clipboard.writeText(text);
    showToast(__('catalog.ui.copied'));
};

const copyNumber = (item: PhoneNumberItem) => {
    navigator.clipboard.writeText(item.formatted_number);
    showToast(__('user.numbers.copied', { number: item.formatted_number }));
};

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isEditTitleModalOpen.value) closeEditTitleModal();
        if (isReleaseModalOpen.value) closeReleaseModal();
        if (isAllocateModalOpen.value) closeAllocateModal();
        if (isCustomIdModalOpen.value) closeCustomIdModal();
        if (isVoiceModalOpen.value) isVoiceModalOpen.value = false;
    }
};

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <Head :title="__('catalog.module_title')" />

    <UserLayout>
        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4 scale-95" enter-to-class="opacity-100 translate-y-0 scale-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 -translate-y-2 scale-95">
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
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
            <!-- 1. Multi-Service Platform Overview Banner -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span class="wallet-title">{{ __('catalog.module_title') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            class="wallet-chip"
                            :class="account_tier === 'enterprise'
                                ? 'bg-amber-500/15 border-amber-500/35 text-amber-600 dark:text-amber-400'
                                : 'bg-indigo-500/15 border-indigo-500/35 text-indigo-600 dark:text-indigo-400'"
                        >
                            {{ account_tier === 'enterprise' ? __('catalog.tiers.enterprise_active_badge') : __('catalog.tiers.individual_active_badge') }}
                        </span>
                    </div>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {{ __('catalog.module_subtitle') }}
                </p>

                <!-- 4 Multi-Service KPI Summary Pills (2x2 Grid for UserLayout width) -->
                <div class="grid grid-cols-2 gap-2.5 mt-4">
                    <div class="p-3.5 rounded-2xl bg-slate-100/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-white/10 flex items-center justify-between">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">{{ __('catalog.ui.phone_lines_count') }}</div>
                            <div class="text-lg font-black text-slate-900 dark:text-white mt-0.5" dir="ltr">
                                {{ quota.active }} <span class="text-xs font-normal text-slate-400">/ {{ quota.max }}</span>
                            </div>
                        </div>
                        <div class="w-9 h-9 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                    </div>
                    <div class="p-3.5 rounded-2xl bg-slate-100/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-white/10 flex items-center justify-between">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">{{ __('catalog.ui.custom_ids_count') }}</div>
                            <div class="text-lg font-black text-indigo-600 dark:text-indigo-400 mt-0.5" dir="ltr">
                                {{ customIdAllocations.length }}
                            </div>
                        </div>
                        <div class="w-9 h-9 rounded-xl bg-blue-500/15 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 font-mono font-black text-sm">
                            @
                        </div>
                    </div>
                </div>

                <!-- Quick Action Bar -->
                
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 pt-3 border-t border-slate-200/80 dark:border-white/10">

                    <span class="text-[11px] text-slate-600 dark:text-slate-400 whitespace-nowrap">
                        {{ __('user.numbers.system_prefix') }}
                        <strong class="font-mono text-indigo-600 dark:text-indigo-400 font-black" dir="ltr">{{ default_prefix || '+98' }}</strong>
                    </span>

                    <div class="flex flex-nowrap items-center gap-2 overflow-x-auto">

                        <button
                            type="button"
                            @click="openAllocateModal"
                            :disabled="quota.active >= quota.max || !isPhoneLinePurchasable"
                            class="glass-btn-primary !w-auto !py-2 !px-3.5 !text-xs !rounded-xl flex items-center gap-1.5 disabled:opacity-40 shrink-0 whitespace-nowrap"
                        >
                            <span>+ {{ __('user.numbers.allocate_new') }}</span>
                        </button>

                        <button
                            type="button"
                            @click="openCustomIdModal"
                            :disabled="!isCustomIdPurchasable"
                            class="glass-pill !py-2 !px-3.5 !text-xs !rounded-xl font-bold text-indigo-600 dark:text-indigo-300 border border-indigo-500/30 hover:bg-indigo-500/15 disabled:opacity-40 shrink-0 whitespace-nowrap"
                        >
                            <span>+ {{ __('user.numbers.register_custom_id') }}</span>
                        </button>

                        <Link
                            :href="route('calls.index')"
                            class="glass-pill !py-2 !px-3.5 !text-xs !rounded-xl font-bold text-emerald-600 dark:text-emerald-300 border border-emerald-500/30 hover:bg-emerald-500/15 flex items-center gap-1.5 shrink-0 whitespace-nowrap"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>{{ __('user.numbers.voice_dialer') }}</span>
                        </Link>

                    </div>
                </div>

            </div>

            <!-- 2. Registered Multi-Service Catalog Cards (Clean Bespoke Cards for Phone Lines & Custom IDs) -->
            <div class="grid grid-cols-1 gap-3.5">
                <!-- Service 1: Phone Lines -->
                <div class="glass-card !p-4 flex flex-col justify-between space-y-3.5 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 hover:border-indigo-500/40 transition-all shadow-xs">
                    <div class="flex items-start gap-3.5">
                        <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 border bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/25">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0 space-y-1.5">
                            <h3 class="text-sm font-black text-slate-900 dark:text-white">
                                {{ __('user.numbers.card_phone_title') }}
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                {{ __('user.numbers.card_phone_desc') }}
                            </p>
                        </div>
                    </div>

                    <div class="pt-2.5 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-between gap-2">
                        <span class="text-[11px] font-mono text-slate-500 dark:text-slate-400">
                            {{ __('user.numbers.valid_prefix') }} <strong class="text-indigo-600 dark:text-indigo-400 font-bold" dir="ltr">{{ default_prefix || '+85' }}</strong>
                        </span>
                        <button
                            type="button"
                            @click="openAllocateModal"
                            :disabled="quota.active >= quota.max || !isPhoneLinePurchasable"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl disabled:opacity-40"
                        >
                            {{ isPhoneLinePurchasable ? ('+ ' + __('user.numbers.allocate_new')) : __('user.numbers.temporarily_disabled') }}
                        </button>
                    </div>
                </div>

                <!-- Service 2: Custom IDs -->
                <div class="glass-card !p-4 flex flex-col justify-between space-y-3.5 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 hover:border-indigo-500/40 transition-all shadow-xs">
                    <div class="flex items-start gap-3.5">
                        <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 border bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/25 font-mono font-black text-lg">
                            @
                        </div>
                        <div class="flex-1 min-w-0 space-y-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-black text-slate-900 dark:text-white">
                                    {{ __('user.numbers.card_custom_id_title') }}
                                </h3>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30 shrink-0">
                                    {{ __('user.numbers.min_6_chars_badge') }}
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                {{ __('user.numbers.card_custom_id_desc') }}
                            </p>
                        </div>
                    </div>

                    <div class="pt-2.5 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-between gap-2">
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold">
                            {{ __('user.numbers.no_sim_required') }}
                        </span>
                        <button
                            type="button"
                            @click="openCustomIdModal"
                            :disabled="!isCustomIdPurchasable"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl disabled:opacity-40"
                        >
                            {{ isCustomIdPurchasable ? ('+ ' + __('user.numbers.register_custom_id')) : __('user.numbers.temporarily_disabled') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. Category Tabs (Strictly 2 Standard User Services: Lines & IDs) -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="activeTab = 'all'"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'all' ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-300'"
                >
                    {{ __('catalog.tabs.all_resources') }} ({{ numbers.total + customIdAllocations.length }})
                </button>
                <button
                    type="button"
                    @click="activeTab = 'phone_line'"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'phone_line' ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-300'"
                >
                    {{ __('catalog.tabs.phone_line') }} ({{ numbers.total }})
                </button>
                <button
                    type="button"
                    @click="activeTab = 'custom_id'"
                    class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'custom_id' ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-300'"
                >
                    {{ __('catalog.tabs.custom_id') }} ({{ customIdAllocations.length }})
                </button>
            </div>

            <!-- 4. Unified Search & Filter Bar (Placed Directly Under Tabs) -->
            <div class="glass-card !p-3 flex items-center gap-2 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="relative flex-1">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        :placeholder="__('user.numbers.search_placeholder')"
                        class="glass-input !py-2 !pr-8 !pl-3 !text-xs !rounded-xl w-full bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white"
                    />
                    <svg class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <select
                    v-if="activeTab !== 'custom_id'"
                    v-model="typeFilter"
                    @change="handleFilter"
                    class="form-select !py-2 !text-xs !rounded-xl w-auto"
                >
                    <option value="">{{ __('user.numbers.all_types') }}</option>
                    <option value="standard">{{ __('user.numbers.type_standard') }}</option>
                    <option value="service">{{ __('user.numbers.type_service') }}</option>
                </select>

                <button
                    v-if="search || typeFilter"
                    type="button"
                    @click="clearFilter"
                    class="w-8 h-8 rounded-xl glass-pill !p-0 flex items-center justify-center text-rose-500 hover:bg-rose-500/10"
                    :title="__('user.numbers.clear_filter')"
                >
                    ✕
                </button>
            </div>

            <!-- 5. Unified Resources List (Custom IDs & Phone Numbers) -->
            <div class="space-y-3">
                <!-- Custom IDs Cards -->
                <template v-if="(activeTab === 'all' || activeTab === 'custom_id') && filteredCustomIds.length > 0">
                    <div
                        v-for="alloc in filteredCustomIds"
                        :key="alloc.uuid"
                        class="glass-card !p-4 hover:border-indigo-500/40 transition-all space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30">
                                    {{ __('catalog.services.custom_id.short_title') }}
                                </span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                    {{ alloc.tier_label }}
                                </span>
                                <span class="text-xs font-black text-slate-900 dark:text-white">
                                    {{ alloc.display_title }}
                                </span>
                            </div>
                        </div>

                        <div class="p-3 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 flex items-center justify-between">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-8 h-8 rounded-xl bg-blue-500/15 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 border border-blue-500/25 font-mono font-black text-base">
                                    @
                                </div>
                                <div dir="ltr" class="text-left font-mono font-black text-base text-blue-600 dark:text-blue-400 tracking-wider truncate select-all">
                                    {{ alloc.resource_identifier }}
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="copyText(alloc.resource_identifier)"
                                class="p-2 rounded-xl glass-pill hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                                :title="__('dashboard.copy_label')"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </button>
                        </div>

                        <!-- Dedicated Communications Section for Custom ID -->
                        <div class="mt-3 p-3 rounded-xl bg-blue-50/60 dark:bg-slate-800/50 border border-blue-100 dark:border-white/5">
                            <div class="flex items-center justify-between mb-2.5">
                                <span class="text-xs font-bold text-slate-700 dark:text-slate-200">
                                    {{ __('user.numbers.custom_id_comms_title') }}
                                </span>
                                <span class="text-[10px] text-emerald-500 font-bold">
                                    {{ __('user.numbers.active_online') }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <!-- SMS -->
                                <Link
                                    :href="route('messages.index', { line_key: 'custom_' + alloc.id, custom_id: alloc.resource_identifier })"
                                    class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 hover:border-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors group"
                                >
                                    <span class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                        </svg>
                                        {{ __('user.numbers.sms_btn') }}
                                    </span>

                                    <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-indigo-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 19l-7-7 7-7"/>
                                    </svg>
                                </Link>

                                <!-- Voice -->
                                <Link
                                    :href="route('calls.index', { line_key: 'custom_' + alloc.id, custom_id: alloc.resource_identifier })"
                                    class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 hover:border-emerald-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors group"
                                >
                                    <span class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200 group-hover:text-emerald-600 dark:group-hover:text-emerald-400">
                                        <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                        </svg>
                                        {{ __('user.numbers.voice_call_btn') }}
                                    </span>

                                    <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-emerald-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 19l-7-7 7-7"/>
                                    </svg>
                                </Link>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-xs">
                            <span class="text-[11px] text-slate-400">
                                {{ __('user.numbers.allocated_at', { date: alloc.allocated_at || __('user.numbers.unknown') }) }}
                            </span>
                            <button
                                type="button"
                                @click="releaseServiceAllocation(alloc)"
                                class="text-[11px] font-bold text-rose-500 hover:underline px-2"
                            >
                                {{ __('user.numbers.release_line') }}
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Phone Numbers Cards -->
                <template v-if="(activeTab === 'all' || activeTab === 'phone_line') && numbers.data.length > 0">
                    <div
                        v-for="item in numbers.data"
                        :key="item.id"
                        class="glass-card !p-4 hover:border-indigo-500/40 transition-all space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border shadow-xs" :class="item.type_badge">
                                    {{ item.type_label }}
                                </span>
                                <span
                                    v-if="item.rond_tier && item.rond_tier !== 'regular'"
                                    class="text-[10px] font-bold px-2 py-0.5 rounded-full border"
                                    :class="item.rond_tier === 'golden_rond'
                                        ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                        : 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/30'"
                                >
                                    {{ item.rond_tier_label }}
                                </span>
                                <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                                    {{ item.display_title || __('user.numbers.default_title') }}
                                </span>
                            </div>

                            <button
                                type="button"
                                @click="openEditTitleModal(item)"
                                class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline px-2 py-0.5 rounded-md hover:bg-indigo-50 dark:hover:bg-indigo-950/40 shrink-0"
                            >{{ __('user.numbers.edit_title') }}</button>
                        </div>

                        <div class="p-3 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 flex items-center justify-between">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 border border-indigo-500/25">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                    </svg>
                                </div>
                                <div dir="ltr" class="text-left font-mono font-black text-base text-slate-900 dark:text-white tracking-wider truncate select-all">
                                    {{ item.formatted_number }}
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="copyNumber(item)"
                                class="p-2 rounded-xl glass-pill hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                                :title="__('user.numbers.copy_number')"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </button>
                        </div>

                        <!-- Dedicated Communications Section for Phone Line -->
                        <div class="mt-3 p-3 rounded-xl bg-indigo-50/60 dark:bg-slate-800/50 border border-indigo-100 dark:border-white/5">
                            <div class="flex items-center justify-between mb-2.5">
                                <span class="text-xs font-bold text-slate-700 dark:text-slate-200">
                                    {{ __('user.numbers.line_comms_title') }}
                                </span>
                                <span class="text-[10px] text-slate-400">
                                    {{ item.status_label }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <!-- SMS -->
                                <Link
                                    :href="route('messages.index', { phone_number_id: item.id })"
                                    class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 hover:border-indigo-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors group"
                                >
                                    <span class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                        </svg>
                                        {{ __('user.numbers.sms_btn') }}
                                    </span>

                                    <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-indigo-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 19l-7-7 7-7"/>
                                    </svg>
                                </Link>

                                <!-- Voice -->
                                <Link
                                    :href="route('calls.index', { phone_number_id: item.id })"
                                    class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 hover:border-emerald-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors group"
                                >
                                    <span class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200 group-hover:text-emerald-600 dark:group-hover:text-emerald-400">
                                        <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                                d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                        </svg>
                                        {{ __('user.numbers.voice_call_btn') }}
                                    </span>

                                    <svg class="w-3.5 h-3.5 text-slate-400 group-hover:text-emerald-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 19l-7-7 7-7"/>
                                    </svg>
                                </Link>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-xs">
                            <span class="text-[11px] text-slate-400">
                                {{ __('user.numbers.allocated_at', { date: item.allocated_at || __('user.numbers.unknown') }) }}
                            </span>
                            <button
                                type="button"
                                @click="openReleaseModal(item)"
                                class="text-[11px] font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300 hover:underline transition-colors"
                            >{{ __('user.numbers.release_line') }}</button>
                        </div>
                    </div>

                    <!-- Pagination Links for Numbers -->
                    <div v-if="numbers.links && numbers.links.length > 3" class="flex justify-center gap-1 pt-2">
                        <Link
                            v-for="(link, i) in numbers.links"
                            :key="i"
                            :href="link.url || '#'"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                            :class="[
                                link.active ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-400',
                                !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </template>

                <!-- Empty State -->
                <div
                    v-if="(activeTab === 'all' && filteredCustomIds.length === 0 && numbers.data.length === 0) ||
                          (activeTab === 'custom_id' && filteredCustomIds.length === 0) ||
                          (activeTab === 'phone_line' && numbers.data.length === 0)"
                    class="glass-card !p-8 flex flex-col items-center justify-center text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                >
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">{{ __('user.numbers.no_numbers_found') }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                        {{ __('user.numbers.no_numbers_desc') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- 7. Dedicated Custom ID Registration Modal -->
        <div
            v-if="isCustomIdModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/75 backdrop-blur-md"
            @click.self="closeCustomIdModal"
        >
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-[420px] shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <div class="flex items-center gap-2.5">
                        <span class="w-8 h-8 rounded-xl bg-blue-500/15 text-blue-600 dark:text-blue-400 flex items-center justify-center font-mono font-black text-sm">@</span>
                        <div>
                            <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('user.numbers.register_custom_id') }}</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('user.numbers.custom_id_modal_subtitle') }}</p>
                        </div>
                    </div>
                    <button type="button" @click="closeCustomIdModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-white transition-colors">✕</button>
                </div>

                <form @submit.prevent="submitCustomIdOrder" class="space-y-4">
                    <div>
                        <label class="form-label font-bold text-xs">{{ __('user.numbers.custom_id_handle_label') }}</label>
                        <div class="flex items-center gap-2 mt-1" dir="ltr">
                            <span class="px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 font-mono font-black text-indigo-500 text-sm">@</span>
                            <input
                                type="text"
                                v-model="customIdHandle"
                                @input="validateCustomIdInput"
                                :placeholder="__('user.numbers.custom_id_handle_placeholder')"
                                class="form-input !py-2.5 !text-xs font-mono flex-1"
                                autofocus
                            />
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">{{ __('user.numbers.custom_id_handle_hint') }}</p>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">{{ __('user.numbers.custom_id_display_title_label') }}</label>
                        <input
                            type="text"
                            v-model="customIdDisplayTitle"
                            :placeholder="__('user.numbers.custom_id_display_title_placeholder')"
                            class="form-input !py-2.5 !text-xs mt-1"
                        />
                    </div>

                    <!-- Live Validation Feedback Card -->
                    <div v-if="customIdValidation.loading" class="p-3.5 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-xs text-indigo-600 dark:text-indigo-400 flex items-center gap-2">
                        <span class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full"></span>
                        <span>{{ __('user.numbers.custom_id_checking') }}</span>
                    </div>

                    <div
                        v-else-if="customIdHandle.trim() && customIdValidation.valid !== undefined"
                        class="p-3.5 rounded-2xl border text-xs space-y-2"
                        :class="customIdValidation.valid
                            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-200'
                            : 'bg-rose-500/10 border-rose-500/30 text-rose-800 dark:text-rose-200'"
                    >
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-black text-sm" dir="ltr">@{{ customIdHandle.replace(/^@/, '').trim() }}</span>
                            <span
                                v-if="customIdValidation.rarity_label"
                                class="px-2.5 py-0.5 rounded-full text-[10px] font-bold"
                                :class="customIdValidation.rarity_tier === 'bronze' ? 'bg-slate-500/20 text-slate-700 dark:text-slate-300' : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'"
                            >
                                {{ customIdValidation.rarity_label }}
                            </span>
                        </div>

                        <div v-if="customIdValidation.valid" class="space-y-1.5 pt-1 border-t border-emerald-500/20">
                            <div class="text-[11px] text-emerald-600 dark:text-emerald-400 font-bold">
                                ✓ {{ __('user.numbers.custom_id_available') }}
                            </div>
                            <div class="flex items-center justify-between font-bold text-xs pt-1">
                                <span>{{ __('user.numbers.custom_id_cost_label') }}</span>
                                <span class="font-mono text-emerald-600 dark:text-emerald-400 font-black" dir="ltr">
                                    {{ customIdValidation.price || '0' }} TRX
                                </span>
                            </div>
                        </div>

                        <div v-else class="text-[11px] font-bold text-rose-600 dark:text-rose-300">
                            ✗ {{ customIdValidation.message || __('user.numbers.custom_id_unavailable') }}
                        </div>
                    </div>

                    <div v-if="customIdError" class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-xs font-bold text-rose-600 dark:text-rose-300">
                        {{ customIdError }}
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeCustomIdModal" class="glass-pill !py-2 !px-4 !text-xs">
                            {{ __('user.numbers.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="!customIdValidation.valid || customIdSubmitting"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl disabled:opacity-40"
                        >
                            <span v-if="customIdSubmitting">{{ __('user.numbers.submitting') }}</span>
                            <span v-else>{{ __('user.numbers.confirm_pay_wallet') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 9. Legacy 9-Digit Allocate Modal -->
        <AllocateNumberModal
            :show="isAllocateModalOpen"
            :default-prefix="default_prefix"
            :active-prefixes="active_prefixes"
            :remaining-quota="Math.max(0, quota.max - quota.active)"
            :max-quota="quota.max"
            @close="closeAllocateModal"
        />

        <!-- 10. Edit Title Modal -->
        <div v-if="isEditTitleModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeEditTitleModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-[420px] shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">
                        {{ __('user.numbers.modal_edit_title', { number: selectedNumberForTitle?.formatted_number }) }}
                    </h3>
                    <button type="button" @click="closeEditTitleModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-white">✕</button>
                </div>

                <form @submit.prevent="submitTitleUpdate" class="space-y-4">
                    <div>
                        <label class="form-label">{{ __('user.numbers.label_display_title') }}</label>
                        <input
                            type="text"
                            v-model="titleForm.display_title"
                            :placeholder="__('user.numbers.placeholder_title')"
                            maxlength="100"
                            class="form-input !py-2.5 !text-xs"
                            :disabled="titleForm.processing"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeEditTitleModal" :disabled="titleForm.processing" class="glass-pill !py-2 !px-4 !text-xs">{{ __('user.numbers.cancel') }}</button>
                        <button
                            type="submit"
                            :disabled="titleForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl"
                        >
                            <span>{{ titleForm.processing ? __('user.numbers.saving') : __('user.numbers.save_title') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 11. Release Confirmation Modal -->
        <div v-if="isReleaseModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md" @click.self="closeReleaseModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-6 w-full max-w-[420px] shadow-2xl relative space-y-4 text-right bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
                <div class="flex items-center gap-3 pb-3 border-b border-slate-200 dark:border-white/10">
                    <div class="w-10 h-10 rounded-2xl bg-rose-500/15 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('user.numbers.release_modal_heading', { number: numberToRelease?.formatted_number || '' }) }}</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('user.numbers.release_modal_subheading') }}</p>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-500/30 space-y-2 text-xs leading-relaxed text-rose-900 dark:text-rose-200">
                    <div class="font-bold flex items-center gap-1.5 text-rose-600 dark:text-rose-400">
                        <span>⚠️</span>
                        <span>{{ __('user.numbers.release_warning_title') }}</span>
                    </div>
                    <p>
                        {{ __('user.numbers.release_warning_p1') }}
                    </p>
                    <p class="font-semibold text-rose-700 dark:text-rose-300">
                        {{ __('user.numbers.release_warning_p2') }}
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-200 dark:border-white/10">
                    <button type="button" @click="closeReleaseModal" :disabled="releaseProcessing" class="glass-pill !py-2.5 !px-4 !text-xs font-bold">{{ __('user.numbers.cancel') }}</button>
                    <button
                        type="button"
                        @click="confirmRelease"
                        :disabled="releaseProcessing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md transition-all flex items-center gap-1.5"
                    >
                        <span v-if="releaseProcessing" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                        <span>{{ releaseProcessing ? __('user.numbers.releasing') : __('user.numbers.confirm_release_understood') }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 12. Voice Call Modal -->
        <VoiceCallModal
            :is-open="isVoiceModalOpen"
            :available-lines="activeDialerLines"
            @close="isVoiceModalOpen = false"
        />
    </UserLayout>
</template>
