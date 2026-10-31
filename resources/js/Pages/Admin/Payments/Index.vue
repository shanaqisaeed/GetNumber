<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface DepositUser {
    id: number;
    name: string;
    username: string;
}

interface DepositItem {
    id: number;
    user: DepositUser;
    tracking_code: string;
    gateway_driver: string;
    currency: string;
    network: string;
    requested_amount: string;
    actual_amount: string;
    coin_amount: string;
    deposit_address: string;
    tx_hash: string | null;
    status: string;
    status_label: string;
    status_badge: string;
    attempts: number;
    failure_reason: string | null;
    created_at: string;
    completed_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    deposits: {
        data: DepositItem[];
        links: PaginationLink[];
        total: number;
    };
    stats: {
        total_completed_trx: string;
        total_completed_coins: string;
        pending_count: number;
        completed_count: number;
    };
    statuses: Array<{ value: string; label: string }>;
    gateways: Array<{ id: number; name: string; driver: string }>;
    filters: {
        status?: string;
        gateway?: string;
        user_id?: string | number;
        search?: string;
    };
}>();

// Filter States
const search = ref(props.filters.search || '');
const selectedStatus = ref(props.filters.status || '');
const selectedGateway = ref(props.filters.gateway || '');
const selectedUserId = ref(props.filters.user_id || '');

const applyFilters = () => {
    router.get(
        route('admin.payments.index'),
        {
            search: search.value || undefined,
            status: selectedStatus.value || undefined,
            gateway: selectedGateway.value || undefined,
            user_id: selectedUserId.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = '';
    selectedGateway.value = '';
    selectedUserId.value = '';
    router.get(route('admin.payments.index'), {}, { preserveState: true, replace: true });
};

// Re-check / Verify
const isVerifying = ref<number | null>(null);
const verifyDeposit = (deposit: DepositItem) => {
    isVerifying.value = deposit.id;
    router.post(route('admin.payments.verify', deposit.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            isVerifying.value = null;
        },
    });
};

// Manual Approval Modal
const isManualModalOpen = ref(false);
const depositToApprove = ref<DepositItem | null>(null);

const manualForm = useForm({
    reason: '',
    tx_hash: '',
});

const openManualModal = (d: DepositItem) => {
    depositToApprove.value = d;
    manualForm.reset();
    manualForm.tx_hash = d.tx_hash || '';
    isManualModalOpen.value = true;
};

const closeManualModal = () => {
    isManualModalOpen.value = false;
    depositToApprove.value = null;
    manualForm.reset();
};

const submitManualApproval = () => {
    if (!depositToApprove.value) return;

    manualForm.post(route('admin.payments.complete-manually', depositToApprove.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeManualModal();
        },
    });
};
</script>

<template>
    <Head title="مدیریت واریزی‌های کریپتو" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        مدیریت درگاه‌های پرداخت و واریزی‌های ترون (TRX)
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        نظارت بر درگاه نوبیتکس، استعلام TX Hash از بلاکچین، تایید خودکار و شارژ کیف پول
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.payments.gateways')"
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>پیکربندی درگاه‌ها</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- 1. STATS METRICS GRID -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">مجموع ترون دریافتی</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-emerald-500 dark:text-emerald-400 mt-1">
                            {{ stats.total_completed_trx }}
                            <span class="text-xs font-bold text-slate-400 mr-1">TRX</span>
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-500 flex items-center justify-center font-black text-lg">
                        💎
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">کوین‌های شارژشده</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-amber-500 dark:text-amber-400 mt-1">
                            {{ stats.total_completed_coins }}
                            <span class="text-xs font-bold text-slate-400 mr-1">COIN</span>
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-500 flex items-center justify-center font-black text-lg">
                        🪙
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">تراکنش‌های موفق</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-indigo-500 dark:text-indigo-400 mt-1">
                            {{ stats.completed_count }}
                            <span class="text-xs font-bold text-slate-400 mr-1">مورد</span>
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/15 text-indigo-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">در صف بررسی و تأیید</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-amber-500 mt-1">
                            {{ stats.pending_count }}
                            <span class="text-xs font-bold text-slate-400 mr-1">درخواست</span>
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-500 flex items-center justify-center font-bold">
                        ⏳
                    </div>
                </div>
            </div>

            <!-- 2. FILTER & SEARCH BAR -->
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                    <div class="sm:col-span-2">
                        <input
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="جستجو در کد رهگیری، TX Hash، آدرس یا کاربر..."
                            class="w-full px-3.5 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
                        />
                    </div>

                    <div>
                        <select
                            v-model="selectedStatus"
                            @change="applyFilters"
                            class="w-full px-3.5 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">
                                {{ s.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <select
                            v-model="selectedGateway"
                            @change="applyFilters"
                            class="w-full px-3.5 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
                        >
                            <option value="">همه درگاه‌ها</option>
                            <option v-for="g in gateways" :key="g.driver" :value="g.driver">
                                {{ g.name }}
                            </option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="applyFilters"
                            class="flex-1 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md shadow-indigo-600/20"
                        >
                            فیلتر
                        </button>
                        <button
                            v-if="search || selectedStatus || selectedGateway || selectedUserId"
                            type="button"
                            @click="resetFilters"
                            class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold transition-all"
                        >
                            پاک‌سازی
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. DEPOSITS TABLE -->
            <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <h2 class="text-sm font-extrabold text-slate-900 dark:text-white">درخواست‌های واریز ترون</h2>
                        <span class="text-xs text-slate-400 font-mono">({{ deposits.total }} رکورد)</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="border-b border-slate-200 dark:border-slate-800 text-slate-400">
                            <tr>
                                <th class="py-3 px-3 font-bold">شناسه / کد رهگیری</th>
                                <th class="py-3 px-3 font-bold">کاربر</th>
                                <th class="py-3 px-3 font-bold">درگاه / شبکه</th>
                                <th class="py-3 px-3 font-bold">مبلغ (TRX)</th>
                                <th class="py-3 px-3 font-bold">کوین معادل</th>
                                <th class="py-3 px-3 font-bold">TX Hash</th>
                                <th class="py-3 px-3 font-bold">وضعیت</th>
                                <th class="py-3 px-3 font-bold">تاریخ ثبت</th>
                                <th class="py-3 px-3 font-bold text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                            <tr v-for="d in deposits.data" :key="d.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-3">
                                    <Link
                                        :href="route('admin.payments.show', d.id)"
                                        class="font-mono font-bold text-indigo-600 dark:text-indigo-400 hover:underline block"
                                    >
                                        {{ d.tracking_code }}
                                    </Link>
                                    <span class="font-mono text-[10px] text-slate-400">#{{ d.id }}</span>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-bold text-slate-900 dark:text-white">{{ d.user.name }}</div>
                                    <div class="font-mono text-[10px] text-slate-400">{{ d.user.username }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-[10px] font-bold">
                                        {{ d.gateway_driver }} ({{ d.network }})
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="font-mono font-black text-sm text-emerald-500">
                                        {{ d.actual_amount }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 mr-1">TRX</span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="font-mono font-black text-sm text-amber-500">
                                        {{ d.coin_amount }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 mr-1">COIN</span>
                                </td>
                                <td class="py-3 px-3 max-w-[140px] truncate">
                                    <span v-if="d.tx_hash" class="font-mono text-[10px] select-all text-slate-600 dark:text-slate-300" :title="d.tx_hash">
                                        {{ d.tx_hash.substring(0, 10) }}...{{ d.tx_hash.substring(d.tx_hash.length - 8) }}
                                    </span>
                                    <span v-else class="text-[10px] text-slate-400 italic">ثبت‌نشده</span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold border" :class="d.status_badge">
                                        {{ d.status_label }}
                                    </span>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-400">{{ d.created_at }}</td>
                                <td class="py-3 px-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <!-- Verify Button if TX Hash exists and not completed -->
                                        <button
                                            v-if="d.tx_hash && d.status !== 'completed'"
                                            type="button"
                                            @click="verifyDeposit(d)"
                                            :disabled="isVerifying === d.id"
                                            class="p-1.5 rounded-lg bg-blue-500/15 hover:bg-blue-500/25 text-blue-500 transition-all disabled:opacity-50"
                                            title="استعلام مجدد از شبکه"
                                        >
                                            <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': isVerifying === d.id }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                        </button>

                                        <!-- Manual Approval Button if not completed -->
                                        <button
                                            v-if="d.status !== 'completed'"
                                            type="button"
                                            @click="openManualModal(d)"
                                            class="px-2 py-1 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-600 dark:text-emerald-400 text-[10px] font-bold border border-emerald-500/30 transition-all"
                                            title="تأیید دستی"
                                        >
                                            تأیید دستی
                                        </button>

                                        <!-- Inspect Link -->
                                        <Link
                                            :href="route('admin.payments.show', d.id)"
                                            class="p-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white transition-all"
                                            title="مشاهده جزئیات کامل"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </Link>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="deposits.data.length === 0">
                                <td colspan="9" class="py-8 text-center text-slate-400">
                                    تراکنشی با این مشخصات یافت نشد.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="deposits.links.length > 3" class="flex items-center justify-center gap-1 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <template v-for="(link, idx) in deposits.links" :key="idx">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-3 py-1.5 rounded-lg text-xs font-mono transition-all"
                            :class="link.active ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="px-3 py-1.5 rounded-lg text-xs font-mono text-slate-400 opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- 4. MANUAL APPROVAL MODAL -->
        <teleport to="body">
            <div v-if="isManualModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm" @click="closeManualModal"></div>

                <div class="relative w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl p-6 overflow-hidden z-10">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            تأیید دستی تراکنش و شارژ کیف پول
                        </h3>
                        <button type="button" @click="closeManualModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            ✕
                        </button>
                    </div>

                    <div v-if="depositToApprove" class="my-4 p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-xs space-y-1">
                        <div class="flex justify-between">
                            <span class="text-slate-500">کد رهگیری:</span>
                            <span class="font-mono font-bold text-indigo-400">{{ depositToApprove.tracking_code }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">کاربر:</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ depositToApprove.user.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">مبلغ شارژ:</span>
                            <span class="font-mono font-bold text-amber-500">{{ depositToApprove.coin_amount }} COIN ({{ depositToApprove.requested_amount }} TRX)</span>
                        </div>
                    </div>

                    <form @submit.prevent="submitManualApproval" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                شناسه تراکنش بلاکچین (TX Hash)
                            </label>
                            <input
                                v-model="manualForm.tx_hash"
                                type="text"
                                placeholder="در صورت وجود TX Hash وارد نمایید..."
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-slate-900 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                دلیل تأیید دستی (اجباری جهت لاگ امنیتی) <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="manualForm.reason"
                                rows="3"
                                required
                                placeholder="دلیل تأیید دستی و تطبیق تراکنش را شرح دهید..."
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none resize-none text-slate-900 dark:text-white"
                            ></textarea>
                            <p v-if="manualForm.errors.reason" class="text-rose-500 text-[11px] mt-1">{{ manualForm.errors.reason }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="closeManualModal"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="manualForm.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-600/20 disabled:opacity-50 transition-all"
                            >
                                <span v-if="manualForm.processing">در حال اعمال...</span>
                                <span v-else>تأیید و شارژ کیف پول کاربر</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </AdminLayout>
</template>

