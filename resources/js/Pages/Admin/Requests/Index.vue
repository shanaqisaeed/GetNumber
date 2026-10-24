<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

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

const props = defineProps<{
    requests: {
        data: NumberRequestItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
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

    // If preferred number exists, prefill custom or auto
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
</script>

<template>
    <AdminLayout>
        <Head title="بررسی درخواست‌های سرشماره سازمانی" />

        <div class="space-y-6">
            <!-- Flash Alerts -->
            <div v-if="$page.props.flash?.success" class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-xs font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $page.props.flash.success }}</span>
            </div>

            <div v-if="$page.props.flash?.error" class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $page.props.flash.error }}</span>
            </div>

            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-purple-100 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                        </span>
                        بررسی درخواست‌های سرشماره سازمانی
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        رسیدگی و تعیین تکلیف درخواست‌های سرشماره اختصاصی متقاضیان
                    </p>
                </div>
            </div>

            <!-- Stats Metric Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-slate-500 dark:text-slate-400 block mb-1">کل درخواست‌های دریافتی</span>
                        <span class="text-2xl font-black text-slate-900 dark:text-white">{{ stats.total }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400 block mb-1">نیازمند بررسی</span>
                        <span class="text-2xl font-black text-amber-600 dark:text-amber-400">{{ stats.pending }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400 block mb-1">تأیید و تخصیص یافته</span>
                        <span class="text-2xl font-black text-emerald-600 dark:text-emerald-400">{{ stats.approved }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-rose-600 dark:text-rose-400 block mb-1">رد شده با دلیل</span>
                        <span class="text-2xl font-black text-rose-600 dark:text-rose-400">{{ stats.rejected }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col md:flex-row gap-3 items-center justify-between">
                <div class="w-full md:w-80 relative">
                    <input
                        v-model="search"
                        @keyup.enter="handleFilter"
                        type="text"
                        placeholder="جستجو در نام کاربر، عنوان یا شماره..."
                        class="w-full pl-10 pr-4 py-2 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all placeholder:text-slate-400"
                    />
                    <div class="absolute left-3 top-2.5 text-slate-400 pointer-events-none">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </div>

                <div class="flex items-center gap-2.5 w-full md:w-auto">
                    <select
                        v-model="statusFilter"
                        @change="handleFilter"
                        class="px-3.5 py-2 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all"
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option value="pending">در انتظار بررسی</option>
                        <option value="approved">تأیید شده</option>
                        <option value="rejected">رد شده</option>
                    </select>

                    <select
                        v-model="lengthFilter"
                        @change="handleFilter"
                        class="px-3.5 py-2 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all"
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
                        class="px-3 py-2 text-xs font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors flex items-center gap-1"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <span>حذف فیلترها</span>
                    </button>
                </div>
            </div>

            <!-- Requests Table -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="px-5 py-4 text-center w-12">#</th>
                                <th class="px-5 py-4">کاربر متقاضی</th>
                                <th class="px-5 py-4">عنوان و شرح سرویس</th>
                                <th class="px-5 py-4 text-center">طول درخواستی</th>
                                <th class="px-5 py-4 text-center">پیشنهادی / تخصیص‌یافته</th>
                                <th class="px-5 py-4 text-center">وضعیت</th>
                                <th class="px-5 py-4 text-center">تاریخ ثبت</th>
                                <th class="px-5 py-4 text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="(req, index) in requests.data"
                                :key="req.id"
                                class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="px-5 py-4 text-center text-xs text-slate-400">
                                    {{ (requests.current_page - 1) * 15 + index + 1 }}
                                </td>

                                <td class="px-5 py-4">
                                    <div class="font-bold text-slate-900 dark:text-white">{{ req.user.name }}</div>
                                    <div class="text-xs text-slate-400 font-mono">@{{ req.user.username }}</div>
                                </td>

                                <td class="px-5 py-4 font-bold text-slate-900 dark:text-white max-w-xs">
                                    <span>{{ req.service_title }}</span>
                                    <span class="block text-xs font-normal text-slate-400 line-clamp-1 mt-0.5">
                                        {{ req.description }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                        {{ req.requested_length }} رقم
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div v-if="req.allocated_number" class="inline-flex items-center gap-1.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/50 px-2 py-0.5 rounded-lg">
                                        <span dir="ltr" class="font-mono text-xs font-black text-emerald-700 dark:text-emerald-400">
                                            {{ req.allocated_number.number }}
                                        </span>
                                    </div>
                                    <span
                                        v-else-if="req.preferred_number"
                                        dir="ltr"
                                        class="font-mono text-xs font-bold text-slate-500 dark:text-slate-400"
                                    >
                                        {{ req.preferred_number }} (پیشنهادی)
                                    </span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span
                                        class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold"
                                        :class="req.status_badge"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                        {{ req.status_label }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center text-xs text-slate-500 dark:text-slate-400">
                                    {{ req.created_at }}
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button
                                            v-if="req.status === 'pending'"
                                            @click="openReviewModal(req)"
                                            type="button"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-bold text-white bg-purple-600 hover:bg-purple-500 shadow-sm transition-all"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>بررسی و تصمیم‌گیری</span>
                                        </button>

                                        <button
                                            v-else
                                            @click="openDetailsModal(req)"
                                            type="button"
                                            class="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
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
                                        <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mb-3">
                                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <span class="font-bold text-base text-slate-800 dark:text-slate-200">درخواستی یافت نشد</span>
                                        <p class="text-xs text-slate-400 mt-1 max-w-sm">
                                            هیچ درخواست سرشماره‌ای منطبق با فیلترهای مشخص‌شده در سامانه ثبت نشده است.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="requests.last_page > 1" class="p-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        نمایش صفحه {{ requests.current_page }} از {{ requests.last_page }}
                    </span>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in requests.links" :key="i">
                            <span
                                v-if="!link.url"
                                class="px-3 py-1.5 text-xs text-slate-400 rounded-lg cursor-not-allowed"
                                v-html="link.label"
                            ></span>
                            <a
                                v-else
                                :href="link.url"
                                class="px-3 py-1.5 text-xs font-bold rounded-lg transition-colors"
                                :class="link.active ? 'bg-purple-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            ></a>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Review / Decision Modal -->
        <div v-if="isReviewModalOpen && activeRequest" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-xl rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <!-- Modal Header -->
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>تعیین تکلیف درخواست سرشماره سازمانی</span>
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">
                            متقاضی: {{ activeRequest.user.name }} (@{{ activeRequest.user.username }})
                        </span>
                    </div>
                    <button @click="closeReviewModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Request Information Card -->
                <div class="p-5 bg-slate-50 dark:bg-slate-800/40 border-b border-slate-100 dark:border-slate-800 space-y-2 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">عنوان سرویس:</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">{{ activeRequest.service_title }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">طول ارقام درخواستی:</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">{{ activeRequest.requested_length }} رقمی</span>
                    </div>
                    <div v-if="activeRequest.preferred_number" class="flex items-center justify-between">
                        <span class="text-slate-400">سرشماره پیشنهادی کاربر:</span>
                        <span dir="ltr" class="font-mono font-bold text-purple-600 dark:text-purple-400">{{ activeRequest.preferred_number }}</span>
                    </div>
                    <div class="pt-1">
                        <span class="text-slate-400 block mb-1">شرح کاربرد:</span>
                        <p class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/60 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 leading-relaxed">
                            {{ activeRequest.description }}
                        </p>
                    </div>
                </div>

                <!-- Decision Tabs -->
                <div class="p-6">
                    <div class="flex items-center gap-2 p-1 rounded-2xl bg-slate-100 dark:bg-slate-800 mb-5">
                        <button
                            type="button"
                            @click="reviewTab = 'approve'"
                            class="flex-1 py-2 text-xs font-bold rounded-xl transition-all"
                            :class="[
                                reviewTab === 'approve'
                                    ? 'bg-emerald-600 text-white shadow-sm'
                                    : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
                            ]"
                        >
                            تأیید و تخصیص سرشماره
                        </button>
                        <button
                            type="button"
                            @click="reviewTab = 'reject'"
                            class="flex-1 py-2 text-xs font-bold rounded-xl transition-all"
                            :class="[
                                reviewTab === 'reject'
                                    ? 'bg-rose-600 text-white shadow-sm'
                                    : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
                            ]"
                        >
                            رد درخواست با درج دلیل
                        </button>
                    </div>

                    <!-- Approve Form -->
                    <form v-if="reviewTab === 'approve'" @submit.prevent="submitApprove" class="space-y-4">
                        <!-- Error Alert inside modal -->
                        <div v-if="(approveForm.errors as any).general || $page.props.flash?.error" class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                            <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ (approveForm.errors as any).general || $page.props.flash?.error }}</span>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">
                                نحوه تخصیص سرشماره
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <label
                                    class="p-3 rounded-xl border cursor-pointer text-center transition-all"
                                    :class="[
                                        approveForm.mode === 'auto'
                                            ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-500 text-emerald-800 dark:text-emerald-300 font-bold'
                                            : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400'
                                    ]"
                                >
                                    <input type="radio" value="auto" v-model="approveForm.mode" class="sr-only" />
                                    <span class="block text-xs">تولید / تخصیص خودکار</span>
                                </label>

                                <label
                                    class="p-3 rounded-xl border cursor-pointer text-center transition-all"
                                    :class="[
                                        approveForm.mode === 'pool'
                                            ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-500 text-emerald-800 dark:text-emerald-300 font-bold'
                                            : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400'
                                    ]"
                                >
                                    <input type="radio" value="pool" v-model="approveForm.mode" class="sr-only" />
                                    <span class="block text-xs">انتخاب از استخر آزاد</span>
                                </label>

                                <label
                                    class="p-3 rounded-xl border cursor-pointer text-center transition-all"
                                    :class="[
                                        approveForm.mode === 'custom'
                                            ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-500 text-emerald-800 dark:text-emerald-300 font-bold'
                                            : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400'
                                    ]"
                                >
                                    <input type="radio" value="custom" v-model="approveForm.mode" class="sr-only" />
                                    <span class="block text-xs">درج سرشماره دستی</span>
                                </label>
                            </div>
                        </div>

                        <!-- Mode: Auto Info -->
                        <div v-if="approveForm.mode === 'auto'" class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                            سامانه ابتدا بررسی می‌کند که آیا سرشماره آزادی با طول {{ activeRequest.requested_length }} رقم در استخر موجود است یا خیر. در صورت وجود، همان شماره تخصیص می‌یابد؛ در غیر این‌صورت یک سرشماره یکتای {{ activeRequest.requested_length }} رقمی تصادفی تولید و تخصیص داده می‌شود.
                        </div>

                        <!-- Mode: Pool Selection -->
                        <div v-if="approveForm.mode === 'pool'" class="space-y-1.5">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                انتخاب خط آزاد از استخر (طول {{ activeRequest.requested_length }} رقم) <span class="text-rose-500">*</span>
                            </label>
                            <select
                                v-model="approveForm.pool_number_id"
                                class="w-full px-4 py-2.5 rounded-xl text-sm font-mono border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                            >
                                <option value="">انتخاب سرشماره...</option>
                                <option v-for="num in filteredPoolNumbers" :key="num.id" :value="num.id">
                                    {{ num.number }} (طول: {{ num.length }} رقم)
                                </option>
                            </select>
                            <span v-if="filteredPoolNumbers.length === 0" class="text-xs text-amber-500 block">
                                هیچ سرشماره آزادی با طول {{ activeRequest.requested_length }} رقم در استخر یافت نشد. می‌توانید از حالت تولید خودکار یا درج دستی استفاده فرمایید.
                            </span>
                            <span v-if="approveForm.errors.pool_number_id" class="text-xs text-rose-500 block">
                                {{ approveForm.errors.pool_number_id }}
                            </span>
                        </div>

                        <!-- Mode: Custom Manual Input -->
                        <div v-if="approveForm.mode === 'custom'" class="space-y-1.5">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                سرشماره اختصاصی دستی (دقیقاً {{ activeRequest.requested_length }} رقم) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                v-model="approveForm.custom_number"
                                type="text"
                                dir="ltr"
                                :maxlength="activeRequest.requested_length"
                                class="w-full px-4 py-2.5 rounded-xl text-sm font-mono text-left border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                                :placeholder="activeRequest.requested_length === 4 ? '1000' : '100000'"
                            />
                            <span v-if="approveForm.errors.custom_number" class="text-xs text-rose-500 block">
                                {{ approveForm.errors.custom_number }}
                            </span>
                        </div>

                        <div class="pt-3 flex items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                            <button
                                @click="closeReviewModal"
                                type="button"
                                class="px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="approveForm.processing"
                                class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-600/20 disabled:opacity-50"
                            >
                                <span v-if="approveForm.processing">در حال پردازش...</span>
                                <span v-else>تأیید و تخصیص قطعی سرشماره</span>
                            </button>
                        </div>
                    </form>

                    <!-- Reject Form -->
                    <form v-if="reviewTab === 'reject'" @submit.prevent="submitReject" class="space-y-4">
                        <!-- Error Alert inside modal -->
                        <div v-if="(rejectForm.errors as any).general || $page.props.flash?.error" class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                            <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ (rejectForm.errors as any).general || $page.props.flash?.error }}</span>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                دلیل عدم تأیید درخواست <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="rejectForm.reason"
                                rows="4"
                                placeholder="دلیل رد درخواست را جهت اطلاع متقاضی به صورت رسمی و دقیق درج نمایید..."
                                class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 resize-none"
                            ></textarea>
                            <span v-if="rejectForm.errors.reason" class="text-xs text-rose-500 block mt-1">
                                {{ rejectForm.errors.reason }}
                            </span>
                        </div>

                        <div class="pt-3 flex items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                            <button
                                @click="closeReviewModal"
                                type="button"
                                class="px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="rejectForm.processing"
                                class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-rose-600 hover:bg-rose-500 shadow-md shadow-rose-600/20 disabled:opacity-50"
                            >
                                <span v-if="rejectForm.processing">در حال ثبت...</span>
                                <span v-else>ثبت رد درخواست</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Details Modal -->
        <div v-if="isDetailsModalOpen && detailsRequest" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">جزئیات بررسی درخواست #{{ detailsRequest.id }}</h3>
                        <span class="text-xs text-slate-400">کاربر: {{ detailsRequest.user.name }} (@{{ detailsRequest.user.username }})</span>
                    </div>
                    <button @click="closeDetailsModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60">
                        <span class="text-slate-500 dark:text-slate-400">وضعیت فعلی:</span>
                        <span class="px-3 py-1 rounded-full font-bold" :class="detailsRequest.status_badge">
                            {{ detailsRequest.status_label }}
                        </span>
                    </div>

                    <div v-if="detailsRequest.allocated_number" class="p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 flex items-center justify-between">
                        <span class="font-bold text-emerald-800 dark:text-emerald-300">سرشماره تخصیص‌یافته:</span>
                        <span dir="ltr" class="font-mono text-base font-black text-emerald-700 dark:text-emerald-400">
                            {{ detailsRequest.allocated_number.number }}
                        </span>
                    </div>

                    <div v-if="detailsRequest.rejection_reason" class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800/60">
                        <span class="font-bold text-rose-800 dark:text-rose-300 block mb-1">دلیل رد درخواست:</span>
                        <p class="text-rose-700 dark:text-rose-300 leading-relaxed">
                            {{ detailsRequest.rejection_reason }}
                        </p>
                    </div>

                    <div>
                        <span class="font-bold text-slate-500 dark:text-slate-400 block mb-1">شرح کاربرد:</span>
                        <p class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300 leading-relaxed">
                            {{ detailsRequest.description }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100 dark:border-slate-800 text-slate-400">
                        <div>
                            <span>مسئول بررسی:</span>
                            <span class="font-bold text-slate-600 dark:text-slate-300 block mt-0.5">
                                {{ detailsRequest.reviewer_name || '—' }}
                            </span>
                        </div>
                        <div>
                            <span>تاریخ بررسی:</span>
                            <span class="font-bold text-slate-600 dark:text-slate-300 block mt-0.5">
                                {{ detailsRequest.reviewed_at || '—' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="pt-2 flex justify-end">
                    <button
                        @click="closeDetailsModal"
                        type="button"
                        class="px-5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                    >
                        بستن
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
