<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface UserInfo {
    id: number;
    account_id: string;
    name: string;
    username: string;
    wallet_balance: string;
}

interface OrderItem {
    id: number;
    order_number: string;
    user: UserInfo;
    service_key: string;
    service_title: string;
    requested_identifier: string;
    status: 'pending' | 'completed' | 'rejected' | 'referred' | string;
    status_label: string;
    status_badge: string;
    unit_price: string;
    final_amount: string;
    held_amount: string;
    rejection_reason: string | null;
    admin_note: string | null;
    reviewed_at: string | null;
    created_at: string;
    created_at_human: string;
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
    orders: PaginatedData<OrderItem>;
    pending_count: number;
    filters: {
        search?: string;
        status?: string;
        service_key?: string;
    };
    services: Array<{ id: number; key: string; title: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const search = ref(props.filters.search || '');
const selectedStatus = ref(props.filters.status || '');
const selectedService = ref(props.filters.service_key || '');

const applyFilters = () => {
    router.get(
        route('admin.orders.index'),
        {
            search: search.value || undefined,
            status: selectedStatus.value || undefined,
            service_key: selectedService.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const clearFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    selectedService.value = '';
    applyFilters();
};

// Modals State
const activeOrder = ref<OrderItem | null>(null);
const isApproveModalOpen = ref(false);
const isRejectModalOpen = ref(false);
const isReferModalOpen = ref(false);

const approveForm = useForm({});
const rejectForm = useForm({
    reason: '',
});
const referForm = useForm({
    note: '',
});

const openApproveModal = (order: OrderItem) => {
    activeOrder.value = order;
    isApproveModalOpen.value = true;
};

const submitApprove = () => {
    if (!activeOrder.value) return;
    approveForm.post(route('admin.orders.approve', activeOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isApproveModalOpen.value = false;
            activeOrder.value = null;
        },
    });
};

const openRejectModal = (order: OrderItem) => {
    activeOrder.value = order;
    rejectForm.reason = '';
    isRejectModalOpen.value = true;
};

const submitReject = () => {
    if (!activeOrder.value) return;
    rejectForm.post(route('admin.orders.reject', activeOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isRejectModalOpen.value = false;
            activeOrder.value = null;
        },
    });
};

const openReferModal = (order: OrderItem) => {
    activeOrder.value = order;
    referForm.note = '';
    isReferModalOpen.value = true;
};

const submitRefer = () => {
    if (!activeOrder.value) return;
    referForm.post(route('admin.orders.refer', activeOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isReferModalOpen.value = false;
            activeOrder.value = null;
        },
    });
};

const formatCurrency = (val: string | number) => {
    return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(Number(val) || 0);
};
</script>

<template>
    <Head title="کنسول یکپارچه بررسی سفارشات" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        کنسول یکپارچه بررسی و نظارت سفارشات
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        بررسی، تایید خودکار/دستی، استرداد وجه و ارجاع سفارش‌های سرشماره، آیدی و دامنه‌های Web3
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-sm font-semibold bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800/50">
                        <span class="w-2 h-2 rounded-full bg-amber-500 animate-ping"></span>
                        {{ pending_count }} سفارش در انتظار بررسی
                    </span>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <form @submit.prevent="applyFilters" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="relative">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="جستجو با شماره سفارش، شناسه، نام کاربر یا Account ID..."
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500 pr-10"
                        />
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <div>
                        <select
                            v-model="selectedStatus"
                            @change="applyFilters"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option v-for="st in statuses" :key="st.value" :value="st.value">{{ st.label }}</option>
                        </select>
                    </div>

                    <div>
                        <select
                            v-model="selectedService"
                            @change="applyFilters"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        >
                            <option value="">همه دسته‌بندی‌های سرویس</option>
                            <option v-for="srv in services" :key="srv.id" :value="srv.key">{{ srv.title }}</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="submit"
                            class="flex-1 px-4 py-2 text-sm font-medium rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 transition-colors"
                        >
                            اعمال فیلتر
                        </button>
                        <button
                            type="button"
                            @click="clearFilters"
                            class="px-3 py-2 text-sm font-medium rounded-xl text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 transition-colors"
                        >
                            بازنشانی
                        </button>
                    </div>
                </form>
            </div>

            <!-- Orders Table -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-gray-50/75 dark:bg-gray-700/50 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700">
                            <tr>
                                <th class="p-4">شماره سفارش / زمان</th>
                                <th class="p-4">کاربر متقاضی (Account ID)</th>
                                <th class="p-4">سرویس و شناسه درخواستی</th>
                                <th class="p-4">مبلغ نهایی (کوین)</th>
                                <th class="p-4">وضعیت</th>
                                <th class="p-4 text-center">اقدامات مدیریتی</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-if="orders.data.length === 0">
                                <td colspan="6" class="p-8 text-center text-gray-400">
                                    هیچ سفارشی مطابق معیارهای جستجو یافت نشد.
                                </td>
                            </tr>
                            <tr
                                v-for="order in orders.data"
                                :key="order.id"
                                class="hover:bg-gray-50/50 dark:hover:bg-gray-700/40 transition-colors"
                            >
                                <td class="p-4">
                                    <div class="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                        {{ order.order_number }}
                                    </div>
                                    <div class="text-xs text-gray-400 mt-0.5">
                                        {{ order.created_at_human }} ({{ order.created_at }})
                                    </div>
                                </td>

                                <td class="p-4">
                                    <div class="font-medium text-gray-900 dark:text-white">
                                        {{ order.user.name }}
                                    </div>
                                    <div class="text-xs font-mono text-gray-500 dark:text-gray-400">
                                        {{ order.user.account_id }}
                                    </div>
                                </td>

                                <td class="p-4">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs font-semibold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                            {{ order.service_title }}
                                        </span>
                                    </div>
                                    <div class="text-sm font-bold text-gray-900 dark:text-white mt-1 font-mono">
                                        {{ order.requested_identifier }}
                                    </div>
                                </td>

                                <td class="p-4">
                                    <div class="font-bold text-gray-900 dark:text-white font-mono">
                                        {{ formatCurrency(order.final_amount) }}
                                    </div>
                                    <div v-if="Number(order.held_amount) > 0" class="text-xs text-amber-600">
                                        بلاک: {{ formatCurrency(order.held_amount) }}
                                    </div>
                                </td>

                                <td class="p-4">
                                    <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold" :class="order.status_badge">
                                        {{ order.status_label }}
                                    </span>
                                    <div v-if="order.rejection_reason" class="text-xs text-rose-500 mt-1 max-w-xs truncate" :title="order.rejection_reason">
                                        علت رد: {{ order.rejection_reason }}
                                    </div>
                                    <div v-if="order.admin_note" class="text-xs text-gray-400 mt-0.5 max-w-xs truncate" :title="order.admin_note">
                                        یادداشت: {{ order.admin_note }}
                                    </div>
                                </td>

                                <td class="p-4 text-center">
                                    <div v-if="order.status === 'pending' || order.status === 'held' || order.status === 'reviewing'" class="flex items-center justify-center gap-1.5">
                                        <button
                                            type="button"
                                            @click="openApproveModal(order)"
                                            class="px-2.5 py-1 text-xs font-medium rounded-lg text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-300 dark:hover:bg-emerald-900/50 transition-colors"
                                            title="تایید و تخصیص مستقیم سرویس"
                                        >
                                            تایید و فعال‌سازی
                                        </button>
                                        <button
                                            type="button"
                                            @click="openRejectModal(order)"
                                            class="px-2.5 py-1 text-xs font-medium rounded-lg text-rose-700 bg-rose-50 hover:bg-rose-100 dark:bg-rose-900/30 dark:text-rose-300 dark:hover:bg-rose-900/50 transition-colors"
                                            title="رد سفارش و استرداد وجه"
                                        >
                                            رد سفارش
                                        </button>
                                        <button
                                            type="button"
                                            @click="openReferModal(order)"
                                            class="px-2.5 py-1 text-xs font-medium rounded-lg text-amber-700 bg-amber-50 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-300 dark:hover:bg-amber-900/50 transition-colors"
                                            title="ارجاع جهت اصلاح متقاضی"
                                        >
                                            ارجاع
                                        </button>
                                    </div>
                                    <div v-else class="text-xs text-gray-400">
                                        بررسی شده در {{ order.reviewed_at || order.created_at }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="orders.links && orders.links.length > 3" class="p-4 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <div class="text-xs text-gray-500">
                        نمایش {{ orders.data.length }} از {{ orders.total }} سفارش ثبت‌شده
                    </div>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, i) in orders.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1 text-xs rounded-lg transition-colors"
                                :class="link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200'"
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-3 py-1 text-xs rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- 1. Approve Modal -->
        <div v-if="isApproveModalOpen && activeOrder" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100 dark:border-gray-700">
                <div class="w-12 h-12 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">تایید و فعال‌سازی سفارش</h3>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                    آیا از تایید سفارش <span class="font-mono font-bold text-indigo-600">{{ activeOrder.order_number }}</span> اطمینان دارید؟ با تایید این سفارش، منبع درخواستی (<span class="font-bold text-gray-900 dark:text-white">{{ activeOrder.requested_identifier }}</span>) به صورت آنی به کاربر <span class="font-bold text-gray-900 dark:text-white">{{ activeOrder.user.name }}</span> تخصیص می‌یابد.
                </p>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="isApproveModalOpen = false"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        :disabled="approveForm.processing"
                        @click="submitApprove"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {{ approveForm.processing ? 'در حال فعال‌سازی...' : 'تایید قطعی و فعال‌سازی' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- 2. Reject Modal (with Refund) -->
        <div v-if="isRejectModalOpen && activeOrder" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100 dark:border-gray-700">
                <div class="w-12 h-12 rounded-2xl bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">رد سفارش و بازگشت وجه</h3>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    با رد این سفارش، مبلغ بلاک‌شده (<span class="font-bold text-gray-900 dark:text-white">{{ formatCurrency(activeOrder.held_amount) }} کوین</span>) بلافاصله به کیف‌پول کاربر بازگردانده می‌شود.
                </p>
                <div class="mt-4">
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">علت رد سفارش (الزامی)</label>
                    <textarea
                        v-model="rejectForm.reason"
                        rows="3"
                        required
                        placeholder="لطفاً دلیل عدم تایید را برای اطلاع کاربر بنویسید..."
                        class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-rose-500 focus:border-rose-500"
                    ></textarea>
                    <div v-if="rejectForm.errors.reason" class="text-xs text-rose-500 mt-1">
                        {{ rejectForm.errors.reason }}
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="isRejectModalOpen = false"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        :disabled="rejectForm.processing || !rejectForm.reason"
                        @click="submitReject"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50"
                    >
                        {{ rejectForm.processing ? 'در حال رد...' : 'رد سفارش و استرداد وجه' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- 3. Refer Modal -->
        <div v-if="isReferModalOpen && activeOrder" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100 dark:border-gray-700">
                <div class="w-12 h-12 rounded-2xl bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">ارجاع سفارش جهت اصلاح کاربر</h3>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    سفارش به حالت تعلیق نگه داشته شده و کاربر می‌تواند اطلاعات خواسته شده را ویرایش نماید.
                </p>
                <div class="mt-4">
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">یادداشت ارجاع برای کاربر (الزامی)</label>
                    <textarea
                        v-model="referForm.note"
                        rows="3"
                        required
                        placeholder="موارد مورد نیاز جهت ویرایش یا تکمیل مدارک را قید کنید..."
                        class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-amber-500 focus:border-amber-500"
                    ></textarea>
                    <div v-if="referForm.errors.note" class="text-xs text-rose-500 mt-1">
                        {{ referForm.errors.note }}
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="isReferModalOpen = false"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        :disabled="referForm.processing || !referForm.note"
                        @click="submitRefer"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-white bg-amber-600 hover:bg-amber-700 disabled:opacity-50"
                    >
                        {{ referForm.processing ? 'در حال ارجاع...' : 'ثبت ارجاع' }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

