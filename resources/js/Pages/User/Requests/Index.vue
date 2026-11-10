<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
}>();

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
        createForm.setError('preferred_number', `طول شماره وارد شده باید دقیقاً ${createForm.requested_length} رقم باشد.`);
        return;
    }
    createForm.clearErrors('preferred_number');
    createStep.value = 3;
};

const goToStep4 = () => {
    createForm.clearErrors();
    let hasErr = false;
    if (!createForm.service_title.trim()) {
        createForm.setError('service_title', 'درج عنوان سرشماره یا نام تجاری الزامی است.');
        hasErr = true;
    }
    if (!createForm.description.trim()) {
        createForm.setError('description', 'توضیحات و اهداف استفاده از سرشماره را شرح دهید.');
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
        showToast('شماره در کلیپ‌بورد کپی شد');
    } catch {
        showToast('شماره کپی شد');
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
        <Head title="درخواست‌های سرشماره من" />

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
            <!-- 1. Header Banner & Action -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <span class="wallet-title">درخواست خط تجاری</span>
                    <span class="wallet-chip bg-purple-500/15 border-purple-500/35 text-purple-600 dark:text-purple-400">
                        ۴ تا ۸ رقمی
                    </span>
                </div>

                <div class="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    درخواست تخصیص خطوط تجاری کوتاه (۴ تا ۸ رقمی) جهت سامانه‌ها و پیام‌های تجاری.
                </div>

                <!-- Stats 3-Column Pill Row -->
                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 text-center">
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-amber-600 dark:text-amber-400 block font-bold">در انتظار</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.pending }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 block font-bold">تأییدشده</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.approved }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-rose-600 dark:text-rose-400 block font-bold">ردشده</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.rejected }}</span>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 flex justify-end">
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="glass-btn-primary !w-full !py-2.5 !text-xs !rounded-xl flex items-center justify-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>ثبت درخواست خط تجاری جدید</span>
                    </button>
                </div>
            </div>

            <!-- 2. Search & Filter Bar -->
            <div class="glass-card !p-3 flex items-center gap-2 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="relative flex-1">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        placeholder="جستجو در عنوان یا توضیحات..."
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
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending">در انتظار</option>
                    <option value="approved">تأییدشده</option>
                    <option value="rejected">ردشده</option>
                </select>

                <button
                    v-if="search || statusFilter"
                    type="button"
                    @click="resetFilter"
                    class="w-8 h-8 rounded-xl glass-pill !p-0 flex items-center justify-center text-rose-500 hover:bg-rose-500/10"
                    title="پاکسازی"
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
                                {{ req.requested_length }} رقمی
                            </span>
                            <span
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full border shrink-0"
                                :class="req.accepts_incoming_sms ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 border-slate-500/30 text-slate-500'"
                            >
                                {{ req.accepts_incoming_sms ? 'دوطرفه (دریافت فعال)' : 'یک‌طرفه (صرفاً ارسال)' }}
                            </span>
                            <span
                                v-if="req.held_amount && Number(req.held_amount) > 0"
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-700 dark:text-amber-400 shrink-0"
                            >
                                وجه مسدود: {{ req.held_amount }} کوین
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
                            <span class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400">شماره تخصیص‌یافته:</span>
                            <span dir="ltr" class="font-mono font-black text-xs text-slate-900 dark:text-white">
                                {{ req.allocated_number.formatted_number }}
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="copyToClipboard(req.allocated_number.formatted_number)"
                            class="p-1 text-emerald-600 dark:text-emerald-400 hover:text-emerald-700 dark:hover:text-emerald-300"
                            title="کپی"
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
                        <span class="font-bold">علت عدم تأیید:</span> {{ req.rejection_reason }}
                    </div>

                    <!-- Footer: Date & Actions -->
                    <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-[11px]">
                        <span class="text-slate-500 dark:text-slate-400 font-mono">{{ req.created_at }}</span>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="openDetailsModal(req)"
                                class="font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300"
                            >
                                جزئیات
                            </button>

                            <button
                                v-if="req.status === 'pending'"
                                type="button"
                                @click="openCancelModal(req)"
                                class="font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300"
                            >
                                انصراف
                            </button>
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
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">درخواستی یافت نشد</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                        شما در حال حاضر درخواستی برای سرشماره‌های خاص ثبت نکرده‌اید.
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
                        <span>ثبت اولین درخواست</span>
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
                                    <h3 class="font-black text-sm text-slate-900 dark:text-white">ثبت درخواست سرشماره سازمانی</h3>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">فرآیند ۴ مرحله‌ای تخصیص و فعال‌سازی سرشماره تجاری</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="closeCreateModal"
                                class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer"
                                aria-label="بستن"
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
                                    <span v-else>۱</span>
                                </div>
                                <span :class="createStep === 1 ? 'text-purple-600 dark:text-purple-400 font-black' : (createStep > 1 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">
                                    پیش‌شماره
                                </span>
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
                                    <span v-else>۲</span>
                                </div>
                                <span :class="createStep === 2 ? 'text-purple-600 dark:text-purple-400 font-black' : (createStep > 2 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">
                                    طول و شماره
                                </span>
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
                                    <span v-else>۳</span>
                                </div>
                                <span :class="createStep === 3 ? 'text-purple-600 dark:text-purple-400 font-black' : (createStep > 3 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">
                                    اطلاعات و تعهد
                                </span>
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
                                    <span>۴</span>
                                </div>
                                <span :class="createStep === 4 ? 'text-purple-600 dark:text-purple-400 font-black' : 'text-slate-400'">
                                    پرداخت
                                </span>
                            </div>
                        </div>

                        <!-- ============================================== -->
                        <!-- STEP 1: Country & Prefix Selection             -->
                        <!-- ============================================== -->
                        <div v-if="createStep === 1" class="space-y-4">
                            <div>
                                <h4 class="font-bold text-xs text-slate-800 dark:text-slate-200">
                                    مرحله ۱: تعیین کشور و کد پیش‌شماره
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    لطفاً کشور و کد پیش‌شماره مد نظر برای صدور سرشماره سازمانی را برگزینید:
                                </p>
                            </div>

                            <!-- Search Input -->
                            <div class="relative">
                                <input
                                    type="text"
                                    v-model="countrySearch"
                                    placeholder="جستجوی نام کشور یا پیش‌شماره..."
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
                                    پیش‌شماره‌ای با این مشخصات یافت نشد.
                                </div>
                            </div>

                            <!-- Selected Country Banner & Footer -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <div class="text-xs font-bold text-slate-600 dark:text-slate-400 flex items-center gap-1.5">
                                    <span>انتخاب فعلی:</span>
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
                                    <span>ادامه به انتخاب طول شماره</span>
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
                                        مرحله ۲: انتخاب طول و شماره سازمانی
                                    </h4>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                        طول خط مورد نظر (۴ تا ۸ رقم) را مشخص کرده و شماره دلخواه یا پیشنهادی را برگزینید:
                                    </p>
                                </div>
                                <div class="px-2.5 py-1 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 text-xs font-bold border border-purple-500/20">
                                    {{ selectedPrefix?.flag }} {{ selectedPrefix?.prefix }}
                                </div>
                            </div>

                            <!-- Length Chips -->
                            <div>
                                <label class="form-label">طول سرشماره درخواستی:</label>
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
                                        {{ l }} رقم
                                    </button>
                                </div>
                            </div>

                            <!-- Pool Suggestions for Selected Length -->
                            <div class="p-3.5 rounded-2xl bg-purple-50/60 dark:bg-purple-950/20 border border-purple-200/60 dark:border-purple-500/20 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-black text-purple-800 dark:text-purple-300">
                                        شماره‌های آزاد پیشنهادی در استخر (طول {{ createForm.requested_length }} رقمی):
                                    </span>
                                    <span class="text-[10px] text-purple-600 dark:text-purple-400 font-bold">
                                        {{ poolNumbersForCurrentLength.length }} شماره موجود
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
                                        <span v-if="pool.rond_tier && pool.rond_tier !== 'regular'" class="text-[10px] text-amber-500" title="شماره رند">★</span>
                                    </button>
                                </div>
                                <div v-else class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed py-1">
                                    شماره آماده از پیش تعریف‌شده‌ای برای این طول در استخر وجود ندارد؛ می‌توانید شماره دلخواه خود را در کادر زیر وارد فرمایید تا توسط کارشناسان بررسی شود.
                                </div>
                            </div>

                            <!-- Preferred Number Input -->
                            <div>
                                <label class="form-label flex items-center justify-between">
                                    <span>شماره درخواستی (انتخاب از پیشنهادات یا ورود دستی):</span>
                                    <span class="font-mono text-[10px] text-slate-400 font-bold">
                                        {{ createForm.preferred_number ? createForm.preferred_number.length : 0 }} / {{ createForm.requested_length }} رقم
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
                                        placeholder="مثال: 8888"
                                        dir="ltr"
                                        class="w-full h-10 px-3 rounded-xl bg-white dark:bg-slate-900 font-mono font-black text-sm text-center tracking-widest text-slate-900 dark:text-white border border-slate-200 dark:border-slate-700 focus:border-purple-500 outline-none"
                                    />
                                    <button
                                        v-if="createForm.preferred_number"
                                        type="button"
                                        @click="createForm.preferred_number = ''"
                                        class="w-7 h-7 rounded-lg text-slate-400 hover:text-rose-500 flex items-center justify-center text-xs"
                                        title="پاک کردن شماره"
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
                                    <span class="text-slate-500 dark:text-slate-400 block text-[11px]">تعرفه برآورد شده:</span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="font-black text-sm text-purple-600 dark:text-purple-400 font-mono">
                                            {{ currentPrice }} کوین (TRX)
                                        </span>
                                        <span
                                            class="px-2 py-0.5 rounded-md text-[10px] font-black border"
                                            :class="currentIsRond
                                                ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30'
                                                : 'bg-slate-200/80 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-300 dark:border-slate-600'"
                                        >
                                            {{ currentIsRond ? 'الگوی رند' : 'الگوی عادی' }}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-left text-[11px] text-slate-500 dark:text-slate-400">
                                    طول: <strong class="text-slate-800 dark:text-slate-200">{{ createForm.requested_length }} رقمی</strong>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="createStep = 1"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
                                >
                                    ← مرحله قبل (کشور)
                                </button>
                                <button
                                    type="button"
                                    @click="goToStep3"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-1.5 shadow-md shadow-purple-600/25 cursor-pointer"
                                >
                                    <span>ادامه به مشخصات خط</span>
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
                                    مرحله ۳: اطلاعات هویتی، کاربرد و تعهدنامه
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    مشخصات بهره‌بردار سرشماره و تعهدنامه عدم وابستگی سیاسی را تکمیل نمایید:
                                </p>
                            </div>

                            <!-- Service Title (برای کیه) -->
                            <div>
                                <label class="form-label">
                                    عنوان سرشماره / نام تجاری یا سازمان بهره‌بردار <span class="text-rose-500">*</span>:
                                </label>
                                <input
                                    type="text"
                                    v-model="createForm.service_title"
                                    placeholder="مثال: شرکت تجارت الکترونیک پارس / سامانه اطلاع‌رسانی"
                                    required
                                    class="form-input !py-2.5 !text-xs"
                                />
                                <p v-if="createForm.errors.service_title" class="text-xs text-rose-500 font-bold mt-1">
                                    {{ createForm.errors.service_title }}
                                </p>
                            </div>

                            <!-- Description (توضیحش چیه) -->
                            <div>
                                <label class="form-label">
                                    توضیحات و اهداف بهره‌برداری سازمانی <span class="text-rose-500">*</span>:
                                </label>
                                <textarea
                                    v-model="createForm.description"
                                    rows="2"
                                    placeholder="اهداف استفاده، نوع پیام‌ها و مخاطبان سرشماره را شرح دهید..."
                                    required
                                    class="form-textarea !py-2 !text-xs resize-none"
                                ></textarea>
                                <p v-if="createForm.errors.description" class="text-xs text-rose-500 font-bold mt-1">
                                    {{ createForm.errors.description }}
                                </p>
                            </div>

                            <!-- Incoming SMS Option (میخواد پیامک ورودی داشته باشه یا نه) -->
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="createForm.accepts_incoming_sms"
                                        class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-white/20 text-purple-600 focus:ring-purple-500/40 cursor-pointer"
                                    />
                                    <div class="space-y-0.5 select-none">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                                            قابلیت دریافت پیامک ورودی (خط دوطرفه)
                                        </span>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                            در صورت فعال بودن، پیامک‌های ارسالی کاربران به این سرشماره دریافت و در سامانه قابل مدیریت خواهد بود.
                                        </p>
                                    </div>
                                </label>
                            </div>

                            <!-- Strict Non-Political Agreement Box -->
                            <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-xs space-y-2.5">
                                <div class="flex items-start gap-2 text-amber-800 dark:text-amber-300 font-bold">
                                    <span class="text-base shrink-0 mt-0.5">⚠️</span>
                                    <p class="text-[11px] leading-relaxed">
                                        این سامانه یک بستر کاملاً مستقل و ارائه‌دهنده خدمات فنی-تجاری است. استفاده از سرشماره‌های سازمانی برای هرگونه اهداف حزبی، تشکل‌های سیاسی، تبلیغات جناحی یا مواضع سیاسی اکیداً ممنوع بوده و در صورت احراز، خط فوراً و برای همیشه مسدود خواهد شد.
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
                                        اقرار و تأیید می‌نمایم که خط و سازمان ما جزء هیچ حزب یا سازمان سیاسی نیست و در صورت احراز هرگونه فعالیت سیاسی، خط مسدود شده و حق هیچ‌گونه اعتراضی نخواهم داشت.
                                    </span>
                                </label>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="createStep = 2"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
                                >
                                    ← مرحله قبل (شماره)
                                </button>
                                <button
                                    type="button"
                                    @click="goToStep4"
                                    :disabled="!politicalTermsAccepted || !createForm.service_title.trim() || !createForm.description.trim()"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-1.5 shadow-md shadow-purple-600/25 cursor-pointer disabled:opacity-40"
                                >
                                    <span>ادامه به فاکتور و پرداخت</span>
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
                                    مرحله ۴: فاکتور نهایی و ثبت پرداخت
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    مشخصات نهایی سرشماره را بازبینی کرده و با تایید پرداخت، درخواست خود را ثبت فرمایید:
                                </p>
                            </div>

                            <!-- Order Summary Card -->
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-white/10 text-xs space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500 dark:text-slate-400">کشور و پیش‌شماره:</span>
                                    <span class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1">
                                        <span>{{ selectedPrefix?.flag }}</span>
                                        <span>{{ selectedPrefix?.country_name || selectedPrefix?.name }}</span>
                                        <span dir="ltr" class="font-mono text-purple-600 dark:text-purple-400 font-bold">({{ selectedPrefix?.prefix }})</span>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">طول سرشماره:</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        {{ createForm.requested_length }} رقم
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">شماره برگزیده:</span>
                                    <span dir="ltr" class="font-mono font-black text-purple-600 dark:text-purple-400">
                                        {{ createForm.preferred_number ? (selectedPrefix?.prefix + ' - ' + createForm.preferred_number) : 'تخصیص هوشمند توسط کارشناس' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">عنوان تجاری:</span>
                                    <span class="font-bold text-slate-800 dark:text-slate-200 truncate max-w-[200px]">
                                        {{ createForm.service_title }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10">
                                    <span class="text-slate-500 dark:text-slate-400">پیامک ورودی:</span>
                                    <span class="font-bold" :class="createForm.accepts_incoming_sms ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500'">
                                        {{ createForm.accepts_incoming_sms ? 'فعال (دوطرفه)' : 'غیرفعال' }}
                                    </span>
                                </div>
                            </div>

                            <!-- Financial Invoice Card -->
                            <div class="p-4 rounded-2xl bg-purple-500/10 border border-purple-500/25 space-y-2.5 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600 dark:text-slate-300 font-bold">هزینه قطعی سرشماره:</span>
                                    <div class="text-left">
                                        <span class="text-base font-black text-purple-600 dark:text-purple-400 font-mono">
                                            {{ currentPrice }} کوین (TRX)
                                        </span>
                                        <span class="block text-[10px] text-slate-500">
                                            ({{ currentIsRond ? 'تعرفه خطوط رند' : 'تعرفه خطوط عادی' }})
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between pt-2 border-t border-purple-500/15">
                                    <span class="text-slate-600 dark:text-slate-400">موجودی کیف پول شما:</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        {{ userBalance.toFixed(2) }} کوین
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-1 border-t border-purple-500/15">
                                    <span class="text-slate-600 dark:text-slate-400">مانده پس از ثبت سفارش:</span>
                                    <span
                                        class="font-mono font-bold"
                                        :class="hasInsufficientBalance ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'"
                                    >
                                        {{ (userBalance - Number(currentPrice)).toFixed(2) }} کوین
                                    </span>
                                </div>

                                <div class="pt-1.5 text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed bg-white/70 dark:bg-black/30 p-2.5 rounded-xl border border-purple-500/15">
                                    🔒 <strong>سازوکار مالی مسدودی (Hold):</strong> با کلیک بر روی پرداخت، مبلغ <strong class="text-purple-600 dark:text-purple-400">{{ currentPrice }} TRX</strong> به‌صورت امن در کیف پول شما مسدود می‌شود. در صورت رد درخواست توسط کارشناسان، وجه فوراً و ۱۰۰٪ به موجودی قابل استفاده شما عودت داده می‌شود.
                                </div>

                                <div v-if="hasInsufficientBalance" class="pt-1 text-rose-600 dark:text-rose-400 font-bold text-[11px] flex items-center justify-between">
                                    <span>⚠️ موجودی کیف پول شما برای این سفارش کافی نمی‌باشد.</span>
                                    <Link :href="route('profile.edit')" class="underline hover:text-rose-700">
                                        شارژ حساب ←
                                    </Link>
                                </div>
                            </div>

                            <!-- Final Submit Buttons -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="createStep = 3"
                                    :disabled="createForm.processing"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
                                >
                                    ← مرحله قبل (مشخصات)
                                </button>
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
                                        {{ createForm.processing ? 'در حال ثبت و مسدودی وجه...' : 'پرداخت و ثبت نهایی درخواست' }}
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
                                <h3 class="font-black text-sm text-slate-900 dark:text-white">جزئیات درخواست #{{ selectedRequest.id }}</h3>
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
                                <span class="text-slate-500 dark:text-slate-400">پیش‌شماره / کشور:</span>
                                <span class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                    <span>{{ selectedRequest.prefix.flag }}</span>
                                    <span>{{ selectedRequest.prefix.country_name }}</span>
                                    <span dir="ltr" class="font-mono text-purple-600 font-black">({{ selectedRequest.prefix.prefix }})</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">عنوان:</span>
                                <span class="font-bold text-slate-900 dark:text-white">{{ selectedRequest.service_title }}</span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">طول شماره:</span>
                                <span class="font-mono font-bold text-purple-600 dark:text-purple-400">{{ selectedRequest.requested_length }} رقمی</span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">قابلیت دریافت پیامک:</span>
                                <span
                                    class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border"
                                    :class="selectedRequest.accepts_incoming_sms ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 border-slate-500/30 text-slate-500'"
                                >
                                    {{ selectedRequest.accepts_incoming_sms ? 'دوطرفه (ارسال و دریافت)' : 'یک‌طرفه (صرفاً ارسال)' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">وضعیت:</span>
                                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border" :class="selectedRequest.status_badge">
                                    {{ selectedRequest.status_label }}
                                </span>
                            </div>
                            <div>
                                <span class="text-slate-500 dark:text-slate-400 block mb-1 font-semibold">شرح درخواست:</span>
                                <p class="p-3 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300 leading-relaxed text-xs">
                                    {{ selectedRequest.description }}
                                </p>
                            </div>
                            <div v-if="selectedRequest.reviewer_name" class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-white/5">
                                <span class="text-slate-500 dark:text-slate-400">بررسی‌کننده:</span>
                                <span class="font-bold text-slate-900 dark:text-white">{{ selectedRequest.reviewer_name }}</span>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="closeDetailsModal"
                                class="px-5 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                            >
                                بستن
                            </button>
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
                            <h3 class="font-black text-sm text-slate-900 dark:text-white">لغو درخواست سرشماره</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                آیا از انصراف و حذف این درخواست اطمینان دارید؟ این عمل غیرقابل بازگشت است.
                            </p>
                        </div>

                        <div class="flex items-center justify-center gap-2.5 pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="closeCancelModal"
                                :disabled="cancelProcessing"
                                class="px-5 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                            >
                                انصراف
                            </button>
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
                                <span>{{ cancelProcessing ? 'در حال لغو...' : 'تأیید و لغو درخواست' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>
    </UserLayout>
</template>
