<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
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

const { __ } = useTrans();

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

// Manual Rejection Modal
const isRejectModalOpen = ref(false);
const rejectForm = useForm({
    reason: '',
});

const submitManualRejection = () => {
    rejectForm.post(route('admin.payments.reject-manually', props.deposit.id), {
        preserveScroll: true,
        onSuccess: () => {
            isRejectModalOpen.value = false;
            rejectForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="__('admin.payments.head_title_show_code', { code: deposit.tracking_code })" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        {{ __('admin.payments.inspect_tx') }}
                        <span class="font-mono text-indigo-400">{{ deposit.tracking_code }}</span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        {{ __('admin.payments.inspect_tx_desc') }}
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
                        <span>{{ __('admin.payments.back_to_deposits') }}</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6 max-w-4xl mx-auto">
            <!-- 1. STATUS HERO CARD -->
            <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-400">{{ __('admin.payments.doc_status') }}</span>
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
                        v-if="deposit.tx_hash && deposit.status !== 'completed' && deposit.status !== 'failed'"
                        type="button"
                        @click="triggerVerify"
                        :disabled="isVerifying"
                        class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold transition-all shadow-md shadow-blue-600/20 disabled:opacity-50 flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4" :class="{ 'animate-spin': isVerifying }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>{{ __('admin.payments.recheck_network') }}</span>
                    </button>

                    <button
                        v-if="deposit.status !== 'completed' && deposit.status !== 'failed'"
                        type="button"
                        @click="isRejectModalOpen = true"
                        class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition-all shadow-md shadow-rose-600/20 cursor-pointer"
                    >{{ __('admin.payments.manual_reject_tooltip') }}</button>

                    <button
                        v-if="deposit.status !== 'completed'"
                        type="button"
                        @click="isManualModalOpen = true"
                        class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all shadow-md shadow-emerald-600/20 cursor-pointer"
                    >{{ __('admin.payments.approve_btn') }}</button>
                </div>
            </div>

            <!-- 2. DETAILS GRID -->
            <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <h3 class="text-xs font-black text-slate-900 dark:text-white flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    {{ __('admin.payments.technical_specs') }}
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.paying_user') }}</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ deposit.user.name }} ({{ deposit.user.username }})</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.selected_gateway') }}</span>
                        <span class="font-bold text-slate-900 dark:text-white">{{ deposit.gateway_name }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.blockchain_network') }}</span>
                        <span class="font-mono font-bold text-indigo-400">{{ deposit.network }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.applied_rate') }}</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">1 TRX = {{ deposit.trx_to_coin_rate }} COIN</span>
                    </div>

                    <div class="sm:col-span-2 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 space-y-1">
                        <span class="text-slate-400 block">{{ __('admin.payments.deposit_dest_address') }}</span>
                        <div dir="ltr" class="font-mono font-bold text-[11px] text-slate-900 dark:text-white break-all text-left select-all">
                            {{ deposit.deposit_address }}
                        </div>
                    </div>

                    <div class="sm:col-span-2 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 space-y-1">
                        <span class="text-slate-400 block">{{ __('admin.payments.tx_hash_id') }}</span>
                        <div dir="ltr" class="font-mono font-bold text-[11px] text-slate-900 dark:text-white break-all text-left select-all">
                            {{ deposit.tx_hash || __('admin.payments.not_submitted_by_user') }}
                        </div>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.periodic_attempts') }}</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white">{{ __('admin.payments.times_unit', { count: deposit.attempts }) }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.created_date') }}</span>
                        <span class="font-mono text-slate-900 dark:text-white">{{ deposit.created_at }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.last_checked') }}</span>
                        <span class="font-mono text-slate-900 dark:text-white">{{ deposit.last_checked_at || '—' }}</span>
                    </div>

                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-100 dark:border-slate-700/60 flex justify-between">
                        <span class="text-slate-400">{{ __('admin.payments.completed_date') }}</span>
                        <span class="font-mono font-bold text-emerald-500">{{ deposit.completed_at || '—' }}</span>
                    </div>
                </div>

                <!-- Failure Reason if any -->
                <div v-if="deposit.failure_reason" class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-xs text-rose-500 space-y-1">
                    <span class="font-bold block">{{ __('admin.payments.last_error_reason') }}</span>
                    <p>{{ deposit.failure_reason }}</p>
                </div>

                <!-- Raw Metadata Box (Clean LTR View) -->
                <div v-if="deposit.metadata" class="pt-2">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-xs font-bold text-slate-400">{{ __('admin.payments.raw_metadata_title') }}</span>
                        <span class="text-[10px] font-mono text-slate-500">LTR JSON Mode</span>
                    </div>
                    <pre dir="ltr" class="p-4 rounded-2xl bg-slate-950 text-emerald-400 font-mono text-[11px] leading-relaxed overflow-x-auto text-left select-all border border-slate-800">{{ JSON.stringify(deposit.metadata, null, 2) }}</pre>
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
                            {{ __('admin.payments.modal_approve_title') }}
                        </h3>
                        <button type="button" @click="isManualModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
                    </div>

                    <form @submit.prevent="submitManualApproval" class="space-y-4 mt-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.payments.label_tx_hash') }}
                            </label>
                            <input
                                v-model="manualForm.tx_hash"
                                type="text"
                                :placeholder="__('admin.payments.placeholder_tx_hash')"
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none text-slate-900 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.payments.label_approve_reason') }} <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="manualForm.reason"
                                rows="3"
                                required
                                :placeholder="__('admin.payments.placeholder_approve_reason')"
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none resize-none text-slate-900 dark:text-white"
                            ></textarea>
                            <p v-if="manualForm.errors.reason" class="text-rose-500 text-[11px] mt-1">{{ manualForm.errors.reason }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="isManualModalOpen = false"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >{{ __('admin.payments.cancel') }}</button>
                            <button
                                type="submit"
                                :disabled="manualForm.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-600/20 disabled:opacity-50 transition-all cursor-pointer"
                            >
                                <span v-if="manualForm.processing">{{ __('admin.payments.applying') }}</span>
                                <span v-else>{{ __('admin.payments.confirm_approve_submit') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>

        <!-- MANUAL REJECTION MODAL -->
        <teleport to="body">
            <div v-if="isRejectModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm" @click="isRejectModalOpen = false"></div>

                <div class="relative w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl p-6 overflow-hidden z-10">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-rose-500 flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                            {{ __('admin.payments.modal_reject_title') }}
                        </h3>
                        <button type="button" @click="isRejectModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
                    </div>

                    <div class="my-4 p-3 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-xs text-rose-600 dark:text-rose-400 space-y-1">
                        <p class="font-bold">{{ __('admin.payments.security_warning_title') }}</p>
                        <p class="text-[11px] leading-relaxed">
                            {{ __('admin.payments.security_warning_desc') }}
                        </p>
                    </div>

                    <form @submit.prevent="submitManualRejection" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.payments.label_reject_reason') }} <span class="text-rose-500">*</span>
                            </label>
                            <textarea
                                v-model="rejectForm.reason"
                                rows="3"
                                required
                                :placeholder="__('admin.payments.placeholder_reject_desc')"
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:border-rose-500 focus:ring-1 focus:ring-rose-500 outline-none resize-none text-slate-900 dark:text-white"
                            ></textarea>
                            <p v-if="rejectForm.errors.reason" class="text-rose-500 text-[11px] mt-1">{{ rejectForm.errors.reason }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                @click="isRejectModalOpen = false"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >{{ __('admin.payments.cancel') }}</button>
                            <button
                                type="submit"
                                :disabled="rejectForm.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-500 shadow-md shadow-rose-600/20 disabled:opacity-50 transition-all cursor-pointer"
                            >
                                <span v-if="rejectForm.processing">{{ __('admin.payments.applying') }}</span>
                                <span v-else>{{ __('admin.payments.confirm_reject_submit') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </AdminLayout>
</template>

