<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface UserItem {
    id: number;
    name: string;
    username: string;
    role: string;
    status: string;
    wallet_balance: string;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    users: {
        data: UserItem[];
        links: PaginationLink[];
        total: number;
    };
    stats: {
        total_circulation: string;
        total_wallets: number;
        total_transactions: number;
    };
    rates: {
        trx_to_coin_rate: string;
        number_price_coin: string;
    };
    filters: {
        search?: string;
    };
}>();

// Search filter
const search = ref(props.filters.search || '');

const applySearch = () => {
    router.get(
        route('admin.wallets.index'),
        { search: search.value || undefined },
        { preserveState: true, replace: true }
    );
};

const clearSearch = () => {
    search.value = '';
    router.get(route('admin.wallets.index'), {}, { preserveState: true, replace: true });
};

// Settings Form
const settingsForm = useForm({
    trx_to_coin_rate: props.rates.trx_to_coin_rate,
    number_price_coin: props.rates.number_price_coin,
});

const submitSettings = () => {
    settingsForm.post(route('admin.wallets.settings'), {
        preserveScroll: true,
    });
};

// Modal for Credit / Debit
const isModalOpen = ref(false);
const modalMode = ref<'credit' | 'debit'>('credit');
const selectedUser = ref<UserItem | null>(null);

const balanceForm = useForm({
    amount: '',
    reason: '',
});

const openBalanceModal = (user: UserItem, mode: 'credit' | 'debit') => {
    selectedUser.value = user;
    modalMode.value = mode;
    balanceForm.reset();
    balanceForm.clearErrors();
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    selectedUser.value = null;
    balanceForm.reset();
    balanceForm.clearErrors();
};

const submitBalanceAdjustment = () => {
    if (!selectedUser.value) return;

    const url = modalMode.value === 'credit'
        ? route('admin.wallets.credit', selectedUser.value.id)
        : route('admin.wallets.debit', selectedUser.value.id);

    balanceForm.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
    });
};
</script>

<template>
    <Head title="مدیریت مالی و کیف‌پول‌ها" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"></span>
                        مدیریت مالی، کیف‌پول‌ها و اعتبار کوین
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        مشاهده گردش حساب کاربران، تنظیم نرخ ارز و کسر/افزایش اعتبار با لاگ امنیتی
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.wallets.transactions')"
                        class="px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md shadow-indigo-600/20 flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        <span>دفترکل تراکنش‌ها (Ledger)</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- 1. STATS METRIC CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">مجموع کوین در گردش سامانه</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-amber-500 dark:text-amber-400 mt-1">
                            {{ stats.total_circulation }}
                            <span class="text-xs font-bold text-slate-400 mr-1">COIN</span>
                        </div>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-amber-500/15 text-amber-500 flex items-center justify-center font-black text-xl shrink-0">
                        🪙
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">تعداد کیف‌پول‌های فعال</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-indigo-500 dark:text-indigo-400 mt-1">
                            {{ stats.total_wallets }}
                            <span class="text-xs font-bold text-slate-400 mr-1">حساب</span>
                        </div>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-indigo-500/15 text-indigo-500 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <rect x="2" y="5" width="20" height="14" rx="2" stroke-width="2" />
                            <line x1="2" y1="10" x2="22" y2="10" stroke-width="2" />
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">تعداد تراکنش‌های ثبتی دفترکل</span>
                        <div class="text-xl sm:text-2xl font-black font-mono text-emerald-500 dark:text-emerald-400 mt-1">
                            {{ stats.total_transactions }}
                            <span class="text-xs font-bold text-slate-400 mr-1">سند</span>
                        </div>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-emerald-500/15 text-emerald-500 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- 2. SYSTEM SETTINGS CARD (RATES & PRICING) -->
            <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                    </svg>
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-900 dark:text-white">پیکربندی نرخ تبدیل و قیمت خطوط</h2>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">تغییرات نرخ مستقیماً بر محاسبه واریز و هزینه کسر خرید خط تأثیر می‌گذارد.</p>
                    </div>
                </div>

                <form @submit.prevent="submitSettings" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            نرخ هر ترون (TRX) به کوین (COIN)
                        </label>
                        <div class="relative">
                            <input
                                v-model="settingsForm.trx_to_coin_rate"
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                required
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                            />
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">COIN</span>
                        </div>
                        <p v-if="settingsForm.errors.trx_to_coin_rate" class="text-rose-500 text-[11px] mt-1">{{ settingsForm.errors.trx_to_coin_rate }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            قیمت پایه خرید شماره (به COIN)
                        </label>
                        <div class="relative">
                            <input
                                v-model="settingsForm.number_price_coin"
                                type="number"
                                step="0.0001"
                                min="0"
                                required
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                            />
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">COIN</span>
                        </div>
                        <p v-if="settingsForm.errors.number_price_coin" class="text-rose-500 text-[11px] mt-1">{{ settingsForm.errors.number_price_coin }}</p>
                    </div>

                    <div>
                        <button
                            type="submit"
                            :disabled="settingsForm.processing"
                            class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-xs font-bold transition-all shadow-md shadow-indigo-600/20"
                        >
                            <span v-if="settingsForm.processing">در حال ذخیره...</span>
                            <span v-else>ذخیره تنظیمات نرخ</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- 3. USERS WALLET BALANCES TABLE -->
            <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                        <h2 class="text-sm font-extrabold text-slate-900 dark:text-white">کیف‌پول کاربران و موجودی‌ها</h2>
                        <span class="text-xs text-slate-400 font-mono">({{ users.total }} کاربر)</span>
                    </div>

                    <!-- Search Filter -->
                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <input
                                v-model="search"
                                @keyup.enter="applySearch"
                                type="text"
                                placeholder="جستجو بر اساس نام یا نام کاربری..."
                                class="w-64 px-3.5 py-1.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
                            />
                        </div>
                        <button
                            type="button"
                            @click="applySearch"
                            class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                        >
                            جستجو
                        </button>
                        <button
                            v-if="search"
                            type="button"
                            @click="clearSearch"
                            class="px-2 py-1.5 text-xs font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        >
                            پاک‌سازی
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="border-b border-slate-200 dark:border-slate-800 text-slate-400">
                            <tr>
                                <th class="py-3 px-3 font-bold">شناسه</th>
                                <th class="py-3 px-3 font-bold">کاربر</th>
                                <th class="py-3 px-3 font-bold">نقش</th>
                                <th class="py-3 px-3 font-bold">وضعیت</th>
                                <th class="py-3 px-3 font-bold">موجودی کیف‌پول (COIN)</th>
                                <th class="py-3 px-3 font-bold">تاریخ عضویت</th>
                                <th class="py-3 px-3 font-bold text-center">عملیات حساب</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-3 font-mono text-slate-400">#{{ user.id }}</td>
                                <td class="py-3 px-3 font-medium text-slate-900 dark:text-white">
                                    <div class="font-bold">{{ user.name }}</div>
                                    <div class="font-mono text-[11px] text-slate-400">{{ user.username }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="user.role === 'admin' ? 'bg-rose-500/15 text-rose-500' : 'bg-slate-500/15 text-slate-400'">
                                        {{ user.role === 'admin' ? 'راهبر' : 'کاربر عادی' }}
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="user.status === 'active' ? 'bg-emerald-500/15 text-emerald-500' : 'bg-rose-500/15 text-rose-500'">
                                        {{ user.status === 'active' ? 'فعال' : 'غیرفعال' }}
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="font-mono text-sm font-extrabold text-amber-500 dark:text-amber-400">
                                        {{ user.wallet_balance }}
                                    </span>
                                    <span class="text-[10px] font-bold text-slate-400 mr-1">COIN</span>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-400">{{ user.created_at }}</td>
                                <td class="py-3 px-3">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button
                                            type="button"
                                            @click="openBalanceModal(user, 'credit')"
                                            class="px-2.5 py-1 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold border border-emerald-500/30 transition-all flex items-center gap-1"
                                            title="شارژ دستی (Credit)"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                                            </svg>
                                            <span>افزایش</span>
                                        </button>

                                        <button
                                            type="button"
                                            @click="openBalanceModal(user, 'debit')"
                                            class="px-2.5 py-1 rounded-lg bg-rose-500/15 hover:bg-rose-500/25 text-rose-600 dark:text-rose-400 text-[11px] font-bold border border-rose-500/30 transition-all flex items-center gap-1"
                                            title="کسر دستی (Debit)"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 12H4" />
                                            </svg>
                                            <span>کسر</span>
                                        </button>

                                        <Link
                                            :href="route('admin.wallets.transactions', { user_id: user.id })"
                                            class="p-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white transition-all"
                                            title="مشاهده گردش حساب این کاربر"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                            </svg>
                                        </Link>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="users.data.length === 0">
                                <td colspan="7" class="py-8 text-center text-slate-400">
                                    کاربری با این مشخصات یافت نشد.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users.links.length > 3" class="flex items-center justify-center gap-1 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <template v-for="(link, idx) in users.links" :key="idx">
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

        <!-- 4. CREDIT / DEBIT MODAL WITH AUDIT LOGGING -->
        <teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm" @click="closeModal"></div>

                <div class="relative w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl p-6 overflow-hidden z-10">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span
                                class="w-2.5 h-2.5 rounded-full"
                                :class="modalMode === 'credit' ? 'bg-emerald-500' : 'bg-rose-500'"
                            ></span>
                            {{ modalMode === 'credit' ? 'افزایش اعتبار کاربر (Credit)' : 'کسر اعتبار کاربر (Debit)' }}
                        </h3>
                        <button type="button" @click="closeModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            ✕
                        </button>
                    </div>

                    <div v-if="selectedUser" class="my-4 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 text-xs space-y-1">
                        <div class="flex justify-between">
                            <span class="text-slate-500">کاربر مقصد:</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ selectedUser.name }} ({{ selectedUser.username }})</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">موجودی فعلی:</span>
                            <span class="font-mono font-bold text-amber-500">{{ selectedUser.wallet_balance }} COIN</span>
                        </div>
                    </div>

                    <form @submit.prevent="submitBalanceAdjustment" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                مبلغ تغییر (به COIN) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                v-model="balanceForm.amount"
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                required
                                placeholder="مثال: 10.0000"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                            />
                            <p v-if="balanceForm.errors.amount" class="text-rose-500 text-[11px] mt-1">{{ balanceForm.errors.amount }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                دلیل ثبت تغییر (اجباری جهت لاگ امنیتی) <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="balanceForm.reason"
                                rows="3"
                                required
                                placeholder="علت شارژ یا کسر اعتبار را به دقت شرح دهید..."
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none resize-none"
                            ></textarea>
                            <p v-if="balanceForm.errors.reason" class="text-rose-500 text-[11px] mt-1">{{ balanceForm.errors.reason }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="closeModal"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="balanceForm.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-white transition-all shadow-md disabled:opacity-50"
                                :class="modalMode === 'credit' ? 'bg-emerald-600 hover:bg-emerald-500 shadow-emerald-600/20' : 'bg-rose-600 hover:bg-rose-500 shadow-rose-600/20'"
                            >
                                <span v-if="balanceForm.processing">در حال ثبت...</span>
                                <span v-else>{{ modalMode === 'credit' ? 'تأیید افزایش موجودی' : 'تأیید کسر موجودی' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </AdminLayout>
</template>

