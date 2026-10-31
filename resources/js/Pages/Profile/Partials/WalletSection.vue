<script setup lang="ts">
import QrCode from '@/Components/QrCode.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TransactionItem {
    id: number;
    type: string;
    type_label: string;
    is_credit: boolean;
    amount: string;
    balance_after: string;
    reference: string | null;
    created_at: string;
    created_ago: string;
}

interface GatewayItem {
    id: number;
    name: string;
    driver: string;
    supported_currencies: string[];
    supported_networks: string[];
}

interface PendingDeposit {
    tracking_code: string;
    currency: string;
    network: string;
    requested_amount: string;
    coin_amount: string;
    deposit_address: string;
    deposit_tag: string | null;
    status: string;
    status_label: string;
    status_badge: string;
    tx_hash: string | null;
    can_submit_tx: boolean;
    is_completed: boolean;
    is_expired: boolean;
    created_at: string;
}

const props = defineProps<{
    wallet: {
        balance: string;
    };
    transactions: TransactionItem[];
    activeGateways: GatewayItem[];
    pendingDeposit?: PendingDeposit | null;
    initialView?: 'charge' | 'transactions';
}>();

const currentView = ref<'charge' | 'transactions'>(props.initialView || 'charge');

// Charge Top-up Form
const chargeForm = useForm({
    amount: '10',
    currency: 'TRX',
    network: 'TRON',
    gateway: props.activeGateways?.[0]?.driver || '',
});

const setPresetAmount = (val: string) => {
    chargeForm.amount = val;
};

const submitCharge = () => {
    chargeForm.post(route('wallet.deposit.store'), {
        preserveScroll: true,
    });
};

// TX Hash Form for pending deposit
const txForm = useForm({
    tx_hash: props.pendingDeposit?.tx_hash || '',
});

const submitTx = () => {
    if (!props.pendingDeposit) return;
    txForm.post(route('wallet.deposit.submit-tx', props.pendingDeposit.tracking_code), {
        preserveScroll: true,
    });
};

// Copy Address
const copied = ref(false);
const copyAddress = async () => {
    if (!props.pendingDeposit?.deposit_address) return;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(props.pendingDeposit.deposit_address);
        } else {
            const input = document.createElement('input');
            input.value = props.pendingDeposit.deposit_address;
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
</script>

<template>
    <div class="space-y-4">
        <!-- 1. Balance Summary Card (Standard dash-wallet-card) -->
        <div class="dash-wallet-card !mb-0">
            <div class="wallet-top-row">
                <span class="wallet-title">موجودی کیف پول و حساب</span>
                <span class="wallet-chip">وضعیت: فعال</span>
            </div>

            <div class="wallet-amount">
                <span>{{ props.wallet.balance }}</span>
                <span class="wallet-currency text-sm font-bold">واحد اعتبار</span>
            </div>

            <!-- View Switcher Tabs -->
            <div class="wallet-actions pt-1">
                <button
                    type="button"
                    @click="currentView = 'charge'"
                    class="wallet-btn-topup !py-2 !text-xs cursor-pointer"
                    :class="currentView === 'charge' ? '!opacity-100' : '!opacity-65'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <line x1="12" y1="5" x2="12" y2="19" stroke-width="2.5"></line>
                        <line x1="5" y1="12" x2="19" y2="12" stroke-width="2.5"></line>
                    </svg>
                    <span>شارژ حساب کاربری</span>
                </button>

                <button
                    type="button"
                    @click="currentView = 'transactions'"
                    class="wallet-btn-history !py-2 !text-xs cursor-pointer"
                    :class="currentView === 'transactions' ? '!border-indigo-500 !font-black !text-indigo-600 dark:!text-indigo-400' : ''"
                >
                    <svg class="w-4 h-4 text-indigo-400 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <span>لیست تراکنش‌ها</span>
                </button>
            </div>
        </div>

        <!-- 2. View A: Charge Account (شارژ حساب کاربری) -->
        <div v-if="currentView === 'charge'" class="space-y-4">
            <!-- Case A: Active Pending Deposit in Progress -->
            <div v-if="props.pendingDeposit && !props.pendingDeposit.is_completed && !props.pendingDeposit.is_expired" class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-xs sm:text-sm font-black text-slate-900 dark:text-white flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                            <span>درخواست واریز در حال پردازش</span>
                        </h3>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            کد رهگیری: <span class="font-mono font-bold text-indigo-500">{{ props.pendingDeposit.tracking_code }}</span>
                        </p>
                    </div>
                    <span class="px-2.5 py-1 rounded-xl text-[11px] font-bold border" :class="props.pendingDeposit.status_badge">
                        {{ props.pendingDeposit.status_label }}
                    </span>
                </div>

                <!-- Offline QR Code & Deposit Address -->
                <div class="flex flex-col sm:flex-row items-center gap-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200/80 dark:border-slate-800">
                    <div class="p-2 bg-white rounded-xl shadow-xs shrink-0">
                        <QrCode :value="props.pendingDeposit.deposit_address" :size="130" />
                    </div>

                    <div class="space-y-2.5 flex-1 min-w-0 text-right w-full">
                        <div>
                            <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 block mb-1">
                                آدرس واریز رمز ارز (شبکه {{ props.pendingDeposit.network }}):
                            </span>
                            <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 font-mono text-xs text-slate-900 dark:text-white font-bold select-all break-all text-left" dir="ltr">
                                {{ props.pendingDeposit.deposit_address }}
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <button
                                type="button"
                                @click="copyAddress"
                                class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all shadow-xs flex items-center gap-1 cursor-pointer"
                                :class="copied ? 'bg-emerald-600 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white'"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <span>{{ copied ? 'کپی شد' : 'کپی آدرس' }}</span>
                            </button>

                            <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                <span>مبلغ واریز: </span>
                                <strong class="text-slate-900 dark:text-white font-mono">{{ props.pendingDeposit.requested_amount }} {{ props.pendingDeposit.currency }}</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit TX Hash Form -->
                <form @submit.prevent="submitTx" class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-2.5">
                    <label class="form-label mb-1 text-xs font-bold block">
                        کد رهگیری تراکنش (هش تراکنش در شبکه):
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="txForm.tx_hash"
                            type="text"
                            required
                            placeholder="شناسه یا هش تراکنش ارسالی..."
                            class="form-input flex-1 font-mono text-xs text-left"
                            dir="ltr"
                        />
                        <button
                            type="submit"
                            :disabled="txForm.processing"
                            class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all shrink-0 cursor-pointer disabled:opacity-50"
                        >
                            <span v-if="txForm.processing">در حال ثبت...</span>
                            <span v-else>ثبت کد رهگیری</span>
                        </button>
                    </div>
                    <span v-if="txForm.errors.tx_hash" class="text-xs text-rose-500 block">
                        {{ txForm.errors.tx_hash }}
                    </span>
                </form>
            </div>

            <!-- Case B: No active gateways warning -->
            <div v-else-if="props.activeGateways.length === 0" class="p-5 rounded-2xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200/80 dark:border-amber-800/60 text-right space-y-2">
                <div class="flex items-center gap-2 text-amber-800 dark:text-amber-300 font-bold text-xs">
                    <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>درگاه پرداخت خودکار غیرفعال است</span>
                </div>
                <p class="text-[11px] text-amber-700 dark:text-amber-400 leading-relaxed">
                    در حال حاضر درگاه‌های پرداخت آنلاین توسط مدیریت سامانه در وضعیت غیرفعال قرار دارند. جهت افزایش موجودی اعتبار، لطفاً با پشتیبانی سامانه تماس بگیرید یا منتظر فعال‌سازی درگاه توسط راهبر باشید.
                </p>
            </div>

            <!-- Case C: New Top-up Form -->
            <div v-else class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 shadow-sm space-y-4">
                <div class="pb-2 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        افزایش اعتبار حساب کاربری
                    </h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">
                        انتخاب رمز ارز و واریز مستقیم جهت افزایش موجودی اعتبار
                    </p>
                </div>

                <form @submit.prevent="submitCharge" class="space-y-4">
                    <!-- Cryptocurrency Selection -->
                    <div>
                        <label class="form-label mb-1.5 font-bold text-xs block">
                            انتخاب درگاه و رمز ارز جهت واریز:
                        </label>
                        <select
                            v-model="chargeForm.gateway"
                            class="form-select w-full !text-xs !py-2.5 !rounded-xl"
                        >
                            <option
                                v-for="gw in props.activeGateways"
                                :key="gw.driver"
                                :value="gw.driver"
                            >
                                {{ gw.name }} ({{ gw.supported_currencies.join(' / ') }})
                            </option>
                        </select>
                    </div>

                    <!-- Amount Selection -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label font-bold text-xs">
                                تعداد اعتبار مورد نیاز (واحد):
                            </label>
                            <span class="text-[10px] text-indigo-500 font-bold">حداقل ۱ واحد</span>
                        </div>

                        <input
                            v-model="chargeForm.amount"
                            type="number"
                            step="1"
                            min="1"
                            required
                            placeholder="مثال: 50"
                            class="form-input w-full font-mono text-left font-black"
                            dir="ltr"
                        />
                        <span v-if="chargeForm.errors.amount" class="text-xs text-rose-500 block mt-1">
                            {{ chargeForm.errors.amount }}
                        </span>

                        <!-- Quick Presets -->
                        <div class="grid grid-cols-4 gap-2 mt-2">
                            <button
                                v-for="preset in ['10', '25', '50', '100']"
                                :key="preset"
                                type="button"
                                @click="setPresetAmount(preset)"
                                class="py-1.5 rounded-xl text-xs font-bold border transition-all text-center cursor-pointer"
                                :class="chargeForm.amount === preset
                                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs'
                                    : 'bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100'"
                            >
                                {{ preset }} واحد
                            </button>
                        </div>
                    </div>

                    <!-- Direct Info Notice -->
                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between text-xs">
                        <span class="text-slate-500 dark:text-slate-400 font-medium">موجودی پس از شارژ:</span>
                        <span class="font-mono font-black text-emerald-600 dark:text-emerald-400">
                            {{ (parseFloat(props.wallet.balance || '0') + parseFloat(chargeForm.amount || '0')).toFixed(4) }} واحد
                        </span>
                    </div>

                    <button
                        type="submit"
                        :disabled="chargeForm.processing"
                        class="w-full py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-extrabold text-xs shadow-md shadow-indigo-600/20 transition-all flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span v-if="chargeForm.processing">در حال ایجاد درخواست...</span>
                        <span v-else>ایجاد درخواست شارژ و دریافت آدرس واریز</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- 3. View B: Transaction Ledger & History (گردش و لیست تراکنش‌ها) -->
        <div v-else class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 shadow-sm space-y-3">
            <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                <h3 class="text-xs sm:text-sm font-black text-slate-900 dark:text-white">
                    فهرست و تاریخچه تراکنش‌های کیف پول
                </h3>
                <span class="text-[11px] text-slate-400 font-medium">
                    {{ props.transactions.length }} تراکنش اخیر
                </span>
            </div>

            <!-- Transactions Table -->
            <div v-if="props.transactions.length > 0" class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-50/70 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                            <th class="py-2.5 px-3 font-bold">نوع تراکنش</th>
                            <th class="py-2.5 px-3 font-bold text-center">مقدار</th>
                            <th class="py-2.5 px-3 font-bold text-center">موجودی پس از تراکنش</th>
                            <th class="py-2.5 px-3 font-bold">توضیحات</th>
                            <th class="py-2.5 px-3 font-bold text-left">تاریخ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <tr v-for="tx in props.transactions" :key="tx.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-3">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                    :class="tx.is_credit ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 border border-emerald-200/80 dark:border-emerald-800/60' : 'bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-200/80 dark:border-rose-800/60'"
                                >
                                    {{ tx.type_label }}
                                </span>
                            </td>
                            <td class="py-3 px-3 text-center font-mono font-bold" dir="ltr" :class="tx.is_credit ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                                {{ tx.is_credit ? '+' : '-' }}{{ tx.amount }}
                            </td>
                            <td class="py-3 px-3 text-center font-mono text-[11px] text-slate-500 dark:text-slate-400" dir="ltr">
                                {{ tx.balance_after }}
                            </td>
                            <td class="py-3 px-3 text-[11px] text-slate-600 dark:text-slate-300 max-w-[180px] truncate" :title="tx.reference || ''">
                                {{ tx.reference || '—' }}
                            </td>
                            <td class="py-3 px-3 text-left font-mono text-[11px] text-slate-400" dir="ltr">
                                {{ tx.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-10 space-y-2">
                <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mx-auto text-xl">
                    🪙
                </div>
                <h4 class="text-xs font-bold text-slate-800 dark:text-slate-200">هنوز تراکنشی در حساب شما ثبت نشده است</h4>
                <p class="text-[11px] text-slate-400">
                    با افزایش اعتبار حساب یا ثبت شماره‌های سازمانی، گردش حساب در این بخش نمایش داده می‌شود.
                </p>
            </div>
        </div>
    </div>
</template>
