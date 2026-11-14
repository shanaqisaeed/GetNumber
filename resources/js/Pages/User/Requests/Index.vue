<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import OrganizationalOrderWizard from '@/Components/OrganizationalOrderWizard.vue';

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
    id?: number | null;
    number: string;
    length: number;
    rond_tier: string;
    rond_label?: string;
    rond_pattern?: string;
    price?: string;
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

// 3-Step Organizational Order Wizard State
const isOrgWizardOpen = ref(false);
const openOrgWizard = () => {
    isOrgWizardOpen.value = true;
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
        if (isOrgWizardOpen.value) isOrgWizardOpen.value = false;
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
            <!-- 1. Organizational Command Center Header -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                        <span class="wallet-title">{{ __('catalog.enterprise_hub_title') }}</span>
                    </div>
                    <span class="wallet-chip bg-amber-500/20 border-amber-500/40 text-amber-600 dark:text-amber-300">
                        دسترسی عمومی و مستقیم
                    </span>
                </div>

                <div class="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    {{ __('catalog.enterprise_hub_subtitle') }}
                </div>

                <!-- Call to action button to open 3-Step Wizard -->
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-200/80 dark:border-white/10">
                    <div class="text-xs text-slate-500 dark:text-slate-400">
                        موجودی کیف‌پول:
                        <strong class="font-mono text-emerald-600 dark:text-emerald-400 font-bold" dir="ltr">
                            {{ Number(wallet?.balance || 0).toFixed(2) }} TRX
                        </strong>
                    </div>
                    <button
                        type="button"
                        @click="openOrgWizard"
                        class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-xl flex items-center gap-2 shadow-lg shadow-indigo-500/20"
                    >
                        <span>+ ثبت سفارش در ویزارد سازمانی (۳ مرحله‌ای)</span>
                    </button>
                </div>

                <!-- 3 Service Cards with Action into Wizard -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10">
                    <!-- Service 1: Short Lines -->
                    <div class="p-4 rounded-2xl bg-slate-100/90 dark:bg-slate-900/70 border border-purple-500/30 flex flex-col justify-between space-y-3">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="w-9 h-9 rounded-xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                    </svg>
                                </div>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/30">
                                    ۴ الی ۸ رقم
                                </span>
                            </div>
                            <h3 class="text-xs font-black text-slate-900 dark:text-white">خطوط کوتاه و رند سازمانی</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                سرشماره‌های کوتاه با قابلیت ارسال و دریافت پیامک و پیش‌نمایش تماس صوتی.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openOrgWizard"
                            class="glass-pill !py-2 !px-3 !text-xs font-bold text-purple-600 dark:text-purple-400 border border-purple-500/30 text-center hover:bg-purple-500/10"
                        >
                            سفارش خط سازمانی ←
                        </button>
                    </div>

                    <!-- Service 2: Custom IDs -->
                    <div class="p-4 rounded-2xl bg-slate-100/90 dark:bg-slate-900/70 border border-indigo-500/30 flex flex-col justify-between space-y-3">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="w-9 h-9 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center font-mono font-black text-sm">
                                    @
                                </div>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30">
                                    شناسه نایاب
                                </span>
                            </div>
                            <h3 class="text-xs font-black text-slate-900 dark:text-white">آیدی‌های سازمانی و برندینگ</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                آیدی‌های تک‌کلمه‌ای و برند تجاری با ارزش افزوده بالا و رتبه‌بندی نایابی.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openOrgWizard"
                            class="glass-pill !py-2 !px-3 !text-xs font-bold text-indigo-600 dark:text-indigo-400 border border-indigo-500/30 text-center hover:bg-indigo-500/10"
                        >
                            سفارش آیدی اختصاصی ←
                        </button>
                    </div>

                    <!-- Service 3: Web3 Domains -->
                    <div class="p-4 rounded-2xl bg-slate-100/90 dark:bg-slate-900/70 border border-amber-500/30 flex flex-col justify-between space-y-3">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="w-9 h-9 rounded-xl bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/25 flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
                                    </svg>
                                </div>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30" dir="ltr">
                                    .web3 / .dao
                                </span>
                            </div>
                            <h3 class="text-xs font-black text-slate-900 dark:text-white">دامنه‌ها و پکیج وب۳</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                ثبت دامنه غیرمتمرکز، ایجاد صندوق‌های ایمیل زیرمجموعه و مدیریت کووتا.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openOrgWizard"
                            class="glass-pill !py-2 !px-3 !text-xs font-bold text-amber-600 dark:text-amber-400 border border-amber-500/30 text-center hover:bg-amber-500/10"
                        >
                            سفارش دامنه Web3 ←
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
                        @click="openOrgWizard"
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

        <!-- 3-Step Organizational Order Wizard -->
        <OrganizationalOrderWizard
            :is-open="isOrgWizardOpen"
            :wallet-balance="wallet?.balance"
            :active-prefixes="active_prefixes"
            @close="isOrgWizardOpen = false"
        />
    </UserLayout>
</template>
