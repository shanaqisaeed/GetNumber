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
    requested_amount: string;
    coin_amount: string;
    trx_to_coin_rate: string;
    deposit_address: string;
    deposit_tag: string | null;
    tx_hash: string | null;
    actual_amount: string;
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
    qr_code_url?: string | null;
}

const props = defineProps<{
    deposit: DepositData;
    rates: {
        trx_to_coin_rate: string;
    };
}>();

// Copy notification
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
    <Head :title="'پرداخت ' + deposit.tracking_code" />

    <UserLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-sm sm:text-base font-black text-[var(--text-primary)] flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        درخواست واریز ترون:
                        <span class="font-mono text-indigo-400">{{ deposit.tracking_code }}</span>
                    </h1>
                    <p class="text-[11px] text-[var(--text-muted)] mt-0.5">
                        آدرس اختصاصی و ثبت شناسه تراکنش جهت شارژ خودکار
                    </p>
                </div>
                <Link
                    :href="route('wallet.index')"
                    class="px-3 py-1.5 rounded-xl bg-slate-200/60 dark:bg-slate-800 text-[var(--text-primary)] text-xs font-bold transition-all flex items-center gap-1.5"
                >
                    <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>کیف پول</span>
                </Link>
            </div>
        </template>

        <div class="space-y-5 max-w-2xl mx-auto">
            <!-- 1. COMPLETED SUCCESS HERO BANNER -->
            <div
                v-if="deposit.is_completed"
                class="p-6 rounded-3xl bg-emerald-500/15 border-2 border-emerald-500/40 text-center space-y-3 shadow-xl backdrop-blur-xl animate-fade-in"
            >
                <div class="w-14 h-14 mx-auto rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-black text-2xl border border-emerald-500/30 shadow-lg shadow-emerald-500/20">
                    ✓
                </div>
                <div>
                    <h2 class="text-base font-black text-[var(--text-primary)]">واریز با موفقیت تأیید و منظور شد!</h2>
                    <p class="text-xs text-[var(--text-secondary)] mt-1">
                        مبلغ <strong class="text-emerald-400 font-mono">{{ deposit.actual_amount }} TRX</strong> با موفقیت در شبکه تأیید شد و
                        <strong class="text-amber-400 font-mono">{{ deposit.coin_amount }} COIN</strong> به کیف پول شما افزوده گردید.
                    </p>
                </div>
                <div class="pt-2">
                    <Link
                        :href="route('wallet.index')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md transition-all"
                    >
                        <span>مشاهده موجودی در کیف پول</span>
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </Link>
                </div>
            </div>

            <!-- 2. STATUS & INSTRUCTIONS CARD -->
            <div class="p-5 sm:p-6 rounded-3xl bg-[var(--card-bg)] border border-[var(--card-border)] shadow-xl space-y-5">
                <!-- Status Top Row -->
                <div class="flex items-center justify-between pb-3 border-b border-[var(--card-border)]">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-[var(--text-muted)] font-medium">وضعیت تراکنش:</span>
                        <span class="px-2.5 py-1 rounded-xl text-xs font-bold border" :class="deposit.status_badge">
                            {{ deposit.status_label }}
                        </span>
                    </div>

                    <div class="text-xs">
                        <span class="text-[var(--text-muted)]">مبلغ درخواستی:</span>
                        <span class="font-mono font-black text-emerald-400 mr-1 text-sm">{{ deposit.requested_amount }} TRX</span>
                        <span class="text-[10px] text-[var(--text-muted)]">({{ deposit.coin_amount }} COIN)</span>
                    </div>
                </div>

                <!-- Offline Local QR Code & Address Display -->
                <div class="flex flex-col sm:flex-row items-center gap-5 p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800">
                    <div class="shrink-0">
                        <QrCode :value="deposit.deposit_address" :size="150" />
                    </div>

                    <div class="space-y-3 flex-1 min-w-0 text-right w-full">
                        <div>
                            <span class="text-[11px] font-bold text-[var(--text-muted)] block mb-1">
                                آدرس اختصاصی واریز ترون (شبکه TRON / TRC-20):
                            </span>
                            <div class="p-3 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs text-[var(--text-primary)] font-bold select-all break-all dir-ltr text-left">
                                {{ deposit.deposit_address }}
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <button
                                type="button"
                                @click="copyAddress"
                                class="px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-md flex items-center gap-1.5"
                                :class="copied ? 'bg-emerald-600 text-white' : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/20'"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <span>{{ copied ? 'آدرس کپی شد!' : 'کپی آدرس کیف پول' }}</span>
                            </button>

                            <span class="text-[10px] text-amber-500 dark:text-amber-400 font-bold">
                                ⚠️ فقط شبکه ترون (TRC20)
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Important Notice Alerts -->
                <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-700 dark:text-amber-300 space-y-1">
                    <p class="font-bold flex items-center gap-1.5">
                        <span>ℹ️</span>
                        <span>دستورالعمل مهم پرداخت:</span>
                    </p>
                    <ul class="list-disc list-inside space-y-0.5 text-[11px] pr-2 text-slate-600 dark:text-slate-300">
                        <li>حداقل مبلغ انتقال ۱ ترون می‌باشد.</li>
                        <li>پس از ارسال ترون از کیف پول یا صرافی مبدأ، شناسه تراکنش (TX Hash) را در فرم زیر وارد نمایید.</li>
                        <li>سیستم به‌صورت خودکار و دوره‌ای بلاکچین را استعلام کرده و پس از تأیید شبکه، حساب شما شارژ خواهد شد.</li>
                    </ul>
                </div>

                <!-- Failure Reason Alert if Failed -->
                <div v-if="deposit.failure_reason && !deposit.is_completed" class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-xs text-rose-500 space-y-1">
                    <span class="font-bold block">علت رد شدن یا خطای تراکنش:</span>
                    <p class="text-[11px]">{{ deposit.failure_reason }}</p>
                </div>

                <!-- 3. TX HASH SUBMISSION FORM (Active when not completed) -->
                <div v-if="!deposit.is_completed && !deposit.is_expired" class="pt-2 border-t border-[var(--card-border)]">
                    <form @submit.prevent="submitTx" class="space-y-3">
                        <div>
                            <label class="block text-xs font-bold text-[var(--text-primary)] mb-1.5">
                                شناسه تراکنش (TX Hash / Transaction ID) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                v-model="txForm.tx_hash"
                                type="text"
                                required
                                placeholder="مثال: 9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a3b2c1d0e9f8a"
                                class="w-full px-4 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-[var(--text-primary)]"
                            />
                            <p v-if="txForm.errors.tx_hash" class="text-rose-500 text-[11px] mt-1">{{ txForm.errors.tx_hash }}</p>
                        </div>

                        <div class="flex items-center justify-between gap-3 pt-1">
                            <span v-if="deposit.attempts > 0" class="text-[11px] text-[var(--text-muted)] font-mono">
                                دفعات استعلام: {{ deposit.attempts }}
                            </span>
                            <span v-else></span>

                            <button
                                type="submit"
                                :disabled="txForm.processing"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-bold text-xs shadow-md shadow-indigo-600/20 transition-all flex items-center gap-1.5"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span v-if="txForm.processing">در حال ارسال...</span>
                                <span v-else>{{ deposit.tx_hash ? 'به‌روزرسانی و استعلام مجدد' : 'ثبت و بررسی تراکنش' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
