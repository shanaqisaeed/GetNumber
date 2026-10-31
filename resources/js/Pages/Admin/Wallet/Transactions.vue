<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TransactionUser {
    id: number;
    name: string;
    username: string;
}

interface TransactionItem {
    id: number;
    user: TransactionUser;
    type: string;
    type_label: string;
    type_badge: string;
    is_credit: boolean;
    amount: string;
    balance_before: string;
    balance_after: string;
    reference_id: string;
    description: string | null;
    metadata: Record<string, any> | null;
    created_at: string;
    created_at_relative: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    transactions: {
        data: TransactionItem[];
        links: PaginationLink[];
        total: number;
    };
    transaction_types: Array<{ value: string; label: string }>;
    filters: {
        type?: string;
        user_id?: string | number;
        search?: string;
    };
}>();

// Filter states
const search = ref(props.filters.search || '');
const selectedType = ref(props.filters.type || '');
const selectedUserId = ref(props.filters.user_id || '');

const applyFilters = () => {
    router.get(
        route('admin.wallets.transactions'),
        {
            search: search.value || undefined,
            type: selectedType.value || undefined,
            user_id: selectedUserId.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilters = () => {
    search.value = '';
    selectedType.value = '';
    selectedUserId.value = '';
    router.get(route('admin.wallets.transactions'), {}, { preserveState: true, replace: true });
};

// Reversal Modal State
const isReversalModalOpen = ref(false);
const transactionToReverse = ref<TransactionItem | null>(null);

const reversalForm = useForm({
    reason: '',
});

const openReversalModal = (tx: TransactionItem) => {
    transactionToReverse.value = tx;
    reversalForm.reset();
    reversalForm.clearErrors();
    isReversalModalOpen.value = true;
};

const closeReversalModal = () => {
    isReversalModalOpen.value = false;
    transactionToReverse.value = null;
    reversalForm.reset();
    reversalForm.clearErrors();
};

const submitReversal = () => {
    if (!transactionToReverse.value) return;

    reversalForm.post(route('admin.wallets.reverse', transactionToReverse.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeReversalModal();
        },
    });
};
</script>

<template>
    <Head title="دفترکل تراکنش‌های مالی" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 shadow-sm shadow-indigo-500/50"></span>
                        دفترکل جامع تراکنش‌های مالی (Ledger)
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        سوابق تغییرناپذیر حسابداری، شارژ، خرید خطوط و اصلاحات مالی سیستم
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.wallets.index')"
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>بازگشت به کیف‌پول‌ها</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- 1. FILTER & SEARCH BAR -->
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <input
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="جستجو در کد مرجع، نام کاربر یا شرح سند..."
                            class="w-full px-3.5 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
                        />
                    </div>

                    <div>
                        <select
                            v-model="selectedType"
                            @change="applyFilters"
                            class="w-full px-3.5 py-2 text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
                        >
                            <option value="">همه انواع تراکنش‌ها</option>
                            <option v-for="t in transaction_types" :key="t.value" :value="t.value">
                                {{ t.label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="applyFilters"
                            class="flex-1 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md shadow-indigo-600/20"
                        >
                            اعمال فیلتر
                        </button>
                        <button
                            v-if="search || selectedType || selectedUserId"
                            type="button"
                            @click="resetFilters"
                            class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold transition-all"
                        >
                            پاک‌سازی
                        </button>
                    </div>
                </div>

                <div v-if="selectedUserId" class="flex items-center gap-2 pt-2 border-t border-slate-100 dark:border-slate-800 text-xs">
                    <span class="text-slate-500">فیلتر فعال روی کاربر شناسه:</span>
                    <span class="font-mono font-bold text-indigo-500">#{{ selectedUserId }}</span>
                    <button
                        type="button"
                        @click="selectedUserId = ''; applyFilters()"
                        class="text-rose-500 hover:underline font-bold text-[11px]"
                    >
                        (حذف فیلتر کاربر)
                    </button>
                </div>
            </div>

            <!-- 2. TRANSACTIONS LEDGER TABLE -->
            <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                        <h2 class="text-sm font-extrabold text-slate-900 dark:text-white">اسناد دفترکل مالی</h2>
                        <span class="text-xs text-slate-400 font-mono">({{ transactions.total }} ردیف سند)</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="border-b border-slate-200 dark:border-slate-800 text-slate-400">
                            <tr>
                                <th class="py-3 px-3 font-bold">شناسه / کد مرجع</th>
                                <th class="py-3 px-3 font-bold">کاربر</th>
                                <th class="py-3 px-3 font-bold">نوع سند</th>
                                <th class="py-3 px-3 font-bold">مبلغ تراکنش</th>
                                <th class="py-3 px-3 font-bold">تراز قبل</th>
                                <th class="py-3 px-3 font-bold">تراز بعد</th>
                                <th class="py-3 px-3 font-bold">تاریخ ثبت</th>
                                <th class="py-3 px-3 font-bold">شرح / دلیل</th>
                                <th class="py-3 px-3 font-bold text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                            <tr v-for="tx in transactions.data" :key="tx.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="py-3 px-3">
                                    <div class="font-mono font-bold text-slate-900 dark:text-white">#{{ tx.id }}</div>
                                    <div class="font-mono text-[10px] text-slate-400 select-all">{{ tx.reference_id }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    <Link
                                        :href="route('admin.wallets.transactions', { user_id: tx.user.id })"
                                        class="font-bold text-indigo-600 dark:text-indigo-400 hover:underline block"
                                    >
                                        {{ tx.user.name }}
                                    </Link>
                                    <span class="font-mono text-[10px] text-slate-400">{{ tx.user.username }}</span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold border" :class="tx.type_badge">
                                        {{ tx.type_label }}
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <span
                                        class="font-mono font-extrabold text-sm"
                                        :class="tx.is_credit ? 'text-emerald-500' : 'text-rose-500'"
                                    >
                                        {{ tx.is_credit ? '+' : '-' }}{{ tx.amount }}
                                    </span>
                                    <span class="text-[10px] font-bold text-slate-400 mr-1">COIN</span>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-400">{{ tx.balance_before }}</td>
                                <td class="py-3 px-3 font-mono font-bold text-slate-900 dark:text-white">{{ tx.balance_after }}</td>
                                <td class="py-3 px-3 font-mono text-slate-400">
                                    <div>{{ tx.created_at }}</div>
                                    <div class="text-[10px] text-slate-500">{{ tx.created_at_relative }}</div>
                                </td>
                                <td class="py-3 px-3 max-w-xs truncate text-slate-600 dark:text-slate-300" :title="tx.description || ''">
                                    {{ tx.description || '—' }}
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <button
                                        v-if="tx.type !== 'reversal'"
                                        type="button"
                                        @click="openReversalModal(tx)"
                                        class="px-2.5 py-1 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 text-amber-600 dark:text-amber-400 text-[10px] font-bold border border-amber-500/30 transition-all flex items-center gap-1 mx-auto"
                                        title="ثبت سند اصلاحی معکوس"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        <span>اصلاح (Reversal)</span>
                                    </button>
                                    <span v-else class="text-[10px] text-slate-400 font-bold">اصلاحی</span>
                                </td>
                            </tr>

                            <tr v-if="transactions.data.length === 0">
                                <td colspan="9" class="py-8 text-center text-slate-400">
                                    هیچ تراکنشی مطابق فیلترهای انتخابی یافت نشد.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="transactions.links.length > 3" class="flex items-center justify-center gap-1 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <template v-for="(link, idx) in transactions.links" :key="idx">
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

        <!-- 3. REVERSAL TRANSACTION MODAL -->
        <teleport to="body">
            <div v-if="isReversalModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm" @click="closeReversalModal"></div>

                <div class="relative w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl p-6 overflow-hidden z-10">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            ثبت تراکنش اصلاحی معکوس (Reversal)
                        </h3>
                        <button type="button" @click="closeReversalModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            ✕
                        </button>
                    </div>

                    <div v-if="transactionToReverse" class="my-4 p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs space-y-2">
                        <div class="flex justify-between">
                            <span class="text-slate-500 dark:text-slate-400">شناسه تراکنش مبدأ:</span>
                            <span class="font-mono font-bold text-slate-900 dark:text-white">#{{ transactionToReverse.id }} ({{ transactionToReverse.reference_id }})</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500 dark:text-slate-400">کاربر مربوطه:</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ transactionToReverse.user.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500 dark:text-slate-400">مبلغ سند مبدأ:</span>
                            <span class="font-mono font-extrabold text-amber-500">{{ transactionToReverse.amount }} COIN</span>
                        </div>
                        <p class="text-[11px] text-amber-700 dark:text-amber-300 font-medium pt-1 border-t border-amber-500/20">
                            ⚠️ ثبت سند اصلاحی، اثر مالی این تراکنش را معکوس کرده و در دفترکل با نوع Reversal ثبت می‌نماید. سند مبدأ هرگز حذف نمی‌شود.
                        </p>
                    </div>

                    <form @submit.prevent="submitReversal" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                دلیل اصلاح و بازگشت سند (اجباری) <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="reversalForm.reason"
                                rows="3"
                                required
                                placeholder="دلیل ثبت تراکنش اصلاحی را قید نمایید..."
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none resize-none"
                            ></textarea>
                            <p v-if="reversalForm.errors.reason" class="text-rose-500 text-[11px] mt-1">{{ reversalForm.errors.reason }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="closeReversalModal"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="reversalForm.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-slate-900 bg-amber-500 hover:bg-amber-400 transition-all shadow-md shadow-amber-500/20 disabled:opacity-50"
                            >
                                <span v-if="reversalForm.processing">در حال ثبت...</span>
                                <span v-else>تأیید و اعمال اصلاحیه</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </AdminLayout>
</template>

