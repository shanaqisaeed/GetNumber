<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

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
});

const openCreateModal = () => {
    termsAccepted.value = false;
    createForm.reset();
    createForm.requested_length = 4;
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

        <template #header>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></span>
                <h1 class="text-base font-bold text-slate-900 dark:text-white">درخواست‌های سرشماره اختصاصی</h1>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Header Card & CTA -->
            <div class="glass-card !p-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="glass-pill text-[11px] text-purple-600 dark:text-purple-400 font-bold">
                                خطوط رند و سرشماره‌های سفارشی
                            </span>
                            <span class="glass-pill text-[11px] text-indigo-600 dark:text-indigo-400 font-bold">
                                طول ۴ تا ۸ رقم
                            </span>
                        </div>
                        <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                            درخواست‌های سرشماره اختصاصی سازمانی
                        </h1>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-xl">
                            ثبت و رهگیری سرشماره‌های اختصاصی کوتاه ۴ تا ۸ رقمی، مناسب درگاه‌های سازمانی و سامانه‌های پردازش وب‌سرویس.
                        </p>
                    </div>

                    <button
                        type="button"
                        @click="openCreateModal"
                        class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl shrink-0 cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>ثبت درخواست سرشماره جدید</span>
                    </button>
                </div>
            </div>

            <!-- Stats Metric Cards (Liquid Glass) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="glass-card !p-4 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block mb-1">کل درخواست‌ها</span>
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
                            در انتظار بررسی
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
                        <span class="text-xs font-bold text-rose-600 dark:text-rose-400 block mb-1">رد شده</span>
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
                        placeholder="جستجو در عنوان، شرح یا شماره..."
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
                        class="glass-input !py-2.5 !px-3 !text-xs cursor-pointer"
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
                                <th class="px-5 py-4">عنوان سرویس درخواستی</th>
                                <th class="px-5 py-4 text-center">طول ارقام</th>
                                <th class="px-5 py-4 text-center">سرشماره پیشنهادی</th>
                                <th class="px-5 py-4 text-center">سرشماره تخصیص‌یافته</th>
                                <th class="px-5 py-4 text-center">وضعیت درخواست</th>
                                <th class="px-5 py-4 text-center">تاریخ ثبت</th>
                                <th class="px-5 py-4 text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/50 dark:divide-white/5">
                            <tr
                                v-for="(req, index) in requests.data"
                                :key="req.id"
                                class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="px-5 py-4 text-center text-xs text-slate-400 font-mono">
                                    {{ (requests.current_page - 1) * 10 + index + 1 }}
                                </td>

                                <td class="px-5 py-4 font-bold text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <span>{{ req.service_title }}</span>
                                    </div>
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
                                    <span
                                        v-if="req.preferred_number"
                                        dir="ltr"
                                        class="font-mono text-xs font-bold text-slate-800 dark:text-slate-200 px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800"
                                    >
                                        {{ req.preferred_number }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div v-if="req.allocated_number" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-emerald-500/10 border border-emerald-500/25">
                                        <span dir="ltr" class="font-mono text-xs font-black text-emerald-600 dark:text-emerald-400 tracking-wider">
                                            {{ req.allocated_number.number }}
                                        </span>
                                        <button
                                            type="button"
                                            @click="copyToClipboard(req.allocated_number.number)"
                                            class="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 cursor-pointer"
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

                                        <!-- Cancel button allowed ONLY in pending status -->
                                        <button
                                            v-if="req.status === 'pending'"
                                            type="button"
                                            @click="openCancelModal(req)"
                                            class="p-1.5 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors cursor-pointer"
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
                                        <div class="w-14 h-14 rounded-2xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center mb-3">
                                            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <span class="font-bold text-sm text-slate-800 dark:text-slate-200">هیچ درخواستی یافت نشد</span>
                                        <p class="text-xs text-slate-400 mt-1 max-w-sm">
                                            شما تاکنون درخواستی برای دریافت سرشماره سازمانی ثبت نکرده‌اید یا فیلترهای انتخابی نتیجه‌ای در بر ندارند.
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
                                :class="link.active ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Request Modal (Liquid Glass) -->
        <div v-if="isCreateModalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
            <div class="glass-card !p-6 w-full max-w-lg shadow-2xl relative animate-in fade-in zoom-in-95 duration-200">
                <div class="p-4 border-b border-slate-200/60 dark:border-white/10 flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white flex items-center justify-center font-bold text-sm shadow-md shadow-purple-500/25">
                            +
                        </div>
                        <div>
                            <h3 class="font-black text-sm text-slate-900 dark:text-white">
                                ثبت درخواست سرشماره اختصاصی سازمانی
                            </h3>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">تخصیص خطوط رند با طول ۴ تا ۸ رقم</span>
                        </div>
                    </div>
                    <button type="button" @click="closeCreateModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitCreate" class="space-y-4">
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
                                class="p-2.5 rounded-xl text-center border transition-all cursor-pointer"
                                :class="[
                                    createForm.requested_length === len
                                        ? 'bg-purple-600/15 border-purple-500 text-purple-600 dark:text-purple-300 font-black shadow-xs'
                                        : 'border-slate-200 dark:border-white/10 text-slate-600 dark:text-slate-400 hover:border-slate-300'
                                ]"
                            >
                                <span class="block text-base font-mono font-black">{{ len }}</span>
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
                            class="glass-input !py-2.5 !text-xs"
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
                            placeholder="نحوه استفاده، اهداف ارتباطی و ساختار جامعه هدف را شرح دهید..."
                            class="glass-input !py-2.5 !text-xs resize-none"
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
                            <span class="text-[11px] text-slate-400 font-mono">اختیاری — {{ createForm.requested_length }} رقم عددی</span>
                        </div>
                        <input
                            v-model="createForm.preferred_number"
                            type="text"
                            dir="ltr"
                            :maxlength="createForm.requested_length"
                            :placeholder="createForm.requested_length === 4 ? '1000' : '100000'"
                            class="glass-input !py-2.5 !text-xs !font-mono text-left"
                        />
                        <span class="text-[10px] text-slate-400 block mt-1">
                            در صورت خالی بودن، سرشماره آزاد با طول {{ createForm.requested_length }} رقم به صورت خودکار تخصیص می‌یابد.
                        </span>
                        <span v-if="createForm.errors.preferred_number" class="text-xs text-rose-500 block mt-1">
                            {{ createForm.errors.preferred_number }}
                        </span>
                    </div>

                    <!-- Non-Affiliation and Terms Notice -->
                    <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 text-xs leading-relaxed space-y-2">
                        <div class="flex items-center gap-1.5 font-bold text-amber-800 dark:text-amber-300">
                            <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>بیانیه عدم وابستگی و ضوابط اختصاص سرشماره</span>
                        </div>
                        <p class="text-[11px] leading-relaxed text-slate-700 dark:text-slate-300">
                            این سامانه یک بستر کاملاً مستقل ارائه خدمات ارتباطی بوده و به هیچ حزب، گروه، سازمان‌های سیاسی، نهادهای دولتی یا حاکمیتی وابسته نبوده و هیچ‌گونه سرشماره‌ای بابت فعالیت‌های مذکور اختصاص نمی‌دهد. سرشماره‌های تخصیص‌یافته صرفاً در درگاه‌های مجاز تعیین‌شده در دسترس خواهند بود.
                        </p>
                        <label class="flex items-start gap-2 pt-1 font-semibold cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="termsAccepted"
                                required
                                class="mt-0.5 rounded border-slate-300 dark:border-slate-700 text-purple-600 focus:ring-purple-500 cursor-pointer"
                            />
                            <span class="text-[11px] text-slate-800 dark:text-slate-200">
                                ضوابط فوق و مقررات بهره‌برداری را تایید می‌نمایم.
                            </span>
                        </label>
                    </div>

                    <!-- Actions -->
                    <div class="pt-3 border-t border-slate-200/60 dark:border-white/10 flex items-center justify-end gap-2.5">
                        <button
                            type="button"
                            @click="closeCreateModal"
                            class="glass-pill !py-2 !px-4 !text-xs cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="!termsAccepted || createForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl cursor-pointer disabled:opacity-40"
                        >
                            {{ createForm.processing ? 'در حال ارسال...' : 'ثبت و ارسال درخواست' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Details Modal -->
        <div v-if="isDetailsModalOpen && selectedRequest" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
            <div class="glass-card !p-6 w-full max-w-lg shadow-2xl relative animate-in fade-in zoom-in-95 duration-200">
                <div class="p-4 border-b border-slate-200/60 dark:border-white/10 flex items-center justify-between mb-4">
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">
                            جزئیات درخواست سرشماره سازمانی
                        </h3>
                        <span class="text-[11px] text-slate-400 block font-mono">شناسه درخواست: #{{ selectedRequest.id }}</span>
                    </div>
                    <button type="button" @click="closeDetailsModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-900/50 border border-slate-200/40 dark:border-white/5">
                            <span class="text-slate-400 block text-[10px] mb-1">طول درخواستی</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ selectedRequest.requested_length }} رقم</span>
                        </div>
                        <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-900/50 border border-slate-200/40 dark:border-white/5">
                            <span class="text-slate-400 block text-[10px] mb-1">وضعیت کنونی</span>
                            <span class="font-bold" :class="selectedRequest.status_badge">{{ selectedRequest.status_label }}</span>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-900/50 border border-slate-200/40 dark:border-white/5">
                        <span class="text-slate-400 block text-[10px] mb-1">عنوان سرویس</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ selectedRequest.service_title }}</span>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-100/50 dark:bg-slate-900/50 border border-slate-200/40 dark:border-white/5">
                        <span class="text-slate-400 block text-[10px] mb-1">توضیحات و کاربرد</span>
                        <p class="text-slate-700 dark:text-slate-300 leading-relaxed">{{ selectedRequest.description }}</p>
                    </div>

                    <!-- Allocated number card if approved -->
                    <div v-if="selectedRequest.allocated_number" class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/25">
                        <span class="text-emerald-700 dark:text-emerald-400 block text-[10px] font-bold mb-1">سرشماره اختصاص‌یافته رسمی</span>
                        <div class="flex items-center justify-between">
                            <span dir="ltr" class="font-mono text-base font-black text-emerald-700 dark:text-emerald-400">
                                {{ selectedRequest.allocated_number.formatted_number }}
                            </span>
                            <button
                                type="button"
                                @click="copyToClipboard(selectedRequest.allocated_number.number)"
                                class="glass-pill !py-1 !px-2.5 !text-[10px] text-emerald-600 dark:text-emerald-400 cursor-pointer"
                            >
                                کپی شماره
                            </button>
                        </div>
                    </div>

                    <!-- Rejection reason if rejected -->
                    <div v-if="selectedRequest.rejection_reason" class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/25">
                        <span class="text-rose-700 dark:text-rose-400 block text-[10px] font-bold mb-1">دلیل عدم تأیید توسط مدیریت</span>
                        <p class="text-rose-900 dark:text-rose-200 leading-relaxed">{{ selectedRequest.rejection_reason }}</p>
                    </div>

                    <!-- Reviewer details if reviewed -->
                    <div v-if="selectedRequest.reviewed_at" class="text-[11px] text-slate-400 pt-2 border-t border-slate-200/50 dark:border-white/5 flex items-center justify-between font-mono">
                        <span>بررسی‌کننده: {{ selectedRequest.reviewer_name || 'راهبر سامانه' }}</span>
                        <span>تاریخ: {{ selectedRequest.reviewed_at }}</span>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-200/60 dark:border-white/10 flex justify-end mt-4">
                    <button type="button" @click="closeDetailsModal" class="glass-pill !py-2 !px-5 !text-xs cursor-pointer">
                        بستن
                    </button>
                </div>
            </div>
        </div>

        <!-- Cancel Request Modal -->
        <div v-if="isCancelModalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
            <div class="glass-card !p-6 w-full max-w-md shadow-2xl text-center relative animate-in fade-in zoom-in-95 duration-200">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-4 mx-auto">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>

                <h3 class="font-black text-sm text-slate-900 dark:text-white mb-2">
                    تأیید لغو درخواست سرشماره
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-5 leading-relaxed">
                    آیا از لغو درخواست سرشماره سازمانی «<b>{{ requestToCancel?.service_title }}</b>» اطمینان دارید؟ این درخواست از صف بررسی مدیریت حذف خواهد گردید.
                </p>

                <div class="flex items-center justify-center gap-3">
                    <button
                        type="button"
                        @click="closeCancelModal"
                        class="glass-pill !py-2.5 !px-5 !text-xs cursor-pointer"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmCancel"
                        :disabled="cancelProcessing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/25 transition-all disabled:opacity-50 cursor-pointer"
                    >
                        {{ cancelProcessing ? 'در حال لغو...' : 'تأیید و لغو درخواست' }}
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
