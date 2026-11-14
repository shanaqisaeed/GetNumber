<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import UserLayout from '@/Layouts/UserLayout.vue';
import AllocateNumberModal, { type PrefixItem } from '@/Components/AllocateNumberModal.vue';
import VoiceCallModal from '@/Components/VoiceCallModal.vue';

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

// Search & Filter state
const search = ref(props.filters.search || '');
const typeFilter = ref(props.filters.type || '');

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
    customIdValidation.value.loading = true;
    customIdTimer = setTimeout(async () => {
        try {
            const res = await axios.post(route('api.validate.custom_id'), { handle: clean });
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
                message: e.response?.data?.message || 'خطا در اعتبارسنجی آیدی',
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
            showToast('آیدی اختصاصی با موفقیت ثبت شد.');
        },
        onError: (errs) => {
            customIdSubmitting.value = false;
            customIdError.value = Object.values(errs)[0] || 'خطا در ثبت آیدی';
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
                    <span class="text-[11px] text-slate-600 dark:text-slate-400">
                        {{ __('user.numbers.system_prefix') }}
                        <strong class="font-mono text-indigo-600 dark:text-indigo-400 font-black" dir="ltr">{{ default_prefix || '+98' }}</strong>
                    </span>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="openAllocateModal"
                            :disabled="quota.active >= quota.max"
                            class="glass-btn-primary !w-auto !py-2 !px-3.5 !text-xs !rounded-xl flex items-center gap-1.5 disabled:opacity-40"
                        >
                            <span>+ تخصیص خط جدید</span>
                        </button>
                        <button
                            type="button"
                            @click="openCustomIdModal"
                            class="glass-pill !py-2 !px-3.5 !text-xs !rounded-xl font-bold text-indigo-600 dark:text-indigo-300 border border-indigo-500/30 hover:bg-indigo-500/15"
                        >
                            <span>+ ثبت آیدی اختصاصی</span>
                        </button>
                        <button
                            type="button"
                            @click="openVoiceDialer"
                            class="glass-pill !py-2 !px-3.5 !text-xs !rounded-xl font-bold text-emerald-600 dark:text-emerald-300 border border-emerald-500/30 hover:bg-emerald-500/15 flex items-center gap-1.5"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>شماره‌گیر صوتی (Voice)</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 2. Registered Multi-Service Catalog Cards (Filtered to strictly 2 standard services: Phone Lines & IDs) -->
            <div v-if="service_catalog && service_catalog.length > 0" class="grid grid-cols-1 gap-3">
                <div
                    v-for="service in service_catalog.filter(s => s.key === 'phone_line' || s.key === 'custom_id')"
                    :key="service.key"
                    class="glass-card !p-4 flex flex-col justify-between space-y-3.5 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 hover:border-indigo-500/40 transition-all shadow-xs"
                >
                    <div class="flex items-start gap-3.5">
                        <!-- Service Icon Box -->
                        <div
                            class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 border"
                            :class="{
                                'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/25': service.key === 'phone_line',
                                'bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/25': service.key === 'custom_id',
                                'bg-purple-500/15 text-purple-600 dark:text-purple-400 border-purple-500/25': service.key === 'web3_email_package'
                            }"
                        >
                            <svg v-if="service.key === 'phone_line'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span v-else-if="service.key === 'custom_id'" class="font-mono font-black text-lg">@</span>
                            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
                            </svg>
                        </div>

                        <div class="flex-1 min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-black text-slate-900 dark:text-white">
                                    {{ service.title }}
                                </h3>
                                <span
                                    class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border shrink-0"
                                    :class="service.is_active && service.is_purchasable
                                        ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                                        : 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30'"
                                >
                                    {{ service.is_active && service.is_purchasable ? __('catalog.flags.purchasable') : __('catalog.flags.not_purchasable') }}
                                </span>
                            </div>

                            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                {{ service.description }}
                            </p>

                            <!-- Active Business Policy Chips -->
                            <div class="flex flex-wrap items-center gap-1.5 pt-1">
                                <span
                                    v-if="service.active_policy?.min_length"
                                    class="text-[10px] px-2.5 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold"
                                >
                                    {{ __('catalog.ui.min_id_length_rule', { min: service.active_policy.min_length }) }}
                                </span>
                                <span
                                    v-if="service.plans && service.plans.length > 0"
                                    class="text-[10px] px-2.5 py-0.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 font-mono font-bold"
                                    dir="ltr"
                                >
                                    {{ service.plans[0].final_price }} TRX
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-2.5 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-between gap-2">
                        <Link
                            :href="route('requests.index')"
                            class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-600 dark:text-amber-400 hover:underline"
                        >
                            <span>{{ __('catalog.tiers.enterprise') }}</span>
                            <span>←</span>
                        </Link>
                        <button
                            type="button"
                            @click="service.key === 'phone_line' ? openAllocateModal() : openCustomIdModal()"
                            :disabled="!service.is_active || !service.is_purchasable"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl disabled:opacity-40"
                        >
                            {{ __('catalog.ui.buy_new_service') }}
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

            <!-- 4. Custom IDs (@handle) Section -->
            <div v-if="(activeTab === 'all' || activeTab === 'custom_id') && customIdAllocations.length > 0" class="space-y-3">
                <div
                    v-for="alloc in customIdAllocations"
                    :key="alloc.uuid"
                    class="glass-card !p-4 hover:border-indigo-500/40 transition-all space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                >
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30">
                                {{ __('catalog.services.custom_id.short_title') }}
                            </span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                {{ alloc.tier_label }}
                            </span>
                            <span class="text-xs font-black text-slate-900 dark:text-white">
                                {{ alloc.display_title }}
                            </span>
                        </div>
                        <span class="text-[10px] text-emerald-500 font-bold">{{ __('catalog.ui.channel_ready_phase2') }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 flex items-center justify-between">
                        <div dir="ltr" class="font-mono font-black text-base text-indigo-600 dark:text-indigo-400 select-all">
                            {{ alloc.resource_identifier }}
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="copyText(alloc.resource_identifier)"
                                class="p-2 rounded-xl glass-pill hover:text-indigo-600 text-xs font-bold"
                            >
                                {{ __('dashboard.copy_label') }}
                            </button>
                            <button
                                type="button"
                                @click="releaseServiceAllocation(alloc)"
                                class="text-[11px] font-bold text-rose-500 hover:underline px-2"
                            >
                                {{ __('user.numbers.release_line') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>


            <!-- 6. Communication Lines (Phone Numbers) Search & List -->
            <div v-if="activeTab === 'all' || activeTab === 'phone_line'" class="space-y-3">
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

                <div v-if="numbers.data.length > 0" class="space-y-3">
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

                        <!-- Dedicated Communications Section for each Number -->
                        <div class="mt-3 p-3.5 rounded-2xl bg-indigo-50/70 dark:bg-slate-800/60 border border-indigo-100 dark:border-white/5 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-black text-indigo-950 dark:text-indigo-200 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    بخش ارتباطات و قابلیت‌های خط
                                </span>
                                <span class="text-[11px] font-mono text-slate-500 dark:text-slate-400">
                                    {{ item.status_label }}
                                </span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 text-xs">
                                <!-- SMS Channel -->
                                <div class="flex items-center justify-between p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-white/10">
                                    <div class="flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">💬</span>
                                        <div>
                                            <div class="font-bold text-[11px] text-slate-800 dark:text-slate-200">پیامک دوطرفه (SMS)</div>
                                            <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">ارسال و دریافت فعال</div>
                                        </div>
                                    </div>
                                    <Link
                                        :href="route('messages.index', { phone_number_id: item.id })"
                                        class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-[11px] transition-colors shadow-xs"
                                    >
                                        ورود به پیام‌ها
                                    </Link>
                                </div>

                                <!-- Voice Call Channel -->
                                <div class="flex items-center justify-between p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-white/10">
                                    <div class="flex items-center gap-2">
                                        <span class="w-7 h-7 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">📞</span>
                                        <div>
                                            <div class="font-bold text-[11px] text-slate-800 dark:text-slate-200">تماس صوتی (Voice)</div>
                                            <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">آماده برقراری تماس</div>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="openVoiceDialerWithLine(item)"
                                        class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] transition-colors shadow-xs flex items-center gap-1"
                                    >
                                        <span>تماس صوتی</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-xs">
                            <span class="text-[11px] text-slate-400">
                                تخصیص یافته در: {{ item.allocated_at || 'نامشخص' }}
                            </span>
                            <button
                                type="button"
                                @click="openReleaseModal(item)"
                                class="text-[11px] font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300 hover:underline transition-colors"
                            >{{ __('user.numbers.release_line') }}</button>
                        </div>
                    </div>

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
                </div>

                <div v-else class="glass-card !p-8 flex flex-col items-center justify-center text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
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
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-lg shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <div class="flex items-center gap-2.5">
                        <span class="w-8 h-8 rounded-xl bg-blue-500/15 text-blue-600 dark:text-blue-400 flex items-center justify-center font-mono font-black text-sm">@</span>
                        <div>
                            <h3 class="font-black text-sm text-slate-900 dark:text-white">ثبت آیدی اختصاصی (Custom ID)</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">شناسه کاربری منحصر‌به‌فرد برای ارتباطات و دریافت پیام</p>
                        </div>
                    </div>
                    <button type="button" @click="closeCustomIdModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-white transition-colors">✕</button>
                </div>

                <form @submit.prevent="submitCustomIdOrder" class="space-y-4">
                    <div>
                        <label class="form-label font-bold text-xs">شناسه کاربری درخواستی</label>
                        <div class="flex items-center gap-2 mt-1" dir="ltr">
                            <span class="px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 font-mono font-black text-indigo-500 text-sm">@</span>
                            <input
                                type="text"
                                v-model="customIdHandle"
                                @input="validateCustomIdInput"
                                placeholder="مثلاً: ceo یا alex"
                                class="form-input !py-2.5 !text-xs font-mono flex-1"
                                autofocus
                            />
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">شناسه باید با حروف انگلیسی، اعداد یا زیرخط باشد (حداقل ۳ حرف).</p>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">عنوان نمایشی (اختیاری)</label>
                        <input
                            type="text"
                            v-model="customIdDisplayTitle"
                            placeholder="مثلاً: مدیریت فروش"
                            class="form-input !py-2.5 !text-xs mt-1"
                        />
                    </div>

                    <!-- Live Validation Feedback Card -->
                    <div v-if="customIdValidation.loading" class="p-3.5 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-xs text-indigo-600 dark:text-indigo-400 flex items-center gap-2">
                        <span class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full"></span>
                        <span>در حال استعلام وضعیت آزادی و محاسبه تعرفه آیدی...</span>
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
                                ✓ این آیدی آزاد و قابل تخصیص است.
                            </div>
                            <div class="flex items-center justify-between font-bold text-xs pt-1">
                                <span>هزینه ثبت شناسه:</span>
                                <span class="font-mono text-emerald-600 dark:text-emerald-400 font-black" dir="ltr">
                                    {{ customIdValidation.price || '0' }} TRX
                                </span>
                            </div>
                        </div>

                        <div v-else class="text-[11px] font-bold text-rose-600 dark:text-rose-300">
                            ✗ {{ customIdValidation.message || 'این آیدی در دسترس نیست یا فرمت آن نامعتبر است.' }}
                        </div>
                    </div>

                    <div v-if="customIdError" class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-xs font-bold text-rose-600 dark:text-rose-300">
                        {{ customIdError }}
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeCustomIdModal" class="glass-pill !py-2 !px-4 !text-xs">
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="!customIdValidation.valid || customIdSubmitting"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl disabled:opacity-40"
                        >
                            <span v-if="customIdSubmitting">در حال ثبت...</span>
                            <span v-else>تایید و پرداخت از کیف‌پول</span>
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
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
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
        <div v-if="isReleaseModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeReleaseModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 text-center bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
                <div class="space-y-1">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('user.numbers.modal_release_title') }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ __('user.numbers.release_confirm_text', { number: numberToRelease?.formatted_number }) }}
                    </p>
                </div>

                <div class="flex items-center justify-center gap-2 pt-2">
                    <button type="button" @click="closeReleaseModal" :disabled="releaseProcessing" class="glass-pill !py-2 !px-4 !text-xs">{{ __('user.numbers.cancel') }}</button>
                    <button
                        type="button"
                        @click="confirmRelease"
                        :disabled="releaseProcessing"
                        class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold"
                    >
                        <span>{{ releaseProcessing ? __('user.numbers.releasing') : __('user.numbers.confirm_release') }}</span>
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
