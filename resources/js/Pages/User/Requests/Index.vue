<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

interface AllocatedNumber {
    id: number;
    number: string;
    formatted_number: string;
}

interface NumberRequestItem {
    id: number;
    requested_length: number;
    service_title: string;
    description: string;
    preferred_number: string | null;
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
const availableLengths = [4, 5, 6, 7, 8];
const termsAccepted = ref(false);

const createForm = useForm({
    requested_length: 4,
    service_title: '',
    description: '',
    preferred_number: '',
    accepts_incoming_sms: true,
});

const openCreateModal = () => {
    termsAccepted.value = false;
    createForm.reset();
    createForm.requested_length = 4;
    createForm.accepts_incoming_sms = true;
    createForm.clearErrors();
    isCreateModalOpen.value = true;
};

const closeCreateModal = () => {
    isCreateModalOpen.value = false;
};

const submitCreate = () => {
    if (!termsAccepted.value) return;
    createForm.post(route('requests.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateModalOpen.value = false;
            createForm.reset();
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
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 border border-purple-500/30 text-purple-700 dark:text-purple-400 shrink-0">
                                {{ req.requested_length }} رقمی
                            </span>
                            <span
                                class="text-[10px] font-bold px-2 py-0.5 rounded-full border shrink-0"
                                :class="req.accepts_incoming_sms ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600 dark:text-emerald-400' : 'bg-slate-500/10 border-slate-500/30 text-slate-500'"
                            >
                                {{ req.accepts_incoming_sms ? 'دوطرفه (دریافت فعال)' : 'یک‌طرفه (صرفاً ارسال)' }}
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
            <div v-else class="glass-card !p-8 text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
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
                <button
                    type="button"
                    @click="openCreateModal"
                    class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl inline-flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>ثبت اولین درخواست</span>
                </button>
            </div>
        </div>

        <!-- 4. Create Request Modal -->
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
                        class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                    >
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 dark:border-white/10">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-2xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                                    📋
                                </div>
                                <div>
                                    <h3 class="font-black text-sm text-slate-900 dark:text-white">ثبت درخواست سرشماره جدید</h3>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">بررسی و تخصیص سرشماره‌های خاص ۴ تا ۸ رقمی</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="closeCreateModal"
                                class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors"
                            >
                                ✕
                            </button>
                        </div>

                        <form @submit.prevent="submitCreate" class="space-y-4">
                            <!-- Length Chips -->
                            <div>
                                <label class="form-label">طول شماره درخواستی:</label>
                                <div class="grid grid-cols-5 gap-1.5">
                                    <button
                                        v-for="l in availableLengths"
                                        :key="l"
                                        type="button"
                                        @click="createForm.requested_length = l"
                                        class="py-2 rounded-2xl text-xs font-mono font-bold transition-all border"
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

                            <!-- Service Title -->
                            <div>
                                <label class="form-label">عنوان سرویس / کاربرد:</label>
                                <input
                                    type="text"
                                    v-model="createForm.service_title"
                                    placeholder="مثال: پیامک اطلاع‌رسانی سفارشات"
                                    required
                                    :disabled="createForm.processing"
                                    class="form-input !py-2.5 !text-xs"
                                />
                            </div>

                            <!-- Preferred Number (Optional) -->
                            <div>
                                <label class="form-label">
                                    شماره پیشنهادی
                                    <span class="text-slate-400 dark:text-slate-500 text-[10px] font-normal">(اختیاری)</span>:
                                </label>
                                <input
                                    type="text"
                                    v-model="createForm.preferred_number"
                                    placeholder="مثال: 8888"
                                    dir="ltr"
                                    :disabled="createForm.processing"
                                    class="form-input !py-2.5 !text-xs !font-mono text-left"
                                />
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="form-label">توضیحات و نوع بهره‌برداری:</label>
                                <textarea
                                    v-model="createForm.description"
                                    rows="3"
                                    placeholder="اهداف کاربرد خط را مختصر شرح دهید..."
                                    required
                                    :disabled="createForm.processing"
                                    class="form-textarea !py-2.5 !text-xs resize-none"
                                ></textarea>
                            </div>

                            <!-- Incoming SMS Option -->
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        v-model="createForm.accepts_incoming_sms"
                                        :disabled="createForm.processing"
                                        class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-white/20 text-purple-600 focus:ring-purple-500/40 cursor-pointer"
                                    />
                                    <div class="space-y-0.5 select-none">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                                            قابلیت دریافت پیامک ورودی (خط دوطرفه)
                                        </span>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                                            در صورت فعال بودن، پیامک‌های ارسالی مخاطبان به این سرشماره دریافت شده و در صندوق پیامک‌های ورودی پنل قابل مشاهده خواهد بود.
                                        </p>
                                    </div>
                                </label>
                            </div>

                            <!-- Non-Affiliation Agreement & Terms Box -->
                            <div class="p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200/90 dark:border-amber-500/20 text-xs space-y-2.5">
                                <div class="flex items-start gap-2 text-amber-800 dark:text-amber-200/90">
                                    <svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    <p class="text-[11px] leading-relaxed">
                                        این سامانه یک بستر مستقل ارائه خدمات ارتباطی سازمانی بوده و به هیچ حزب، گروه یا سازمان سیاسی یا دولتی وابسته نیست.
                                    </p>
                                </div>

                                <label class="flex items-start gap-2.5 cursor-pointer pt-1 border-t border-amber-200/60 dark:border-amber-500/15">
                                    <input
                                        type="checkbox"
                                        v-model="termsAccepted"
                                        :disabled="createForm.processing"
                                        required
                                        class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-white/20 text-purple-600 focus:ring-purple-500/40 cursor-pointer"
                                    />
                                    <span class="text-[11px] text-slate-800 dark:text-slate-200 font-bold select-none">
                                        تأیید می‌کنم که خط صرفاً جهت مقاصد قانونی و فنی سازمانی استفاده می‌شود و ضوابط را می‌پذیرم.
                                    </span>
                                </label>
                            </div>

                            <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="closeCreateModal"
                                    :disabled="createForm.processing"
                                    class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                                >
                                    انصراف
                                </button>
                                <button
                                    type="submit"
                                    :disabled="!termsAccepted || createForm.processing"
                                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed shadow-md shadow-purple-600/25"
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
                                        {{ createForm.processing ? 'در حال ثبت درخواست...' : 'ثبت درخواست' }}
                                    </span>
                                </button>
                            </div>
                        </form>
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
