<script setup lang="ts">
import QrCode from '@/Components/QrCode.vue';
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

interface DepositData {
    id: number;
    tracking_code: string;
    currency: string;
    network: string;
    gateway_driver: string;
    requested_amount: number;
    coin_amount: number;
    deposit_address: string;
    deposit_tag: string | null;
    tx_hash: string | null;
    actual_amount: number;
    status: string;
    status_label: string;
    status_badge: string;
    failure_reason: string | null;
    attempts: number;
    can_submit_tx: boolean;
    is_completed: boolean;
    is_expired: boolean;
    expires_at: string | null;
    created_at: string;
}

const props = defineProps<{
    deposit: DepositData;
    rates?: {
        trx_to_coin_rate: number;
    };
}>();

// Copy notification state
const copied = ref(false);
const copyAddress = async () => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(props.deposit.deposit_address);
        } else {
            const input = document.createElement('input');
            input.value = props.deposit.deposit_address;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    }
};

// Form for submitting TX Hash
const txForm = useForm({
    tx_hash: props.deposit.tx_hash || '',
});

const submitTx = () => {
    txForm.post(route('wallet.deposit.submit-tx', props.deposit.tracking_code), {
        preserveScroll: true,
    });
};

// Background polling for completion
let pollInterval: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    if (!props.deposit.is_completed && !props.deposit.is_expired) {
        pollInterval = setInterval(async () => {
            try {
                const response = await fetch(route('wallet.deposit.status', props.deposit.tracking_code), {
                    headers: { Accept: 'application/json' },
                });
                if (response.ok) {
                    const data = await response.json();
                    if (data.is_completed || data.status !== props.deposit.status) {
                        router.reload();
                    }
                }
            } catch {
                // Ignore network errors in background polling
            }
        }, 8000);
    }
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <Head :title="__('user.wallet.deposit_invoice_title', { code: deposit.tracking_code })" />

    <UserLayout>
        <div class="space-y-4 max-w-xl mx-auto pb-10">
            <!-- Top Bar with Back Button -->
            <div class="flex items-center justify-between gap-3">
                <Link
                    :href="route('profile.edit')"
                    class="px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer shadow-xs"
                >
                    <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>{{ __('user.wallet.back_to_profile') }}</span>
                </Link>

                <div class="text-left" dir="ltr">
                    <span class="text-[11px] font-mono text-slate-400">#{{ deposit.tracking_code }}</span>
                </div>
            </div>

            <!-- COMPLETED HERO BANNER -->
            <div
                v-if="deposit.is_completed"
                class="p-6 rounded-3xl bg-emerald-500/15 border-2 border-emerald-500/40 text-center space-y-3 shadow-xl backdrop-blur-xl animate-fade-in"
            >
                <div class="w-14 h-14 mx-auto rounded-2xl bg-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center font-black text-2xl border border-emerald-500/30 shadow-lg shadow-emerald-500/20">
                    ✓
                </div>
                <div>
                    <h2 class="text-base font-black text-slate-900 dark:text-white">{{ __('user.wallet.deposit_success_title') }}</h2>
                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-1">
                        {{ __('user.wallet.deposit_success_desc', { amount: deposit.actual_amount, coins: deposit.coin_amount }) }}
                    </p>
                </div>
                <div class="pt-2">
                    <Link
                        :href="route('profile.edit')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md transition-all cursor-pointer"
                    >
                        <span>{{ __('user.wallet.view_balance_in_account') }}</span>
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </Link>
                </div>
            </div>

            <!-- INVOICE MAIN CONTAINER -->
            <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-md space-y-6">
                <!-- 1. Invoice Header & Tracking & Status -->
                <div class="space-y-2 pb-4 border-b border-slate-100 dark:border-slate-800 text-center">
                    <h1 class="text-base font-black text-slate-900 dark:text-white">
                        {{ __('user.wallet.deposit_invoice_heading') }}
                    </h1>
                    <div class="flex items-center justify-center gap-2 flex-wrap text-xs">
                        <span class="text-slate-500 dark:text-slate-400">{{ __('user.wallet.tracking_code_label') }}</span>
                        <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40 px-2 py-0.5 rounded-lg border border-indigo-200/60 dark:border-indigo-800/40">
                            {{ deposit.tracking_code }}
                        </span>
                        <span>•</span>
                        <span class="px-2.5 py-0.5 rounded-lg text-xs font-bold border" :class="deposit.status_badge">
                            {{ deposit.status_label }}
                        </span>
                    </div>
                </div>

                <!-- 2. QR Code at the TOP (Centered, Large, Clean) -->
                <div class="flex flex-col items-center justify-center space-y-2">
                    <div class="p-3 bg-white rounded-3xl border border-slate-200 dark:border-slate-700 shadow-sm inline-block">
                        <QrCode :value="deposit.deposit_address" :size="170" />
                    </div>
                    <span class="text-[11px] text-slate-400 dark:text-slate-500">{{ __('user.wallet.scan_qr') }}</span>
                </div>

                <!-- 3. Deposit Address & Copy Button (Directly Below QR) -->
                <div class="space-y-2">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 text-center">
                        {{ __('user.wallet.dedicated_address', { network: deposit.network }) }}
                    </label>

                    <div
                        class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 font-mono text-xs text-slate-900 dark:text-slate-100 font-bold select-all break-all text-center tracking-wide"
                        dir="ltr"
                    >
                        {{ deposit.deposit_address }}
                    </div>

                    <button
                        type="button"
                        @click="copyAddress"
                        class="w-full py-2.5 rounded-2xl text-xs font-bold transition-all shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                        :class="copied
                            ? 'bg-emerald-600 text-white shadow-emerald-600/20'
                            : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/20'"
                    >
                        <svg v-if="copied" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <span>{{ copied ? __('user.wallet.address_copied') : __('user.wallet.copy_address') }}</span>
                    </button>
                </div>

                <!-- 4. Payment Amount (Directly Below Address) -->
                <div class="p-4 rounded-2xl bg-gradient-to-r from-emerald-500/10 via-teal-500/10 to-indigo-500/10 border border-emerald-500/25 flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block">{{ __('user.wallet.payable_amount') }}</span>
                        <span class="text-lg font-black text-emerald-600 dark:text-emerald-400 font-mono">
                            {{ deposit.requested_amount }} TRX
                        </span>
                    </div>

                    <div class="text-left" dir="rtl">
                        <span class="text-xs text-slate-500 dark:text-slate-400 block">{{ __('user.wallet.credit_increase') }}</span>
                        <span class="text-sm font-bold text-slate-800 dark:text-slate-200">
                            {{ __('user.wallet.credit_units_val', { amount: deposit.coin_amount }) }}
                        </span>
                    </div>
                </div>

                <!-- Status Alerts -->
                <div v-if="deposit.status === 'verifying'" class="p-3.5 rounded-2xl bg-blue-500/10 border border-blue-500/20 text-xs text-blue-600 dark:text-blue-400 space-y-1">
                    <p class="font-bold flex items-center gap-1.5">
                        <span class="animate-spin inline-block">🔄</span>
                        <span>{{ __('user.wallet.querying_tron') }}</span>
                    </p>
                    <p class="text-[11px] text-slate-600 dark:text-slate-300">
                        {{ __('user.wallet.querying_tron_desc') }}
                    </p>
                </div>

                <div v-else-if="deposit.status === 'manual_review'" class="p-3.5 rounded-2xl bg-orange-500/10 border border-orange-500/25 text-xs text-orange-600 dark:text-orange-400 space-y-1">
                    <p class="font-bold flex items-center gap-1.5">
                        <span>⚠️</span>
                        <span>{{ __('user.wallet.manual_review_queue') }}</span>
                    </p>
                    <p class="text-[11px] text-slate-600 dark:text-slate-300">
                        {{ __('user.wallet.manual_review_desc') }}
                    </p>
                </div>

                <div v-else-if="deposit.failure_reason && !deposit.is_completed" class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-xs text-rose-500 space-y-1">
                    <span class="font-bold block">{{ __('user.wallet.system_message') }}</span>
                    <p class="text-[11px]">{{ deposit.failure_reason }}</p>
                </div>

                <!-- 5. TX Hash Submission Form (Active if not completed) -->
                <div v-if="!deposit.is_completed && !deposit.is_expired" class="pt-2 border-t border-slate-100 dark:border-slate-800">
                    <form @submit.prevent="submitTx" class="space-y-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-800 dark:text-slate-200 mb-1.5">
                                {{ __('user.wallet.tx_hash_label') }} <span class="text-rose-500">*</span>
                            </label>
                            <input
                                v-model="txForm.tx_hash"
                                type="text"
                                required
                                :placeholder="__('user.wallet.tx_hash_placeholder')"
                                class="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-slate-900 dark:text-white"
                            />
                            <p v-if="txForm.errors.tx_hash" class="text-rose-500 text-[11px] mt-1">{{ txForm.errors.tx_hash }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="txForm.processing"
                            class="w-full py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-bold text-xs shadow-md shadow-indigo-600/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span v-if="txForm.processing">{{ __('user.wallet.submitting_hash') }}</span>
                            <span>{{ deposit.tx_hash ? __('user.wallet.update_hash') : __('user.wallet.submit_hash') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
