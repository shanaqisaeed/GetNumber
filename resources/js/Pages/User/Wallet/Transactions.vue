<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TransactionItem {
    id: number;
    type: string;
    type_label: string;
    is_credit: boolean;
    badge_classes: string;
    amount: number;
    balance_before: number;
    balance_after: number;
    reference_id: string | null;
    description: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    wallet: {
        id: number;
        balance: number;
        currency: string;
    };
    transactions: {
        data: TransactionItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    types: Array<{ value: string; label: string }>;
    filters: {
        search?: string;
        type?: string;
    };
}>();

const search = ref(props.filters.search || '');
const selectedType = ref(props.filters.type || '');

const applyFilters = () => {
    router.get(
        route('wallet.transactions'),
        {
            search: search.value || undefined,
            type: selectedType.value || undefined,
        },
        { preserveState: true, preserveScroll: true }
    );
};

const resetFilters = () => {
    search.value = '';
    selectedType.value = '';
    applyFilters();
};
</script>

<template>
    <Head :title="__('user.wallet.transactions_title')" />

    <UserLayout>
        <div class="space-y-4 max-w-4xl mx-auto pb-10">
            <!-- Header with Back Button -->
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 shadow-sm shadow-indigo-500/50"></span>
                        {{ __('user.wallet.transactions_title') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        {{ __('user.wallet.transactions_subtitle') }}
                    </p>
                </div>

                <Link
                    :href="route('profile.edit')"
                    class="px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer shadow-xs shrink-0"
                >
                    <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>{{ __('user.wallet.profile_recharge_link') }}</span>
                </Link>
            </div>

            <!-- Current Balance Hero Card -->
            <div class="p-5 rounded-3xl bg-gradient-to-r from-indigo-600 via-indigo-700 to-purple-700 text-white shadow-xl shadow-indigo-600/20 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="space-y-1">
                    <span class="text-xs text-indigo-150 font-medium">{{ __('user.wallet.available_balance') }}</span>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl sm:text-3xl font-black font-mono tracking-tight">{{ wallet.balance }}</span>
                        <span class="text-xs font-bold opacity-90">{{ __('user.wallet.credit_unit') }}</span>
                    </div>
                </div>

                <Link
                    :href="route('profile.edit')"
                    class="px-4 py-2.5 rounded-2xl bg-white text-indigo-700 hover:bg-indigo-50 text-xs font-black transition-all shadow-md flex items-center justify-center gap-2 shrink-0 cursor-pointer"
                >
                    <span class="text-base leading-none">+</span>
                    <span>{{ __('user.wallet.recharge_btn') }}</span>
                </Link>
            </div>

            <!-- Filters Bar -->
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col sm:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="__('user.wallet.search_tx')"
                        @keyup.enter="applyFilters"
                        class="w-full px-3.5 py-2 pr-9 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                    />
                    <svg class="w-4 h-4 text-slate-400 absolute right-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <div class="w-full sm:w-56">
                    <select
                        v-model="selectedType"
                        @change="applyFilters"
                        class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white cursor-pointer"
                    >
                        <option value="">{{ __('user.wallet.all_tx_types') }}</option>
                        <option v-for="t in types" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                </div>

                <button
                    v-if="filters.search || filters.type"
                    type="button"
                    @click="resetFilters"
                    class="px-3 py-2 rounded-xl text-xs font-bold text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-all cursor-pointer whitespace-nowrap"
                >{{ __('user.wallet.clear_filters') }}</button>
            </div>

            <!-- Transactions List -->
            <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div v-if="transactions.data.length === 0" class="p-12 text-center space-y-2">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center text-xl">
                        💳
                    </div>
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-300">{{ __('user.wallet.no_tx_found') }}</p>
                    <p class="text-xs text-slate-400">{{ __('user.wallet.no_tx_desc') }}</p>
                </div>

                <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                    <div
                        v-for="item in transactions.data"
                        :key="item.id"
                        class="p-4 sm:p-5 hover:bg-slate-50/70 dark:hover:bg-slate-850/50 transition-colors flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
                    >
                        <div class="space-y-1.5 flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold" :class="item.badge_classes">
                                    {{ item.type_label }}
                                </span>
                                <span v-if="item.reference_id" class="text-[11px] font-mono text-slate-400 dark:text-slate-500">
                                    {{ __('user.wallet.ref_code', { id: item.reference_id }) }}
                                </span>
                                <span class="text-[11px] text-slate-400">•</span>
                                <span class="text-[11px] text-slate-400 font-mono">{{ item.created_at }}</span>
                            </div>

                            <p class="text-xs text-slate-700 dark:text-slate-200 font-medium">
                                {{ item.description || item.type_label }}
                            </p>
                        </div>

                        <div class="flex items-center justify-between sm:justify-end gap-4 shrink-0 border-t sm:border-t-0 pt-2 sm:pt-0 border-slate-100 dark:border-slate-800">
                            <div class="text-left sm:text-right">
                                <span class="text-[10px] text-slate-400 block">{{ __('user.wallet.balance_after') }}</span>
                                <span class="text-xs font-mono font-bold text-slate-600 dark:text-slate-300">
                                    {{ item.balance_after }} {{ __('user.wallet.unit') }}
                                </span>
                            </div>

                            <div class="text-right sm:text-left min-w-[90px]">
                                <span
                                    class="text-sm font-black font-mono block"
                                    :class="item.is_credit ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
                                >
                                    {{ item.is_credit ? '+' : '-' }}{{ item.amount }} {{ __('user.wallet.unit') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="transactions.links && transactions.links.length > 3" class="p-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-center gap-1 flex-wrap">
                    <template v-for="(link, idx) in transactions.links" :key="idx">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                            :class="link.active
                                ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                                : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="px-3 py-1.5 rounded-xl text-xs text-slate-400 opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </UserLayout>
</template>

