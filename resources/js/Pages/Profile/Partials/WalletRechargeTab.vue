<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    wallet?: { balance: string };
    pendingDeposit?: {
        tracking_code: string;
        requested_amount: string;
        status_label: string;
        created_at: string;
    } | null;
}

const props = withDefaults(defineProps<Props>(), {
    wallet: () => ({ balance: '0' }),
    pendingDeposit: null,
});

const currentBalance = computed(() => {
    return Math.floor(Number(props.wallet?.balance || 0));
});

const form = useForm({
    amount: 25,
});

const presets = [10, 25, 50, 100, 250];

const step = (delta: number) => {
    form.amount = Math.max(1, (Number(form.amount) || 0) + delta);
};

const setPreset = (amt: number) => {
    form.amount = amt;
};

const submitRecharge = () => {
    form.post(route('wallet.deposit.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="space-y-6">
        <!-- Balance Header -->
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 flex-wrap gap-3">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white">شارژ کیف پول</h3>
                <p class="text-xs text-slate-400 mt-0.5">افزایش موجودی حساب (هر ۱ واحد = ۱ ترون TRX)</p>
            </div>
            <div class="px-3.5 py-1.5 rounded-xl bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/25 text-xs font-black">
                موجودی فعلی: {{ currentBalance }} واحد
            </div>
        </div>

        <!-- Pending Invoice Banner (if any) -->
        <div
            v-if="pendingDeposit"
            class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-between gap-3 text-xs flex-wrap"
        >
            <div class="space-y-0.5">
                <span class="font-bold text-amber-900 dark:text-amber-200 block">فاکتور در انتظار پرداخت دارید:</span>
                <span class="font-mono text-slate-600 dark:text-slate-300" dir="ltr">
                    {{ pendingDeposit.tracking_code }} — {{ pendingDeposit.requested_amount }} TRX
                </span>
            </div>
            <Link
                :href="route('wallet.deposit.show', pendingDeposit.tracking_code)"
                class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs shadow-xs transition-all"
            >
                مشاهده و پرداخت فاکتور
            </Link>
        </div>

        <!-- Direct Simple Recharge Form -->
        <form @submit.prevent="submitRecharge" class="space-y-5">
            <!-- Amount Stepper -->
            <div class="space-y-2">
                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    میزان اعتبار درخواستی (واحد):
                </label>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="step(-5)"
                        class="w-12 h-11 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-black text-lg transition-all flex items-center justify-center cursor-pointer shrink-0 border border-slate-200 dark:border-slate-700"
                    >
                        -
                    </button>

                    <div class="relative flex-1">
                        <input
                            v-model.number="form.amount"
                            type="number"
                            min="1"
                            step="1"
                            required
                            class="w-full h-11 px-4 text-center rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono text-base font-black text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
                        />
                        <span class="absolute left-3 top-3 text-xs font-bold text-slate-400 pointer-events-none">
                            واحد
                        </span>
                    </div>

                    <button
                        type="button"
                        @click="step(5)"
                        class="w-12 h-11 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-black text-lg transition-all flex items-center justify-center cursor-pointer shrink-0 border border-slate-200 dark:border-slate-700"
                    >
                        +
                    </button>
                </div>
            </div>

            <!-- Preset buttons -->
            <div class="space-y-1.5">
                <span class="text-[11px] font-bold text-slate-400 block">انتخاب سریع:</span>
                <div class="grid grid-cols-5 gap-2">
                    <button
                        v-for="amt in presets"
                        :key="amt"
                        type="button"
                        @click="setPreset(amt)"
                        class="py-2.5 rounded-xl text-xs font-bold transition-all border cursor-pointer text-center"
                        :class="form.amount === amt
                            ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm shadow-indigo-600/30'
                            : 'bg-slate-50 dark:bg-slate-800/80 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700'"
                    >
                        {{ amt }} واحد
                    </button>
                </div>
            </div>

            <!-- Price calculation summary -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 flex items-center justify-between text-xs">
                <span class="text-slate-600 dark:text-slate-300 font-bold">مبلغ قابل پرداخت:</span>
                <div class="font-mono font-black text-base text-slate-900 dark:text-white flex items-center gap-1.5">
                    <span>{{ form.amount || 0 }}</span>
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold text-xs">ترون (TRX)</span>
                </div>
            </div>

            <!-- Submit Button -->
            <button
                type="submit"
                :disabled="form.processing || (form.amount || 0) < 1"
                class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-indigo-600 via-purple-600 to-indigo-700 hover:from-indigo-700 hover:to-purple-700 text-white font-black text-xs shadow-md shadow-indigo-600/25 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
            >
                <span v-if="form.processing">در حال صدور فاکتور ترون...</span>
                <template v-else>
                    <span>پرداخت و ایجاد فاکتور ترون</span>
                    <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </template>
            </button>
        </form>
    </div>
</template>
