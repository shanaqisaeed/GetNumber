<script setup lang="ts">
import OrgLayout from '@/Layouts/OrgLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { __ } from '@/Composables/useTrans';

interface TransactionItem {
    id: number;
    type: string;
    amount: number;
    balance_before: number;
    balance_after: number;
    reference_id: string | null;
    description: string;
    user_name: string;
    is_credit: boolean;
    created_at: string;
}

interface MemberUsageItem {
    user_id: number;
    user_name: string | null;
    email: string | null;
    total_tx: number;
    total_spent: number;
}

const props = defineProps<{
    balance: string;
    personal_balance?: string;
    is_admin?: boolean;
    currency: string;
    transactions: {
        data: TransactionItem[];
        links: any[];
    };
    member_usage: MemberUsageItem[];
}>();

const txFilter = ref<'all' | 'credit' | 'debit'>('all');

const filteredTransactions = computed(() => {
    if (txFilter.value === 'credit') {
        return props.transactions.data.filter(t => t.is_credit);
    }
    if (txFilter.value === 'debit') {
        return props.transactions.data.filter(t => !t.is_credit);
    }
    return props.transactions.data;
});

const isDepositModalOpen = ref(false);
const depositForm = useForm({
    amount: 500000,
});

const isTransferModalOpen = ref(false);
const transferForm = useForm({
    amount: 100,
});

function submitTransfer() {
    transferForm.post(route('org.wallet.transfer_from_personal'), {
        onSuccess: () => {
            isTransferModalOpen.value = false;
            transferForm.reset();
        },
    });
}

const presetAmounts = [100000, 250000, 500000, 1000000, 2000000];

function selectPreset(val: number) {
    depositForm.amount = val;
}

function submitDeposit() {
    depositForm.post(route('org.wallet.deposit'), {
        onSuccess: () => {
            isDepositModalOpen.value = false;
        },
    });
}
</script>

<template>
    <OrgLayout>
        <Head :title="__('organization.wallet.title')" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header Banner -->
            <div class="p-6 rounded-3xl bg-gradient-to-r from-purple-900/40 via-indigo-900/20 to-purple-900/10 border border-purple-500/20 backdrop-blur-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="space-y-1">
                    <span class="text-xs text-purple-400 font-bold uppercase">{{ __('organization.wallet.title') }}</span>
                    <div class="text-3xl sm:text-4xl font-black text-emerald-500 font-mono tracking-tight" dir="ltr">
                        {{ balance }} <span class="text-sm font-bold text-slate-400">{{ currency }}</span>
                    </div>
                    <p class="text-xs text-slate-400">
                        {{ __('organization.bulk_sms.cost_source_notice') }}
                    </p>
                </div>

                <div class="flex items-center gap-2 self-start sm:self-auto flex-wrap">
                    <button
                        v-if="is_admin"
                        type="button"
                        @click="isTransferModalOpen = true"
                        class="px-4 py-3 rounded-2xl bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-500 dark:text-indigo-300 border border-indigo-500/30 font-extrabold text-xs transition-all flex items-center gap-2 active:scale-95 shadow-xs"
                    >
                        <span>🔄</span>
                        <span>{{ __('organization.wallet.transfer_from_personal') }}</span>
                    </button>

                    <button
                        type="button"
                        @click="isDepositModalOpen = true"
                        class="px-5 py-3 rounded-2xl bg-purple-600 hover:bg-purple-500 text-white font-extrabold text-xs shadow-lg shadow-purple-600/30 transition-all flex items-center gap-2 active:scale-95"
                    >
                        <span>💳</span>
                        <span>{{ __('organization.wallet.deposit') }}</span>
                    </button>
                </div>
            </div>

            <!-- Staff Usage Breakdown Card -->
            <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <h3 class="text-sm font-black text-slate-900 dark:text-white">
                    {{ __('organization.wallet.member_usage') }}
                </h3>

                <div v-if="member_usage.length === 0" class="py-6 text-center text-xs text-slate-400">
                    {{ __('organization.wallet.no_member_usage') }}
                </div>

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div
                        v-for="usage in member_usage"
                        :key="usage.user_id"
                        class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 space-y-2"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200">{{ usage.user_name || 'User #' + usage.user_id }}</span>
                            <span class="text-[10px] text-slate-400 font-mono">{{ usage.total_tx }} {{ __('organization.wallet.total_tx') }}</span>
                        </div>
                        <div class="text-base font-black font-mono text-purple-600 dark:text-purple-400" dir="ltr">
                            {{ usage.total_spent.toLocaleString() }} {{ currency }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transactions Ledger Card -->
            <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.wallet.transactions') }}
                    </h3>

                    <!-- Filter pills -->
                    <div class="flex items-center gap-1.5 self-start sm:self-auto">
                        <button
                            type="button"
                            @click="txFilter = 'all'"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold transition-all"
                            :class="txFilter === 'all' ? 'bg-purple-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            {{ __('organization.wallet.filter_type_all') }}
                        </button>
                        <button
                            type="button"
                            @click="txFilter = 'credit'"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold transition-all"
                            :class="txFilter === 'credit' ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            {{ __('organization.wallet.filter_type_credit') }}
                        </button>
                        <button
                            type="button"
                            @click="txFilter = 'debit'"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold transition-all"
                            :class="txFilter === 'debit' ? 'bg-rose-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            {{ __('organization.wallet.filter_type_debit') }}
                        </button>
                    </div>
                </div>

                <div v-if="filteredTransactions.length === 0" class="p-12 text-center text-xs text-slate-400">
                    {{ __('organization.wallet.no_transactions') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-4 font-bold">{{ __('organization.wallet.transaction_id') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.wallet.type') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.wallet.amount') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.wallet.user') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.wallet.date') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <tr v-for="tx in filteredTransactions" :key="tx.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="p-4 font-mono font-bold text-slate-500">#{{ tx.id }}</td>
                                <td class="p-4">
                                    <p class="font-bold text-slate-800 dark:text-slate-200">{{ tx.description }}</p>
                                    <p class="text-[10px] text-slate-400 font-mono">{{ tx.type }}</p>
                                </td>
                                <td class="p-4 font-mono font-black text-sm" :class="tx.is_credit ? 'text-emerald-500' : 'text-rose-500'" dir="ltr">
                                    {{ tx.is_credit ? '+' : '-' }}{{ tx.amount.toLocaleString() }} {{ currency }}
                                </td>
                                <td class="p-4 text-slate-600 dark:text-slate-400">{{ tx.user_name }}</td>
                                <td class="p-4 text-slate-400 font-mono">{{ tx.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Deposit Modal -->
        <Modal :show="isDepositModalOpen" max-width="md" @close="isDepositModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.wallet.deposit_modal_title') }}
                    </h3>
                    <button type="button" @click="isDepositModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <form @submit.prevent="submitDeposit" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.wallet.deposit_amount') }}
                        </label>
                        <input
                            v-model.number="depositForm.amount"
                            type="number"
                            min="10000"
                            step="10000"
                            required
                            dir="ltr"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold focus:ring-2 focus:ring-purple-500 outline-none"
                        />
                    </div>

                    <!-- Quick Preset Amount Buttons -->
                    <div>
                        <span class="block text-[11px] font-bold text-slate-400 mb-1.5">
                            {{ __('organization.wallet.quick_amounts') }}:
                        </span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="amt in presetAmounts"
                                :key="amt"
                                type="button"
                                @click="selectPreset(amt)"
                                class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold transition-all"
                                :class="depositForm.amount === amt ? 'bg-purple-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200'"
                            >
                                {{ amt.toLocaleString() }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="isDepositModalOpen = false"
                            class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            {{ __('ui.buttons.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="depositForm.processing"
                            class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold shadow-md shadow-purple-600/30 disabled:opacity-50"
                        >
                            {{ __('organization.wallet.deposit_button') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Transfer from Personal Wallet Modal (Admin Only) -->
        <Modal :show="isTransferModalOpen" max-width="md" @close="isTransferModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.wallet.transfer_modal_title') }}
                    </h3>
                    <button type="button" @click="isTransferModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <p class="text-xs text-slate-400">
                    {{ __('organization.wallet.transfer_modal_desc') }}
                </p>

                <div class="p-3.5 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-between text-xs">
                    <span class="text-slate-600 dark:text-slate-300 font-bold">{{ __('organization.wallet.personal_balance_label') }}</span>
                    <span class="font-mono font-black text-indigo-600 dark:text-indigo-400" dir="ltr">
                        {{ personal_balance || '0' }} {{ currency }}
                    </span>
                </div>

                <form @submit.prevent="submitTransfer" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.wallet.transfer_amount') }}
                        </label>
                        <input
                            v-model.number="transferForm.amount"
                            type="number"
                            min="0.0001"
                            step="any"
                            required
                            dir="ltr"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold focus:ring-2 focus:ring-indigo-500 outline-none"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="isTransferModalOpen = false"
                            class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            {{ __('ui.buttons.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="transferForm.processing"
                            class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-md shadow-indigo-600/30 disabled:opacity-50"
                        >
                            {{ __('organization.wallet.submit_transfer') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </OrgLayout>
</template>
