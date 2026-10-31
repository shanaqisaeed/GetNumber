<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface DepositDetail {
    id: number;
    user: {
        id: number;
        name: string;
        username: string;
    };
    tracking_code: string;
    gateway_driver: string;
    gateway_name: string;
    currency: string;
    network: string;
    requested_amount: string;
    actual_amount: string;
    coin_amount: string;
    trx_to_coin_rate: string;
    deposit_address: string;
    deposit_tag: string | null;
    tx_hash: string | null;
    status: string;
    status_label: string;
    status_badge: string;
    failure_reason: string | null;
    attempts: number;
    last_checked_at: string | null;
    next_check_at: string | null;
    expires_at: string | null;
    completed_at: string | null;
    created_at: string;
    metadata: Record<string, any> | null;
}

const props = defineProps<{
    deposit: DepositDetail;
}>();

const isVerifying = ref(false);
const triggerVerify = () => {
    isVerifying.value = true;
    router.post(route('admin.payments.verify', props.deposit.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            isVerifying.value = false;
        },
    });
};

// Manual Approval Modal
const isManualModalOpen = ref(false);
const manualForm = useForm({
    reason: '',
    tx_hash: props.deposit.tx_hash || '',
});

const submitManualApproval = () => {
    manualForm.post(route('admin.payments.complete-manually', props.deposit.id), {
        preserveScroll: true,
        onSuccess: () => {
            isManualModalOpen.value = false;
        },
    });
};
</script>

<template>
    <Head :title="'جزئیات تراکنش ' + deposit.tracking_code" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        بررسی جزئیات تراکنش:
                        <span class="font-mono text-indigo-400">{{ deposit.tracking_code }}</span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        مشاهده سوابق استعلام بلاکچین، داده‌های خام درگاه و وضعیت اعتبارسنجی
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.payments.index')"
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>بازگشت به لیست واریزی‌ها</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6 max-w-4xl mx-auto">
            <!-- 1. STATUS HERO CARD -->
            <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-400">وضعیت سند:</span>
                        <span class="px-3 py-1 rounded-xl text-xs font-bold border" :class="deposit.status_badge">
                            {{ deposit.status_label }}
                        </span>
                    </div>
                    <div class="mt-2 text-2xl font-black font-mono text-emerald-500">
                        {{ deposit.actual_amount }} <span class="text-xs font-bold text-slate-400">TRX</span>
                        <span class="text-base text-amber-500 mr-2">({{ deposit.coin_amount }} COIN)</span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="deposit.tx_hash && deposit.status !== 'completed'"
                        type="button"
                        @click="triggerVerify"
                        :disabled="isVerifying"
                        class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition-all shadow-md shadow-blue-600/20 disabled:opacity-50 flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" :class="{ 'animate-spin': isVerifying }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>استعلام مجدد از شبکه</span>
                    </button>

                    <button
                        v-if="deposit.status !== 'completed'"
                        type="button"
                        @click="isManualModalOpen = true"
                        class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all shadow-md shadow-emerald-600/20"
                    >
                        تأیید دستی
                    </button>
                </div>
            </div>

            <!-- 2. DETAILS GRID -->
            <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <h3 class="text-xs font-black text-slate-900 dark:text-white flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    مشخصات فنی و مقادیر تراکنش
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">کاربر پرداخت‌کننده:</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ deposit.user.name }} ({{ deposit.user.username }})</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">درگاه انتخابی:</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ deposit.gateway_name }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">شبکه بلاکچین:</span>
                        <span class="font-mono font-bold text-indigo-400">{{ deposit.network }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">نرخ برابری اعمال‌شده:</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">1 TRX = {{ deposit.trx_to_coin_rate }} COIN</span>
                    </div>

                    <div class="sm:col-span-2 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 space-y-1">
                        <span class="text-slate-400 block">آدرس واریز مقصد (Deposit Address):</span>
                        <div class="font-mono font-bold text-[11px] text-slate-900 dark:text-white break-all dir-ltr select-all">
                            {{ deposit.deposit_address }}
                        </div>
                    </div>

                    <div class="sm:col-span-2 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 space-y-1">
                        <span class="text-slate-400 block">شناسه هش تراکنش (TX Hash):</span>
                        <div class="font-mono font-bold text-[11px] text-slate-900 dark:text-white break-all dir-ltr select-all">
                            {{ deposit.tx_hash || 'ثبت‌نشده توسط کاربر' }}
                        </div>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">دفعات استعلام دوره‌ای:</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">{{ deposit.attempts }} بار</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">تاریخ ایجاد درخواست:</span>
                        <span class="font-mono text-slate-900 dark:text-white">{{ deposit.created_at }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">آخرین زمان بررسی:</span>
                        <span class="font-mono text-slate-900 dark:text-white">{{ deposit.last_checked_at || '—' }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">تاریخ تکمیل و شارژ:</span>
                        <span class="font-mono font-bold text-emerald-500">{{ deposit.completed_at || '—' }}</span>
                    </div>
                </div>

                <!-- Failure Reason if any -->
                <div v-if="deposit.failure_reason" class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-xs text-rose-500 space-y-1">
                    <span class="font-bold block">آخرین علت خطای ثبت‌شده:</span>
                    <p>{{ deposit.failure_reason }}</p>
                </div>

                <!-- Raw Metadata Box -->
                <div v-if="deposit.metadata" class="pt-2">
                    <span class="text-xs font-bold text-slate-400 block mb-1.5">داده‌های خام درگاه (Raw Metadata):</span>
                    <pre class="p-3.5 rounded-2xl bg-slate-950 text-slate-300 font-mono text-[11px] overflow-x-auto dir-ltr text-left">{{ JSON.stringify(deposit.metadata, null, 2) }}</pre>
                </div>
            </div>
        </div>

        <!-- MANUAL APPROVAL MODAL -->
        <teleport to="body">
            <div v-if="isManualModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm" @click="isManualModalOpen = false"></div>

                <div class="relative w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl p-6 overflow-hidden z-10">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            تأیید دستی تراکنش و شارژ کیف پول
                        </h3>
                        <button type="button" @click="isManualModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
                    </div>

                    <form @submit.prevent="submitManualApproval" class="space-y-4 mt-4">
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
                                دلیل تأیید دستی (اجباری جهت ممیزی و امنیت) <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="manualForm.reason"
                                rows="3"
                                required
                                placeholder="دلیل تأیید دستی را به دقت قید نمایید..."
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none resize-none text-slate-900 dark:text-white"
                            ></textarea>
                            <p v-if="manualForm.errors.reason" class="text-rose-500 text-[11px] mt-1">{{ manualForm.errors.reason }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="isManualModalOpen = false"
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

