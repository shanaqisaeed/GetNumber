<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface GatewayItem {
    id: number;
    name: string;
    driver: string;
    is_default: boolean;
    supported_currencies: string[];
    supported_networks: string[];
}

interface RecentDeposit {
    id: number;
    tracking_code: string;
    requested_amount: string;
    coin_amount: string;
    status: string;
    status_label: string;
    status_badge: string;
    created_at: string;
}

const props = defineProps<{
    rates: {
        trx_to_coin_rate: string;
        number_price_coin: string;
    };
    wallet_balance: string;
    gateways: GatewayItem[];
    recent_deposits: RecentDeposit[];
}>();

const form = useForm({
    amount: '10',
    network: 'TRON',
    gateway: props.gateways.find(g => g.is_default)?.driver || 'nobitex',
});

const quickAmounts = ['5', '10', '25', '50', '100'];

const calculatedCoin = computed(() => {
    const val = parseFloat(form.amount) || 0;
    const rate = parseFloat(props.rates.trx_to_coin_rate) || 1;
    return (val * rate).toFixed(4);
});

const submit = () => {
    form.post(route('wallet.deposit.store'));
};
</script>

<template>
    <Head title="شارژ حساب با ترون (TRX)" />

    <UserLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-sm sm:text-base font-black text-[var(--text-primary)] flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        افزایش اعتبار با ترون (TRX)
                    </h1>
                    <p class="text-[11px] text-[var(--text-muted)] mt-0.5">
                        واریز مستقیم کریپتو و تبدیل خودکار به کوین داخلی
                    </p>
                </div>
                <Link
                    :href="route('wallet.index')"
                    class="px-3 py-1.5 rounded-xl bg-slate-200/60 dark:bg-slate-800 text-[var(--text-primary)] text-xs font-bold transition-all flex items-center gap-1.5"
                >
                    <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>بازگشت به کیف پول</span>
                </Link>
            </div>
        </template>

        <div class="space-y-5 max-w-2xl mx-auto">
            <!-- 1. Rate & Balance Hero Banner -->
            <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-br from-emerald-500/15 via-indigo-600/15 to-purple-600/15 border border-emerald-500/30 shadow-lg backdrop-blur-xl">
                <div class="flex items-center justify-between pb-3 border-b border-white/10">
                    <span class="text-xs font-extrabold text-[var(--text-secondary)] flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                        نرخ رسمی تبدیل سامانه
                    </span>
                    <span class="px-2 py-0.5 rounded-lg bg-emerald-500/20 text-emerald-400 font-mono text-[11px] font-black border border-emerald-500/30">
                        1 TRX = {{ rates.trx_to_coin_rate }} COIN
                    </span>
                </div>

                <div class="mt-3 flex items-center justify-between text-xs">
                    <span class="text-[var(--text-muted)]">موجودی فعلی شما:</span>
                    <span class="font-mono font-black text-amber-400 text-sm">{{ wallet_balance }} COIN</span>
                </div>
            </div>

            <!-- 2. Deposit Form -->
            <div class="p-5 sm:p-6 rounded-3xl bg-[var(--card-bg)] border border-[var(--card-border)] shadow-xl space-y-5">
                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Amount Input -->
                    <div>
                        <label class="block text-xs font-bold text-[var(--text-primary)] mb-1.5">
                            مبلغ ترون جهت واریز (TRX) <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                v-model="form.amount"
                                type="number"
                                step="0.0001"
                                min="0.1"
                                required
                                class="w-full px-4 py-3 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-base font-mono font-bold focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-[var(--text-primary)]"
                                placeholder="مثال: 20"
                            />
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xs font-bold text-emerald-500 font-mono">TRX</span>
                        </div>
                        <p v-if="form.errors.amount" class="text-rose-500 text-[11px] mt-1">{{ form.errors.amount }}</p>

                        <!-- Quick Amount Buttons -->
                        <div class="flex flex-wrap items-center gap-2 mt-2.5">
                            <span class="text-[10px] text-[var(--text-muted)]">مبالغ پیشنهادی:</span>
                            <button
                                v-for="q in quickAmounts"
                                :key="q"
                                type="button"
                                @click="form.amount = q"
                                class="px-2.5 py-1 rounded-xl text-[11px] font-mono font-bold border transition-all"
                                :class="form.amount === q ? 'bg-indigo-600 text-white border-indigo-500 shadow-sm' : 'bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-[var(--text-secondary)] hover:border-indigo-400'"
                            >
                                {{ q }} TRX
                            </button>
                        </div>
                    </div>

                    <!-- Calculated Credit Box -->
                    <div class="p-3.5 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-between text-xs">
                        <span class="text-[var(--text-secondary)]">اعتبار دریافتی شما در کیف پول:</span>
                        <span class="font-mono font-black text-indigo-400 text-base">
                            {{ calculatedCoin }} <span class="text-xs font-bold">COIN</span>
                        </span>
                    </div>

                    <!-- Network Selection -->
                    <div>
                        <label class="block text-xs font-bold text-[var(--text-primary)] mb-1.5">
                            شبکه بلاکچین انتقال
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label
                                class="p-3 rounded-2xl border flex items-center justify-between cursor-pointer transition-all"
                                :class="form.network === 'TRON' ? 'bg-emerald-500/10 border-emerald-500/40 text-emerald-400 font-bold' : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700 text-[var(--text-secondary)]'"
                            >
                                <div class="flex items-center gap-2">
                                    <input type="radio" v-model="form.network" value="TRON" class="hidden" />
                                    <span class="text-xs">شبکه ترون (TRON / TRX)</span>
                                </div>
                                <span class="text-[10px] font-mono opacity-75">پیش‌فرض</span>
                            </label>

                            <label
                                class="p-3 rounded-2xl border flex items-center justify-between cursor-pointer transition-all"
                                :class="form.network === 'TRC20' ? 'bg-emerald-500/10 border-emerald-500/40 text-emerald-400 font-bold' : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700 text-[var(--text-secondary)]'"
                            >
                                <div class="flex items-center gap-2">
                                    <input type="radio" v-model="form.network" value="TRC20" class="hidden" />
                                    <span class="text-xs">TRC-20 Token</span>
                                </div>
                                <span class="text-[10px] font-mono opacity-75">TRC20</span>
                            </label>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-emerald-500 via-teal-500 to-indigo-600 hover:from-emerald-400 hover:to-indigo-500 text-white font-black text-sm shadow-xl shadow-emerald-500/20 disabled:opacity-50 transition-all flex items-center justify-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span v-if="form.processing">در حال تولید آدرس واریز...</span>
                            <span v-else>ایجاد درخواست و دریافت آدرس کیف پول</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- 3. Recent Deposits List -->
            <div v-if="recent_deposits.length > 0" class="p-5 rounded-3xl bg-[var(--card-bg)] border border-[var(--card-border)] shadow-md space-y-3">
                <h3 class="text-xs font-black text-[var(--text-primary)] flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    درخواست‌های اخیر افزایش اعتبار شما
                </h3>

                <div class="divide-y divide-slate-100 dark:divide-slate-800/60 text-xs">
                    <div
                        v-for="d in recent_deposits"
                        :key="d.id"
                        class="py-2.5 flex items-center justify-between"
                    >
                        <div>
                            <Link
                                :href="route('wallet.deposit.show', d.tracking_code)"
                                class="font-mono font-bold text-indigo-400 hover:underline block"
                            >
                                {{ d.tracking_code }}
                            </Link>
                            <span class="text-[10px] text-[var(--text-muted)]">{{ d.created_at }}</span>
                        </div>

                        <div class="flex items-center gap-3">
                            <span class="font-mono font-bold text-[var(--text-primary)]">
                                {{ d.requested_amount }} TRX
                                <span class="text-[10px] text-amber-400">({{ d.coin_amount }} COIN)</span>
                            </span>
                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold border" :class="d.status_badge">
                                {{ d.status_label }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </UserLayout>
</template>

