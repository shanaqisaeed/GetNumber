<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface TransactionItem {
    id: number;
    type: string;
    type_label: string;
    type_badge: string;
    is_credit: boolean;
    amount: string;
    balance_before: string;
    balance_after: string;
    reference_id: string;
    description: string | null;
    created_at: string;
    created_at_relative: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    wallet: {
        id: number;
        balance: string;
        currency: string;
        trx_equivalent: string;
    };
    rates: {
        trx_to_coin_rate: string;
        number_price_coin: string;
    };
    transactions: {
        data: TransactionItem[];
        links: PaginationLink[];
        total: number;
    };
    transaction_types: { value: string; label: string }[];
    filters: {
        type?: string;
        search?: string;
    };
}>();

const search = ref(props.filters.search || '');
const selectedType = ref(props.filters.type || '');

const applyFilters = () => {
    router.get(
        route('wallet.index'),
        {
            search: search.value || undefined,
            type: selectedType.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilters = () => {
    search.value = '';
    selectedType.value = '';
    router.get(route('wallet.index'), {}, { preserveState: true, replace: true });
};
</script>

<template>
    <Head title="کیف پول و گردش حساب" />

    <UserLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-sm sm:text-base font-black text-[var(--text-primary)] flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"></span>
                        کیف پول و دفترکل مالی
                    </h1>
                    <p class="text-[11px] text-[var(--text-muted)] mt-0.5">
                        مدیریت اعتبار COIN، خرید شماره و گزارش گردش حساب
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('wallet.deposit.create')"
                        class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white text-xs font-bold transition-all shadow-md shadow-emerald-500/20 flex items-center gap-1.5"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>شارژ با ترون (TRX)</span>
                    </Link>
                    <Link
                        :href="route('numbers.index')"
                        class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md shadow-indigo-500/20 flex items-center gap-1.5"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>خرید شماره</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- 1. MAIN WALLET BALANCE HERO CARD -->
            <div class="relative overflow-hidden rounded-3xl p-5 bg-gradient-to-br from-amber-500/20 via-indigo-600/15 to-purple-600/20 border border-amber-500/30 dark:border-amber-500/20 shadow-xl backdrop-blur-xl">
                <div class="flex items-center justify-between pb-3 border-b border-white/10">
                    <span class="text-xs font-extrabold text-[var(--text-secondary)] flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <line x1="2" y1="10" x2="22" y2="10" />
                        </svg>
                        موجودی کیف پول دیجیتال
                    </span>
                    <span class="px-2 py-0.5 rounded-lg bg-amber-500/20 text-amber-600 dark:text-amber-400 font-mono text-[11px] font-black border border-amber-500/30">
                        {{ wallet.currency }}
                    </span>
                </div>

                <div class="my-4 text-center">
                    <div class="text-3xl sm:text-4xl font-black font-mono tracking-tight text-[var(--text-primary)]">
                        {{ wallet.balance }}
                        <span class="text-base font-extrabold text-amber-500 dark:text-amber-400 mr-1">COIN</span>
                    </div>
                    <div class="text-xs font-semibold text-[var(--text-muted)] mt-1 flex items-center justify-center gap-1">
                        <span>معادل تقریبی:</span>
                        <span class="font-mono font-bold text-[var(--text-primary)]">{{ wallet.trx_equivalent }}</span>
                        <span>TRX</span>
                    </div>
                </div>

                <!-- Rate Info Chips -->
                <div class="grid grid-cols-2 gap-2 pt-3 border-t border-white/10 text-[11px]">
                    <div class="p-2.5 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <span class="text-[var(--text-muted)] block text-[10px]">نرخ رسمی هر ترون:</span>
                        <span class="font-bold text-[var(--text-primary)] font-mono">1 TRX = {{ rates.trx_to_coin_rate }} COIN</span>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <span class="text-[var(--text-muted)] block text-[10px]">قیمت هر خط مجازی:</span>
                        <span class="font-bold text-indigo-500 dark:text-indigo-400 font-mono">{{ rates.number_price_coin }} COIN</span>
                    </div>
                </div>
            </div>

            <!-- 2. RECHARGE ACTION BANNER -->
            <div class="p-4.5 rounded-2xl bg-gradient-to-r from-emerald-500/15 via-teal-500/10 to-indigo-500/15 border border-emerald-500/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-md">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black text-lg shrink-0 border border-emerald-500/30">
                        ⚡
                    </div>
                    <div>
                        <h3 class="text-xs font-black text-[var(--text-primary)]">شارژ آنلاین کیف پول با ترون (TRX)</h3>
                        <p class="text-[11px] text-[var(--text-secondary)] mt-0.5">
                            انتقال سریع کریپتو در شبکه TRON، بررسی خودکار تراکنش و تبدیل آنی به کوین
                        </p>
                    </div>
                </div>

                <Link
                    :href="route('wallet.deposit.create')"
                    class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-black shadow-md shadow-emerald-600/20 transition-all flex items-center justify-center gap-1.5 shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span>افزایش اعتبار جدید</span>
                </Link>
            </div>

            <!-- 3. TRANSACTIONS LEDGER SECTION -->
            <div class="glass-card !p-4 space-y-3.5">
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200/80 dark:border-white/10">
                    <span class="text-xs font-black text-[var(--text-primary)] flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        دفترکل تراکنش‌ها (Ledger)
                    </span>
                    <span class="text-[11px] text-[var(--text-muted)] font-mono">
                        {{ transactions.total }} تراکنش
                    </span>
                </div>

                <!-- Filters -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input
                        v-model="search"
                        @keyup.enter="applyFilters"
                        type="text"
                        placeholder="جستجو در شناسه پیگیری یا توضیحات..."
                        class="w-full text-xs rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-white/5 px-3 py-2 text-[var(--text-primary)] focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />

                    <select
                        v-model="selectedType"
                        @change="applyFilters"
                        class="w-full text-xs rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-white/5 px-3 py-2 text-[var(--text-primary)] focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    >
                        <option value="">همه انواع تراکنش‌ها</option>
                        <option v-for="t in transaction_types" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                </div>

                <!-- Reset Filters Button if applied -->
                <div v-if="search || selectedType" class="flex justify-end">
                    <button
                        @click="resetFilters"
                        type="button"
                        class="text-[11px] text-rose-500 hover:underline"
                    >
                        پاک‌سازی فیلترها
                    </button>
                </div>

                <!-- Transaction List -->
                <div v-if="transactions.data.length > 0" class="space-y-2.5 pt-1">
                    <div
                        v-for="tx in transactions.data"
                        :key="tx.id"
                        class="p-3.5 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200/70 dark:border-white/10 space-y-2 transition-all hover:border-indigo-500/30"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-black" :class="tx.type_badge">
                                {{ tx.type_label }}
                            </span>
                            <span
                                class="text-xs font-mono font-black"
                                :class="tx.is_credit ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
                            >
                                {{ tx.is_credit ? '+' : '-' }}{{ tx.amount }} COIN
                            </span>
                        </div>

                        <p v-if="tx.description" class="text-xs text-[var(--text-secondary)] font-medium">
                            {{ tx.description }}
                        </p>

                        <div class="pt-2 border-t border-slate-200/60 dark:border-white/5 flex flex-wrap items-center justify-between text-[10px] text-[var(--text-muted)] font-mono gap-1">
                            <span>کد رهگیری: {{ tx.reference_id }}</span>
                            <div class="flex items-center gap-2">
                                <span>موجودی پس از تراکنش: {{ tx.balance_after }}</span>
                                <span>{{ tx.created_at }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="text-center py-8 text-[var(--text-muted)] text-xs">
                    هیچ تراکنشی در تاریخچه کیف پول شما یافت نشد.
                </div>

                <!-- Pagination -->
                <div v-if="transactions.links.length > 3" class="flex items-center justify-center gap-1 pt-3">
                    <template v-for="(link, i) in transactions.links" :key="i">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-2.5 py-1 rounded-lg text-[11px] font-bold border transition-all"
                            :class="link.active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white/5 text-[var(--text-secondary)] border-white/10 hover:bg-white/10'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="px-2 py-1 text-[11px] text-[var(--text-muted)] opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </UserLayout>
</template>

