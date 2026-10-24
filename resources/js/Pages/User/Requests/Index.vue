<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

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
    status: 'pending' | 'approved' | 'rejected';
    status_label: string;
    status_badge: string;
    rejection_reason: string | null;
    allocated_number: AllocatedNumber | null;
    reviewer_name: string | null;
    reviewed_at: string | null;
    created_at: string;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
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

const createForm = useForm({
    requested_length: 4,
    service_title: '',
    description: '',
    preferred_number: '',
});

const openCreateModal = () => {
    createForm.reset();
    createForm.requested_length = 4;
    createForm.clearErrors();
    isCreateModalOpen.value = true;
};

const closeCreateModal = () => {
    isCreateModalOpen.value = false;
};

const submitCreate = () => {
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

    router.delete(route('requests.destroy', requestToCancel.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeCancelModal();
        },
    });
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
    <UserLayout>
        <Head title="درخواست‌های سرشماره سازمانی" />

        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-purple-100 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </span>
                        درخواست‌های سرشماره اختصاصی سازمانی
                    </h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                        ثبت و پیگیری درخواست سرشماره‌های اختصاصی کسب‌وکار با طول ۴ تا ۸ رقم
                    </p>
                </div>

                <button
                    @click="openCreateModal"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 shadow-lg shadow-purple-500/25 transition-all transform active:scale-95"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>ثبت درخواست سرشماره جدید</span>
                </button>
            </div>

            <!-- Stats Metric Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-slate-500 dark:text-slate-400 block mb-1">کل درخواست‌ها</span>
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
                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400 block mb-1">در انتظار بررسی</span>
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
                        <span class="text-xs font-medium text-rose-600 dark:text-rose-400 block mb-1">رد شده</span>
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
                        placeholder="جستجو در عنوان، شرح یا شماره..."
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

                    <button
                        v-if="search || statusFilter"
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
                                <th class="px-5 py-4">عنوان سرویس درخواستی</th>
                                <th class="px-5 py-4 text-center">طول ارقام</th>
                                <th class="px-5 py-4 text-center">سرشماره پیشنهادی</th>
                                <th class="px-5 py-4 text-center">سرشماره تخصیص‌یافته</th>
                                <th class="px-5 py-4 text-center">وضعیت درخواست</th>
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
                                    {{ (requests.current_page - 1) * 10 + index + 1 }}
                                </td>

                                <td class="px-5 py-4 font-bold text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <span>{{ req.service_title }}</span>
                                    </div>
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
                                    <span
                                        v-if="req.preferred_number"
                                        dir="ltr"
                                        class="font-mono text-sm font-bold text-slate-700 dark:text-slate-300 px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800"
                                    >
                                        {{ req.preferred_number }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div v-if="req.allocated_number" class="inline-flex items-center gap-1.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/50 px-2.5 py-1 rounded-lg">
                                        <span dir="ltr" class="font-mono text-sm font-black text-emerald-700 dark:text-emerald-400 tracking-wider">
                                            {{ req.allocated_number.number }}
                                        </span>
                                        <button
                                            @click="copyToClipboard(req.allocated_number.number)"
                                            type="button"
                                            class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300"
                                            title="کپی سرشماره"
                                        >
                                            <svg v-if="copiedNumber === req.allocated_number.number" class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                        </button>
                                    </div>
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

                                        <button
                                            v-if="req.status === 'pending'"
                                            @click="openCancelModal(req)"
                                            type="button"
                                            class="p-1.5 rounded-lg text-rose-500 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                                            title="لغو درخواست"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
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
                                            شما تاکنون درخواستی برای دریافت سرشماره سازمانی ثبت نکرده‌اید یا فیلترهای انتخابی موردی را شامل نمی‌شوند.
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

        <!-- Create Request Modal -->
        <div v-if="isCreateModalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="p-1.5 rounded-xl bg-purple-100 dark:bg-purple-950/60 text-purple-600">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </span>
                        ثبت درخواست سرشماره اختصاصی
                    </h3>
                    <button @click="closeCreateModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitCreate" class="p-6 space-y-5">
                    <!-- Length selector cards -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">
                            طول ارقام سرشماره درخواستی <span class="text-rose-500">*</span>
                        </label>
                        <div class="grid grid-cols-5 gap-2">
                            <button
                                v-for="len in availableLengths"
                                :key="len"
                                type="button"
                                @click="createForm.requested_length = len"
                                class="p-2.5 rounded-xl text-center border transition-all"
                                :class="[
                                    createForm.requested_length === len
                                        ? 'bg-purple-50 dark:bg-purple-950/40 border-purple-500 text-purple-700 dark:text-purple-300 font-black shadow-sm'
                                        : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'
                                ]"
                            >
                                <span class="block text-base font-mono">{{ len }}</span>
                                <span class="text-[10px] block mt-0.5">رقمی</span>
                            </button>
                        </div>
                        <span v-if="createForm.errors.requested_length" class="text-xs text-rose-500 block mt-1">
                            {{ createForm.errors.requested_length }}
                        </span>
                    </div>

                    <!-- Service title -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            عنوان سرویس سازمانی <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="createForm.service_title"
                            type="text"
                            placeholder="نمونه: سامانه اطلاع‌رسانی مرکزی، درگاه پیامکی مشتریان"
                            class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all placeholder:text-slate-400"
                        />
                        <span v-if="createForm.errors.service_title" class="text-xs text-rose-500 block mt-1">
                            {{ createForm.errors.service_title }}
                        </span>
                    </div>

                    <!-- Usage description -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            توضیح کاربرد سازمانی <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            v-model="createForm.description"
                            rows="3"
                            placeholder="لطفاً نحوه استفاده، اهداف ارتباطی و جامعه هدف دریافت‌کننده این سرشماره را شرح دهید..."
                            class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all placeholder:text-slate-400 resize-none"
                        ></textarea>
                        <span v-if="createForm.errors.description" class="text-xs text-rose-500 block mt-1">
                            {{ createForm.errors.description }}
                        </span>
                    </div>

                    <!-- Preferred number (Optional) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                سرشماره پیشنهادی دلخواه
                            </label>
                            <span class="text-[11px] text-slate-400">اختیاری - {{ createForm.requested_length }} رقم عددی</span>
                        </div>
                        <input
                            v-model="createForm.preferred_number"
                            type="text"
                            dir="ltr"
                            :maxlength="createForm.requested_length"
                            :placeholder="createForm.requested_length === 4 ? '1000' : '100000'"
                            class="w-full px-4 py-2.5 rounded-xl text-sm font-mono border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all placeholder:text-slate-400 text-left"
                        />
                        <span class="text-[11px] text-slate-400 block mt-1">
                            در صورت خالی گذاشتن، یک سرشماره آزاد با طول {{ createForm.requested_length }} رقم به صورت خودکار تخصیص خواهد یافت.
                        </span>
                        <span v-if="createForm.errors.preferred_number" class="text-xs text-rose-500 block mt-1">
                            {{ createForm.errors.preferred_number }}
                        </span>
                    </div>

                    <!-- Non-Affiliation and Terms Notice (Requirement 5) -->
                    <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 text-xs leading-relaxed space-y-2">
                        <div class="flex items-center gap-1.5 font-bold text-amber-800 dark:text-amber-300">
                            <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>بیانیه عدم وابستگی و ضوابط اختصاص سرشماره</span>
                        </div>
                        <p class="text-[11px] leading-relaxed text-slate-700 dark:text-slate-300">
                            این سامانه یک بستر کاملاً مستقل ارائه خدمات ارتباطی بوده و به هیچ حزب، گروه، سازمان‌های تروریستی، نهادهای دولتی یا حاکمیتی وابسته نبوده و هیچ‌گونه سرشماره‌ای بابت فعالیت‌های مذکور اختصاص نمی‌دهد. سرشماره‌های تخصیص‌یافته صرفاً در سامانه‌ها و درگاه‌های مورد تأیید و تعیین‌شده توسط این سامانه در دسترس و قابل بهره‌برداری خواهند بود.
                        </p>
                        <label class="flex items-start gap-2 pt-1 font-semibold cursor-pointer">
                            <input type="checkbox" required class="mt-0.5 rounded border-slate-300 dark:border-slate-700 text-purple-600 focus:ring-purple-500" />
                            <span class="text-[11px] text-slate-800 dark:text-slate-200">ضوابط فوق و محدودیت استفاده انحصاری در سامانه‌های تعیین‌شده را تأیید می‌نمایم.</span>
                        </label>
                    </div>

                    <!-- Actions -->
                    <div class="pt-2 flex items-center justify-end gap-3">
                        <button
                            @click="closeCreateModal"
                            type="button"
                            class="px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 shadow-md shadow-purple-500/20 disabled:opacity-50 transition-all"
                        >
                            <span v-if="createForm.processing">در حال ارسال...</span>
                            <span v-else>ثبت و ارسال درخواست</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Details Modal -->
        <div v-if="isDetailsModalOpen && selectedRequest" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">
                            جزئیات درخواست سرشماره سازمانی
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">شناسه درخواست: #{{ selectedRequest.id }}</span>
                    </div>
                    <button @click="closeDetailsModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 space-y-4 text-sm">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60">
                        <span class="text-slate-500 dark:text-slate-400">وضعیت فعلی:</span>
                        <span class="px-3 py-1 rounded-full text-xs font-bold" :class="selectedRequest.status_badge">
                            {{ selectedRequest.status_label }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-400 block mb-1">عنوان سرویس</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ selectedRequest.service_title }}</span>
                        </div>
                        <div class="p-3 rounded-xl border border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-400 block mb-1">طول درخواستی</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ selectedRequest.requested_length }} رقم عددی</span>
                        </div>
                    </div>

                    <div v-if="selectedRequest.allocated_number" class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60">
                        <span class="text-xs font-bold text-emerald-800 dark:text-emerald-300 block mb-1">سرشماره اختصاص‌یافته به شما</span>
                        <div class="flex items-center justify-between">
                            <span dir="ltr" class="font-mono text-2xl font-black text-emerald-700 dark:text-emerald-400 tracking-wider">
                                {{ selectedRequest.allocated_number.number }}
                            </span>
                            <button
                                @click="copyToClipboard(selectedRequest.allocated_number.number)"
                                type="button"
                                class="px-3 py-1.5 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-500 transition-colors"
                            >
                                <span v-if="copiedNumber === selectedRequest.allocated_number.number">کپی شد!</span>
                                <span v-else>کپی شماره</span>
                            </button>
                        </div>
                    </div>

                    <!-- Rejection Alert if rejected -->
                    <div v-if="selectedRequest.status === 'rejected' && selectedRequest.rejection_reason" class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800/60">
                        <span class="text-xs font-bold text-rose-800 dark:text-rose-300 flex items-center gap-1.5 mb-1">
                            <svg class="w-4 h-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            دلیل عدم تأیید توسط مدیریت
                        </span>
                        <p class="text-xs text-rose-700 dark:text-rose-300 leading-relaxed mt-1">
                            {{ selectedRequest.rejection_reason }}
                        </p>
                    </div>

                    <div>
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">شرح کاربرد ارائه‌شده</span>
                        <p class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 text-xs text-slate-700 dark:text-slate-300 leading-relaxed">
                            {{ selectedRequest.description }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 text-xs text-slate-400 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <div>
                            <span>تاریخ ثبت درخواست:</span>
                            <span class="font-bold text-slate-600 dark:text-slate-300 block mt-0.5">{{ selectedRequest.created_at }}</span>
                        </div>
                        <div v-if="selectedRequest.reviewed_at">
                            <span>تاریخ بررسی:</span>
                            <span class="font-bold text-slate-600 dark:text-slate-300 block mt-0.5">{{ selectedRequest.reviewed_at }}</span>
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                    <button
                        @click="closeDetailsModal"
                        type="button"
                        class="px-5 py-2 rounded-xl text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                    >
                        بستن
                    </button>
                </div>
            </div>
        </div>

        <!-- Cancel Confirmation Modal -->
        <div v-if="isCancelModalOpen && requestToCancel" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-md rounded-3xl shadow-2xl p-6 space-y-4">
                <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>

                <div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">لغو و حذف درخواست سرشماره</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                        آیا از لغو درخواست سرشماره «<span class="font-bold text-slate-800 dark:text-slate-200">{{ requestToCancel.service_title }}</span>» اطمینان دارید؟ این عمل غیرقابل بازگشت است.
                    </p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        @click="closeCancelModal"
                        type="button"
                        class="px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                    >
                        انصراف
                    </button>
                    <button
                        @click="confirmCancel"
                        type="button"
                        class="px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-rose-600 hover:bg-rose-500 shadow-md shadow-rose-600/20"
                    >
                        تأیید و لغو درخواست
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
