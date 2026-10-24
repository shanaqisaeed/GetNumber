<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';

interface UserInfo {
    id: number;
    name: string;
    username: string;
}

interface AllocatedNumber {
    id: number;
    number: string;
    formatted_number: string;
}

interface NumberRequestItem {
    id: number;
    user: UserInfo;
    requested_length: number;
    service_title: string;
    description: string;
    preferred_number: string | null;
    status: 'pending' | 'approved' | 'rejected';
    status_label: string;
    status_badge: string;
    rejection_reason: string | null;
    allocated_number: AllocatedNumber | null;
    reviewer_name: string | null;
    reviewed_at: string | null;
    created_at: string;
}

interface AvailablePoolNumber {
    id: number;
    number: string;
    length: number;
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
        length?: string;
    };
    stats: {
        total: number;
        pending: number;
        approved: number;
        rejected: number;
    };
    availableServiceNumbers: AvailablePoolNumber[];
}>();

// Filter states
const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const lengthFilter = ref(props.filters.length || '');

const handleFilter = () => {
    router.get(
        route('admin.requests.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            length: lengthFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilter = () => {
    search.value = '';
    statusFilter.value = '';
    lengthFilter.value = '';
    handleFilter();
};

// Review Modal State
const isReviewModalOpen = ref(false);
const activeRequest = ref<NumberRequestItem | null>(null);
const reviewTab = ref<'approve' | 'reject'>('approve');

const approveForm = useForm({
    mode: 'auto' as 'auto' | 'pool' | 'custom',
    custom_number: '',
    pool_number_id: '' as string | number,
});

const rejectForm = useForm({
    reason: '',
});

const openReviewModal = (req: NumberRequestItem) => {
    activeRequest.value = req;
    reviewTab.value = 'approve';

    approveForm.reset();
    approveForm.mode = req.preferred_number ? 'custom' : 'auto';
    approveForm.custom_number = req.preferred_number || '';
    approveForm.pool_number_id = '';
    approveForm.clearErrors();

    rejectForm.reset();
    rejectForm.clearErrors();

    isReviewModalOpen.value = true;
};

const closeReviewModal = () => {
    isReviewModalOpen.value = false;
    activeRequest.value = null;
};

// Filter pool numbers matching current requested length
const filteredPoolNumbers = computed(() => {
    if (!activeRequest.value) return [];
    return props.availableServiceNumbers.filter(
        (p) => p.length === activeRequest.value?.requested_length
    );
});

const submitApprove = () => {
    if (!activeRequest.value) return;

    approveForm.post(route('admin.requests.approve', activeRequest.value.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!page.props.flash?.error && Object.keys(approveForm.errors).length === 0) {
                closeReviewModal();
            }
        },
    });
};

const submitReject = () => {
    if (!activeRequest.value) return;

    rejectForm.post(route('admin.requests.reject', activeRequest.value.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!page.props.flash?.error && Object.keys(rejectForm.errors).length === 0) {
                closeReviewModal();
            }
        },
    });
};

// Details Modal State
const isDetailsModalOpen = ref(false);
const detailsRequest = ref<NumberRequestItem | null>(null);

const openDetailsModal = (req: NumberRequestItem) => {
    detailsRequest.value = req;
    isDetailsModalOpen.value = true;
};

const closeDetailsModal = () => {
    isDetailsModalOpen.value = false;
    detailsRequest.value = null;
};

// Copy feedback
const copiedNumber = ref<string | null>(null);
const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    copiedNumber.value = text;
    setTimeout(() => {
        copiedNumber.value = null;
    }, 2000);
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isReviewModalOpen.value) closeReviewModal();
        if (isDetailsModalOpen.value) closeDetailsModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <AdminLayout>
        <Head title="بررسی درخواست‌های سرشماره سازمانی" />

        <div class="space-y-6">
            <!-- Flash Alerts -->
            <div v-if="$page.props.flash?.success" class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/25 text-xs font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $page.props.flash.success }}</span>
            </div>

            <div v-if="$page.props.flash?.error" class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $page.props.flash.error }}</span>
            </div>

            <!-- Header Section (Liquid Glass) -->
            <div class="glass-card !p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="glass-pill text-[11px] text-purple-600 dark:text-purple-400 font-bold">
                                مدیریت کل خطوط و سرشماره‌ها
                            </span>
                            <span class="glass-pill text-[11px] text-indigo-600 dark:text-indigo-400 font-bold">
                                ۴ الی ۸ رقم
                            </span>
                        </div>
                        <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                            بررسی و تعیین تکلیف درخواست‌های سرشماره سازمانی
                        </h1>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-xl">
                            رسیدگی، تایید با تخصیص خودکار/دستی/استخر و یا رد درخواست‌های سرشماره با درج دلیل سیستمی.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Stats Metric Cards (Liquid Glass) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="glass-card !p-4 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">کل درخواست‌های دریافتی</span>
                        <span class="text-2xl font-black text-slate-900 dark:text-white">{{ stats.total }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                </div>

                <div class="glass-card !p-4 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-amber-600 dark:text-amber-400 block mb-1 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            نیازمند بررسی
                        </span>
                        <span class="text-2xl font-black text-amber-600 dark:text-amber-400">{{ stats.pending }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="glass-card !p-4 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400 block mb-1 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            تأیید و تخصیص یافته
                        </span>
                        <span class="text-2xl font-black text-emerald-600 dark:text-emerald-400">{{ stats.approved }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="glass-card !p-4 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-rose-600 dark:text-rose-400 block mb-1">رد شده با دلیل</span>
                        <span class="text-2xl font-black text-rose-600 dark:text-rose-400">{{ stats.rejected }}</span>
                    </div>
                    <div class="w-11 h-11 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="glass-card !p-4 flex flex-col md:flex-row gap-3 items-center justify-between">
                <div class="w-full md:w-80 relative">
                    <input
                        v-model="search"
                        @keyup.enter="handleFilter"
                        type="text"
                        placeholder="جستجو در نام کاربر، عنوان یا شماره..."
                        class="glass-input !py-2.5 !pr-10 !text-xs"
                    />
                    <svg class="w-4 h-4 text-slate-400 absolute right-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <div class="flex items-center gap-2.5 w-full md:w-auto">
                    <select
                        v-model="statusFilter"
                        @change="handleFilter"
                        class="form-select !py-2 !px-3 !text-xs cursor-pointer"
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option value="pending">در انتظار بررسی</option>
                        <option value="approved">تأیید شده</option>
                        <option value="rejected">رد شده</option>
                    </select>

                    <select
                        v-model="lengthFilter"
                        @change="handleFilter"
                        class="form-select !py-2 !px-3 !text-xs cursor-pointer"
                    >
                        <option value="">همه طول‌ها</option>
                        <option value="4">۴ رقمی</option>
                        <option value="5">۵ رقمی</option>
                        <option value="6">۶ رقمی</option>
                        <option value="7">۷ رقمی</option>
                        <option value="8">۸ رقمی</option>
                    </select>

                    <button
                        v-if="search || statusFilter || lengthFilter"
                        @click="resetFilter"
                        type="button"
                        class="glass-pill !py-2 !px-3 !text-xs hover:text-rose-500 transition-colors cursor-pointer"
                        title="حذف فیلترها"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <!-- Requests Table (Glass Card Container) -->
            <div class="glass-card !p-0 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-100/60 dark:bg-slate-900/60 text-slate-500 dark:text-slate-400 font-bold border-b border-slate-200/80 dark:border-white/10">
                            <tr>
                                <th class="px-5 py-4 text-center w-12">#</th>
                                <th class="px-5 py-4">کاربر متقاضی</th>
                                <th class="px-5 py-4">عنوان و شرح سرویس</th>
                                <th class="px-5 py-4 text-center">طول درخواستی</th>
                                <th class="px-5 py-4 text-center">پیشنهادی / تخصیص‌یافته</th>
                                <th class="px-5 py-4 text-center">وضعیت</th>
                                <th class="px-5 py-4 text-center">تاریخ ثبت</th>
                                <th class="px-5 py-4 text-center">عملیات مدیریت</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/50 dark:divide-white/5">
                            <tr
                                v-for="(req, index) in requests.data"
                                :key="req.id"
                                class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="px-5 py-4 text-center text-xs text-slate-400 font-mono">
                                    {{ (requests.current_page - 1) * 15 + index + 1 }}
                                </td>

                                <td class="px-5 py-4">
                                    <div class="font-bold text-slate-900 dark:text-white">{{ req.user.name }}</div>
                                    <div class="text-[11px] text-slate-400 font-mono">@{{ req.user.username }}</div>
                                </td>

                                <td class="px-5 py-4 font-bold text-slate-900 dark:text-white max-w-xs">
                                    <span>{{ req.service_title }}</span>
                                    <span class="block text-[11px] font-normal text-slate-400 line-clamp-1 mt-0.5">
                                        {{ req.description }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span class="glass-pill text-[10px] font-bold">
                                        {{ req.requested_length }} رقم
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div v-if="req.allocated_number" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-emerald-500/10 border border-emerald-500/25">
                                        <span dir="ltr" class="font-mono text-xs font-black text-emerald-600 dark:text-emerald-400 tracking-wider">
                                            {{ req.allocated_number.number }}
                                        </span>
                                    </div>
                                    <span
                                        v-else-if="req.preferred_number"
                                        dir="ltr"
                                        class="font-mono text-xs font-bold text-slate-700 dark:text-slate-300 px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800"
                                    >
                                        {{ req.preferred_number }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border"
                                        :class="req.status_badge"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="req.status === 'pending' ? 'bg-amber-500 animate-pulse' : (req.status === 'approved' ? 'bg-emerald-500' : 'bg-rose-500')"></span>
                                        {{ req.status_label }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center text-xs text-slate-500 dark:text-slate-400 font-mono">
                                    {{ req.created_at }}
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <!-- Review Button (Strictly for pending requests) -->
                                        <button
                                            v-if="req.status === 'pending'"
                                            type="button"
                                            @click="openReviewModal(req)"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-xs transition-colors cursor-pointer"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                            </svg>
                                            <span>بررسی</span>
                                        </button>

                                        <!-- Details Button -->
                                        <button
                                            type="button"
                                            @click="openDetailsModal(req)"
                                            class="p-1.5 rounded-xl glass-pill hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors cursor-pointer"
                                            title="مشاهده جزئیات کامل"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="requests.data.length === 0">
                                <td colspan="8" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="w-14 h-14 rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center mb-3">
                                            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <span class="font-bold text-sm text-slate-800 dark:text-slate-200">درخواستی در این بخش ثبت نشده است</span>
                                        <p class="text-xs text-slate-400 mt-1 max-w-sm">
                                            هیچ درخواستی متناسب با فیلترهای جستجو یافت نشد.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="requests.last_page > 1" class="p-4 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-between">
                    <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                        صفحه {{ requests.current_page }} از {{ requests.last_page }}
                    </span>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in requests.links" :key="i">
                            <span
                                v-if="!link.url"
                                class="px-3 py-1 text-xs text-slate-400 rounded-lg cursor-not-allowed"
                                v-html="link.label"
                            ></span>
                            <Link
                                v-else
                                :href="link.url"
                                class="px-3 py-1 text-xs font-bold rounded-lg transition-colors"
                                :class="link.active ? 'bg-purple-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Review Modal (Approve / Reject) -->
        <div v-if="isReviewModalOpen && activeRequest" @click.self="closeReviewModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-xl shadow-2xl relative animate-in fade-in zoom-in-95 duration-200 max-h-[calc(100dvh-2.5rem)] flex flex-col">
                <!-- Modal Header -->
                <div class="pb-4 border-b border-slate-200/60 dark:border-white/10 flex items-center justify-between mb-4 shrink-0">
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">
                            بررسی و تعیین تکلیف درخواست #{{ activeRequest.id }}
                        </h3>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400">
                            متقاضی: {{ activeRequest.user.name }} (@{{ activeRequest.user.username }})
                        </span>
                    </div>
                    <button type="button" @click="closeReviewModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <div class="overflow-y-auto flex-1 space-y-4">
                    <!-- Request Summary Card -->
                    <div class="p-3.5 rounded-2xl bg-slate-100/60 dark:bg-slate-950/60 border border-slate-200/60 dark:border-white/5 text-xs space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-slate-900 dark:text-white">عنوان سرویس:</span>
                            <span class="font-bold text-indigo-600 dark:text-indigo-400">{{ activeRequest.service_title }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500 dark:text-slate-400">طول درخواستی:</span>
                            <span class="font-mono font-bold">{{ activeRequest.requested_length }} رقمی</span>
                        </div>
                        <div v-if="activeRequest.preferred_number" class="flex items-center justify-between">
                            <span class="text-slate-500 dark:text-slate-400">شماره پیشنهادی متقاضی:</span>
                            <span dir="ltr" class="font-mono font-bold text-purple-600 dark:text-purple-400">{{ activeRequest.preferred_number }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 dark:text-slate-400 block mb-1">شرح کاربرد:</span>
                            <p class="text-slate-700 dark:text-slate-300 leading-relaxed bg-white/50 dark:bg-slate-900/50 p-2.5 rounded-xl border border-slate-200/40 dark:border-white/5">
                                {{ activeRequest.description }}
                            </p>
                        </div>
                    </div>

                    <!-- Tabs: Approve vs Reject -->
                    <div class="flex border-b border-slate-200/60 dark:border-white/10 gap-2">
                        <button
                            type="button"
                            @click="reviewTab = 'approve'"
                            class="pb-2 px-3 text-xs font-bold border-b-2 transition-all cursor-pointer"
                            :class="reviewTab === 'approve' ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-slate-400 hover:text-slate-600'"
                        >
                            تأیید و تخصیص سرشماره
                        </button>
                        <button
                            type="button"
                            @click="reviewTab = 'reject'"
                            class="pb-2 px-3 text-xs font-bold border-b-2 transition-all cursor-pointer"
                            :class="reviewTab === 'reject' ? 'border-rose-500 text-rose-600 dark:text-rose-400' : 'border-transparent text-slate-400 hover:text-slate-600'"
                        >
                            عدم تأیید و رد درخواست
                        </button>
                    </div>

                    <!-- Tab 1: Approve Form -->
                    <form v-if="reviewTab === 'approve'" @submit.prevent="submitApprove" class="space-y-4">
                        <div>
                            <label class="form-label mb-2">
                                شیوه تخصیص سرشماره سازمانی
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <label
                                    class="p-2.5 rounded-xl border text-center cursor-pointer transition-all text-xs"
                                    :class="approveForm.mode === 'auto' ? 'bg-emerald-500/15 border-emerald-500 text-emerald-700 dark:text-emerald-300 font-bold' : 'border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-400'"
                                >
                                    <input type="radio" value="auto" v-model="approveForm.mode" class="sr-only" />
                                    <span class="block font-bold">تخصیص خودکار</span>
                                    <span class="text-[10px] opacity-75">سیستمی</span>
                                </label>

                                <label
                                    class="p-2.5 rounded-xl border text-center cursor-pointer transition-all text-xs"
                                    :class="approveForm.mode === 'pool' ? 'bg-emerald-500/15 border-emerald-500 text-emerald-700 dark:text-emerald-300 font-bold' : 'border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-400'"
                                >
                                    <input type="radio" value="pool" v-model="approveForm.mode" class="sr-only" />
                                    <span class="block font-bold">از استخر آزاد</span>
                                    <span class="text-[10px] opacity-75">انتخاب دستی</span>
                                </label>

                                <label
                                    class="p-2.5 rounded-xl border text-center cursor-pointer transition-all text-xs"
                                    :class="approveForm.mode === 'custom' ? 'bg-emerald-500/15 border-emerald-500 text-emerald-700 dark:text-emerald-300 font-bold' : 'border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-400'"
                                >
                                    <input type="radio" value="custom" v-model="approveForm.mode" class="sr-only" />
                                    <span class="block font-bold">شماره دستی</span>
                                    <span class="text-[10px] opacity-75">سفارشی ادمین</span>
                                </label>
                            </div>
                        </div>

                        <!-- Mode: Auto Info -->
                        <div v-if="approveForm.mode === 'auto'" class="p-3 rounded-xl bg-slate-100/60 dark:bg-slate-900/60 border border-slate-200/40 dark:border-white/5 text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                            سامانه به صورت اتمیک یک سرشماره {{ activeRequest.requested_length }} رقمی آزاد را از استخر تخصیص داده یا به صورت تصادفی و یکتا تولید می‌نماید.
                        </div>

                        <!-- Mode: Pool Select -->
                        <div v-if="approveForm.mode === 'pool'" class="space-y-1.5">
                            <label class="form-label">
                                انتخاب سرشماره آزاد از استخر (طول {{ activeRequest.requested_length }} رقم) <span class="text-rose-500">*</span>
                            </label>
                            <select
                                v-if="filteredPoolNumbers.length > 0"
                                v-model="approveForm.pool_number_id"
                                class="form-select w-full !text-xs font-mono"
                            >
                                <option value="">-- انتخاب خط آزاد --</option>
                                <option v-for="num in filteredPoolNumbers" :key="num.id" :value="num.id">
                                    {{ num.number }} (طول: {{ num.length }} رقم)
                                </option>
                            </select>
                            <div v-else class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/25 text-amber-700 dark:text-amber-300 text-xs">
                                در حال حاضر سرشماره آزادی با طول {{ activeRequest.requested_length }} رقم در استخر موجود نیست. لطفاً از شیوه «تخصیص خودکار» یا «شماره دستی» استفاده نمایید.
                            </div>
                            <span v-if="approveForm.errors.pool_number_id" class="text-xs text-rose-500 block">
                                {{ approveForm.errors.pool_number_id }}
                            </span>
                        </div>

                        <!-- Mode: Custom Manual -->
                        <div v-if="approveForm.mode === 'custom'" class="space-y-1.5">
                            <label class="form-label">
                                سرشماره دلخواه دستی (دقیقاً {{ activeRequest.requested_length }} رقم عددی) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="text"
                                v-model="approveForm.custom_number"
                                dir="ltr"
                                :maxlength="activeRequest.requested_length"
                                placeholder="مثال: 2000"
                                class="form-input w-full !text-xs !font-mono text-left"
                            />
                            <span v-if="approveForm.errors.custom_number" class="text-xs text-rose-500 block">
                                {{ approveForm.errors.custom_number }}
                            </span>
                        </div>

                        <div class="pt-3 border-t border-slate-200/60 dark:border-white/10 flex justify-end gap-2.5">
                            <button type="button" @click="closeReviewModal" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="approveForm.processing"
                                class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md shadow-emerald-600/20 disabled:opacity-50 transition-all cursor-pointer"
                            >
                                <svg v-if="approveForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ approveForm.processing ? 'در حال ثبت و تخصیص...' : 'تأیید نهایی و واگذاری خط' }}</span>
                            </button>
                        </div>
                    </form>

                    <!-- Tab 2: Reject Form -->
                    <form v-if="reviewTab === 'reject'" @submit.prevent="submitReject" class="space-y-4">
                        <div>
                            <label class="form-label mb-1.5">
                                دلیل عدم تأیید و رد درخواست <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="rejectForm.reason"
                                rows="3"
                                placeholder="لطفاً دلیل شفاف عدم تأیید را جهت نمایش به متقاضی درج فرمایید (حداقل ۳ کاراکتر)..."
                                class="form-textarea w-full !text-xs resize-none"
                            ></textarea>
                            <span v-if="rejectForm.errors.reason" class="text-xs text-rose-500 block mt-1">
                                {{ rejectForm.errors.reason }}
                            </span>
                        </div>

                        <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-[11px] leading-relaxed">
                            با رد درخواست، وضعیت به «رد شده» تغییر یافته و دلیل فوق به متقاضی نمایش داده خواهد شد.
                        </div>

                        <div class="pt-3 border-t border-slate-200/60 dark:border-white/10 flex justify-end gap-2.5">
                            <button type="button" @click="closeReviewModal" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="rejectForm.processing"
                                class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md shadow-rose-600/20 disabled:opacity-50 transition-all cursor-pointer"
                            >
                                <svg v-if="rejectForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ rejectForm.processing ? 'در حال رد درخواست...' : 'تأیید رد درخواست' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Details Modal -->
        <div v-if="isDetailsModalOpen && detailsRequest" @click.self="closeDetailsModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-lg shadow-2xl relative animate-in fade-in zoom-in-95 duration-200 max-h-[calc(100dvh-2.5rem)] flex flex-col">
                <div class="pb-4 border-b border-slate-200/60 dark:border-white/10 flex items-center justify-between mb-4 shrink-0">
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">
                            جزئیات درخواست سرشماره سازمانی #{{ detailsRequest.id }}
                        </h3>
                        <span class="text-[11px] text-slate-400 block font-mono">ثبت‌شده توسط {{ detailsRequest.user.name }} (@{{ detailsRequest.user.username }})</span>
                    </div>
                    <button type="button" @click="closeDetailsModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <div class="space-y-4 text-xs overflow-y-auto flex-1">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-950/50 border border-slate-200/40 dark:border-white/5">
                            <span class="text-slate-400 block text-[10px] mb-1">طول درخواستی</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ detailsRequest.requested_length }} رقم</span>
                        </div>
                        <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-950/50 border border-slate-200/40 dark:border-white/5">
                            <span class="text-slate-400 block text-[10px] mb-1">وضعیت کنونی</span>
                            <span class="font-bold" :class="detailsRequest.status_badge">{{ detailsRequest.status_label }}</span>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-950/50 border border-slate-200/40 dark:border-white/5">
                        <span class="text-slate-400 block text-[10px] mb-1">عنوان سرویس</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ detailsRequest.service_title }}</span>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-950/50 border border-slate-200/40 dark:border-white/5">
                        <span class="text-slate-400 block text-[10px] mb-1">توضیحات و کاربرد</span>
                        <p class="text-slate-700 dark:text-slate-300 leading-relaxed">{{ detailsRequest.description }}</p>
                    </div>

                    <!-- Allocated number card if approved -->
                    <div v-if="detailsRequest.allocated_number" class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/25">
                        <span class="text-emerald-700 dark:text-emerald-400 block text-[10px] font-bold mb-1">سرشماره اختصاص‌یافته</span>
                        <div class="flex items-center justify-between">
                            <span dir="ltr" class="font-mono text-base font-black text-emerald-700 dark:text-emerald-400">
                                {{ detailsRequest.allocated_number.formatted_number }}
                            </span>
                            <button
                                type="button"
                                @click="copyToClipboard(detailsRequest.allocated_number.number)"
                                class="px-3 py-1 rounded-lg text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-100 transition-colors cursor-pointer"
                            >
                                کپی شماره
                            </button>
                        </div>
                    </div>

                    <!-- Rejection reason if rejected -->
                    <div v-if="detailsRequest.rejection_reason" class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/25">
                        <span class="text-rose-700 dark:text-rose-400 block text-[10px] font-bold mb-1">دلیل رد درخواست</span>
                        <p class="text-rose-900 dark:text-rose-200 leading-relaxed">{{ detailsRequest.rejection_reason }}</p>
                    </div>

                    <!-- Reviewer info -->
                    <div v-if="detailsRequest.reviewed_at" class="text-[11px] text-slate-400 pt-2 border-t border-slate-200/50 dark:border-white/5 flex items-center justify-between font-mono">
                        <span>بررسی‌کننده: {{ detailsRequest.reviewer_name || 'راهبر سامانه' }}</span>
                        <span>تاریخ: {{ detailsRequest.reviewed_at }}</span>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-200/60 dark:border-white/10 flex justify-end mt-4 shrink-0">
                    <button type="button" @click="closeDetailsModal" class="px-5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors cursor-pointer">
                        بستن
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
