<script setup lang="ts">
import AllocateNumberModal from '@/Components/AllocateNumberModal.vue';
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { __ } from '@/Composables/useTrans';

interface Quota {
    active: number;
    max: number;
    remaining: number;
    percentage: number;
}

interface Stats {
    active_numbers: number;
    standard_numbers?: number;
    service_numbers?: number;
    total_messages: number;
    inbound_messages?: number;
    outbound_messages?: number;
    today_messages?: number;
    unread_messages: number;
    pending_requests: number;
    approved_requests?: number;
    active_tokens?: number;
    total_tokens?: number;
    open_tickets?: number;
    answered_tickets?: number;
}

interface RecentNumber {
    id: number;
    number: string;
    formatted_number: string;
    display_title?: string | null;
    type: string;
    type_label: string;
    rond_tier?: string;
    rond_tier_label?: string;
    accepts_incoming_sms?: boolean;
    prefix_flag?: string;
    allocated_at: string;
    allocated_ago: string;
}

interface UnreadSenderSummary {
    sender: string;
    phone_number_id: number;
    destination_number: string;
    count: number;
    received_at: string;
}

interface RecentTransaction {
    id: number;
    type: string;
    type_label: string;
    is_credit: boolean;
    badge_classes: string;
    amount: number;
    description: string;
    created_at: string;
    created_ago: string;
}

interface PendingDepositSummary {
    tracking_code: string;
    requested_amount: string;
    coin_amount: string;
    currency: string;
    status_label: string;
}

interface WalletInfo {
    balance: string;
    currency?: string;
    number_price_coin?: string;
    sms_rate?: string;
    recent_transactions?: RecentTransaction[];
    pending_deposit?: PendingDepositSummary | null;
}

const props = defineProps<{
    quota: Quota;
    stats: Stats;
    recent_numbers: RecentNumber[];
    unread_messages: UnreadSenderSummary[];
    default_prefix: string;
    active_prefixes?: any[];
    wallet?: WalletInfo;
}>();

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showToast = (msg?: string) => {
    toastMessage.value = msg || __('dashboard.op_success');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2500);
};

const copiedId = ref<number | null>(null);

const copyToClipboard = async (text: string, id?: number) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }
        if (id !== undefined) {
            copiedId.value = id;
            setTimeout(() => { copiedId.value = null; }, 2000);
        }
        showToast(__('dashboard.copied_number'));
    } catch {
        showToast(__('dashboard.copied_number'));
    }
};

// Standard Number Allocation Modal State
const isAllocateModalOpen = ref(false);

// Computed helpers
const creditBalance = computed(() => Math.floor(Number(props.wallet?.balance || 0)));
const recentTransactions = computed(() => props.wallet?.recent_transactions || []);
</script>

<template>
    <Head :title="__('dashboard.head_title')" />

    <UserLayout>
        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 -translate-y-4 scale-95"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 -translate-y-2 scale-95"
            >
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2 rounded-2xl bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2 text-xs font-bold pointer-events-none"
                >
                    <div class="w-4 h-4 rounded-full bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-4 pb-16">
            <!-- ========================================================================= -->
            <!-- 1. WALLET HERO & QUICK ACTION BAR (Designed for 460px mobile container)  -->
            <!-- ========================================================================= -->
            <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-br from-indigo-950 via-slate-900 to-slate-950 border border-indigo-500/25 text-white shadow-xl relative overflow-hidden">
                <div class="absolute -end-10 -top-10 w-40 h-40 rounded-full bg-indigo-500/15 blur-2xl pointer-events-none"></div>

                <div class="relative z-10 space-y-3.5">
                    <!-- Top Balance Row -->
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-[11px] font-bold text-slate-400 block">{{ __('dashboard.wallet_balance_label') }}</span>
                            <div class="flex items-baseline gap-1.5 mt-0.5">
                                <span class="text-3xl font-black font-mono tracking-tight text-white">{{ creditBalance }}</span>
                                <span class="text-xs font-extrabold text-amber-400">TRX</span>
                            </div>
                        </div>

                        <!-- Quota Pill -->
                        <div class="text-end">
                            <span class="text-[10px] font-bold text-slate-400 block mb-1">
                                {{ __('dashboard.quota_lines') }}: <span class="text-white font-mono font-bold">{{ props.quota.active }}</span> / {{ props.quota.max }}
                            </span>
                            <div class="w-24 h-1.5 bg-white/10 rounded-full overflow-hidden">
                                <div class="h-full bg-indigo-400 rounded-full" :style="{ width: `${props.quota.percentage}%` }"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Dual Primary Action Buttons -->
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button
                            type="button"
                            @click="isAllocateModalOpen = true"
                            class="w-full py-2.5 px-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-extrabold shadow-md shadow-indigo-600/30 flex items-center justify-center gap-1.5 transition-all cursor-pointer active:scale-95"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            <span class="truncate">{{ __('dashboard.get_new_number_btn') }}</span>
                        </button>

                        <Link
                            :href="route('wallet.deposit.create')"
                            class="w-full py-2.5 px-3 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/15 text-white text-xs font-extrabold flex items-center justify-center gap-1.5 transition-all cursor-pointer active:scale-95"
                        >
                            <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="truncate">{{ __('dashboard.charge_wallet_btn') }}</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Actionable Alerts (Pending Deposit / Answered Tickets) -->
            <div v-if="props.wallet?.pending_deposit" class="p-3 rounded-2xl bg-amber-500/10 border border-amber-500/25 flex items-center justify-between gap-2.5">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-ping shrink-0"></span>
                    <div class="min-w-0 text-xs">
                        <span class="font-bold text-slate-800 dark:text-slate-200 block truncate">{{ __('dashboard.pending_invoice_title') }}</span>
                        <span class="text-[10px] text-slate-500 dark:text-slate-400 font-mono block truncate" dir="ltr">
                            #{{ props.wallet.pending_deposit.tracking_code }} ({{ props.wallet.pending_deposit.coin_amount }} TRX)
                        </span>
                    </div>
                </div>
                <Link
                    :href="route('wallet.deposit.show', props.wallet.pending_deposit.tracking_code)"
                    class="px-3 py-1 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-black shrink-0 transition-colors"
                >
                    {{ __('dashboard.continue_payment') }}
                </Link>
            </div>

            <div v-if="(props.stats.answered_tickets || 0) > 0" class="p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/25 flex items-center justify-between gap-2.5">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                    <div class="min-w-0 text-xs">
                        <span class="font-bold text-slate-800 dark:text-slate-200 block truncate">
                            {{ __('dashboard.answered_tickets', { count: props.stats.answered_tickets }) }}
                        </span>
                        <span class="text-[10px] text-slate-500 dark:text-slate-400 block truncate">{{ __('dashboard.answered_tickets_desc') }}</span>
                    </div>
                </div>
                <Link
                    :href="route('tickets.index', { status: 'answered' })"
                    class="px-3 py-1 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-black shrink-0 transition-colors"
                >
                    {{ __('dashboard.view_reply') }}
                </Link>
            </div>

            <!-- ========================================================================= -->
            <!-- 2. COMPACT 2x2 METRIC GRID                                               -->
            <!-- ========================================================================= -->
            <div class="grid grid-cols-2 gap-2.5">
                <!-- Numbers Stat -->
                <Link
                    :href="route('numbers.index')"
                    class="p-3.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between hover:border-indigo-500/40 transition-colors"
                >
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 block mb-0.5">{{ __('dashboard.active_numbers_title') }}</span>
                        <span class="text-xl font-black font-mono text-slate-900 dark:text-white block">{{ props.quota.active }}</span>
                        <span class="text-[10px] text-slate-400 block mt-0.5">{{ props.quota.remaining }} {{ __('dashboard.quota_lines') }} {{ __('dashboard.free_capacity', { count: '' }).replace(':count', '').trim() }}</span>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                </Link>

                <!-- Messages Stat -->
                <Link
                    :href="route('messages.index')"
                    class="p-3.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between hover:border-emerald-500/40 transition-colors"
                >
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 block mb-0.5">{{ __('dashboard.total_sms_title') }}</span>
                        <div class="flex items-baseline gap-1">
                            <span class="text-xl font-black font-mono text-slate-900 dark:text-white">{{ props.stats.total_messages }}</span>
                            <span v-if="props.stats.unread_messages > 0" class="text-[9px] font-black text-rose-500 animate-pulse">
                                ({{ props.stats.unread_messages }} {{ __('dashboard.unread_badge') }})
                            </span>
                        </div>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold block mt-0.5">
                            {{ props.stats.today_messages ?? 0 }} {{ __('dashboard.today_received') }}
                        </span>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                </Link>

                <!-- Corporate Lines Stat -->
                <Link
                    :href="route('requests.index')"
                    class="p-3.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between hover:border-purple-500/40 transition-colors"
                >
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 block mb-0.5">{{ __('dashboard.corporate_numbers_title') }}</span>
                        <span class="text-xl font-black font-mono text-purple-600 dark:text-purple-400 block">{{ props.stats.service_numbers ?? 0 }}</span>
                        <span v-if="props.stats.pending_requests > 0" class="text-[10px] text-amber-500 font-bold block mt-0.5">
                            {{ props.stats.pending_requests }} {{ __('dashboard.pending_shortcodes') }}
                        </span>
                        <span v-else class="text-[10px] text-slate-400 block mt-0.5">
                            {{ __('dashboard.requests_link') }} →
                        </span>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                    </div>
                </Link>

                <!-- API Tokens Stat -->
                <Link
                    :href="route('api-tokens.index')"
                    class="p-3.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 flex items-center justify-between hover:border-sky-500/40 transition-colors"
                >
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 block mb-0.5">{{ __('dashboard.action_api_title') }}</span>
                        <span class="text-xl font-black font-mono text-sky-600 dark:text-sky-400 block">{{ props.stats.active_tokens ?? 0 }}</span>
                        <span class="text-[10px] text-slate-400 block mt-0.5">{{ __('dashboard.active_tokens') }}</span>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                </Link>
            </div>

            <!-- ========================================================================= -->
            <!-- 3. MY ACTIVE NUMBERS LIST                                                 -->
            <!-- ========================================================================= -->
            <div class="p-4 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                        <h2 class="text-xs font-black text-slate-900 dark:text-white">{{ __('dashboard.my_active_numbers_heading') }}</h2>
                    </div>
                    <Link
                        :href="route('numbers.index')"
                        class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
                    >
                        <span>{{ __('dashboard.view_all_lines') }}</span>
                        <span class="rtl:rotate-180">→</span>
                    </Link>
                </div>

                <!-- Number Cards -->
                <div v-if="props.recent_numbers && props.recent_numbers.length > 0" class="space-y-2.5">
                    <div
                        v-for="num in props.recent_numbers"
                        :key="num.id"
                        class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-800 flex items-center justify-between gap-2.5 hover:border-indigo-500/30 transition-colors"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 mb-1 flex-wrap">
                                <span class="text-sm leading-none">{{ num.prefix_flag || '📱' }}</span>
                                <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                                    {{ num.type_label }}
                                </span>
                                <span v-if="num.rond_tier && num.rond_tier !== 'regular'" class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                    {{ num.rond_tier_label || num.rond_tier }}
                                </span>
                                <span v-if="num.accepts_incoming_sms" class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    {{ __('dashboard.two_way_badge') }}
                                </span>
                            </div>

                            <div class="font-mono font-black text-base text-slate-900 dark:text-white" dir="ltr">
                                {{ num.formatted_number || num.number }}
                            </div>

                            <p v-if="num.display_title" class="text-[10px] text-slate-400 truncate mt-0.5">
                                {{ num.display_title }}
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-1.5 shrink-0">
                            <button
                                type="button"
                                @click="copyToClipboard(num.number, num.id)"
                                class="w-8 h-8 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 hover:text-indigo-600 flex items-center justify-center transition-colors cursor-pointer"
                                :title="__('dashboard.copy_number')"
                            >
                                <svg v-if="copiedId === num.id" class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                                </svg>
                                <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2" stroke-width="2"></rect>
                                    <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke-width="2"></path>
                                </svg>
                            </button>

                            <Link
                                :href="route('messages.index', { phone_number_id: num.id })"
                                class="w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 flex items-center justify-center transition-colors"
                                :title="__('dashboard.open_chat')"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="py-6 text-center rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-800">
                    <p class="text-xs font-bold text-slate-800 dark:text-slate-200">{{ __('dashboard.no_lines_yet_title') }}</p>
                    <p class="text-[11px] text-slate-400 mt-1 max-w-xs mx-auto">{{ __('dashboard.no_lines_yet_desc') }}</p>
                    <button
                        type="button"
                        @click="isAllocateModalOpen = true"
                        class="mt-3 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold inline-flex items-center gap-1.5 shadow-sm cursor-pointer"
                    >
                        <span>+ {{ __('dashboard.get_first_line_btn') }}</span>
                    </button>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 4. RECENT INBOUND MESSAGES                                                -->
            <!-- ========================================================================= -->
            <div class="p-4 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <h2 class="text-xs font-black text-slate-900 dark:text-white">{{ __('dashboard.recent_inbox_heading') }}</h2>
                    </div>
                    <Link
                        :href="route('messages.index')"
                        class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 hover:underline flex items-center gap-1"
                    >
                        <span>{{ __('dashboard.view_all_messages') }}</span>
                        <span class="rtl:rotate-180">→</span>
                    </Link>
                </div>

                <div v-if="props.unread_messages && props.unread_messages.length > 0" class="divide-y divide-slate-100 dark:divide-slate-800">
                    <Link
                        v-for="(msg, idx) in props.unread_messages"
                        :key="idx"
                        :href="route('messages.index', { phone_number_id: msg.phone_number_id, search: msg.sender })"
                        class="py-2.5 flex items-center justify-between gap-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/40 rounded-xl px-2 transition-colors"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-black font-mono text-slate-900 dark:text-white" dir="ltr">{{ msg.sender }}</span>
                                <span class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-400">
                                    {{ msg.count }}
                                </span>
                            </div>
                            <span class="text-[10px] text-slate-400 font-mono block mt-0.5" dir="ltr">
                                → {{ msg.destination_number }}
                            </span>
                        </div>
                        <span class="text-[10px] text-slate-400 shrink-0">{{ msg.received_at }}</span>
                    </Link>
                </div>

                <div v-else class="py-5 text-center text-[11px] text-slate-400">
                    {{ __('dashboard.no_messages_state') }}
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 5. RECENT WALLET TRANSACTIONS                                             -->
            <!-- ========================================================================= -->
            <div class="p-4 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <h2 class="text-xs font-black text-slate-900 dark:text-white">{{ __('dashboard.recent_transactions_heading') }}</h2>
                    </div>
                    <Link
                        :href="route('wallet.transactions')"
                        class="text-[11px] font-bold text-amber-600 dark:text-amber-400 hover:underline"
                    >
                        {{ __('dashboard.view_all_transactions') }}
                    </Link>
                </div>

                <div v-if="recentTransactions.length > 0" class="space-y-2">
                    <div
                        v-for="tx in recentTransactions"
                        :key="tx.id"
                        class="p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate">
                                {{ tx.description || tx.type_label }}
                            </span>
                            <span class="text-[10px] text-slate-400 block mt-0.5">
                                {{ tx.created_ago }}
                            </span>
                        </div>
                        <span
                            class="text-xs font-mono font-black shrink-0 px-2 py-0.5 rounded-lg"
                            :class="tx.is_credit ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400'"
                            dir="ltr"
                        >
                            {{ tx.is_credit ? '+' : '-' }}{{ tx.amount }} TRX
                        </span>
                    </div>
                </div>

                <div v-else class="py-5 text-center text-[11px] text-slate-400">
                    {{ __('dashboard.no_transactions_yet') }}
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 6. OPERATIONAL SHORTCUTS                                                  -->
            <!-- ========================================================================= -->
            <div class="p-4 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm space-y-2.5">
                <div class="flex items-center gap-2 pb-2 border-b border-slate-100 dark:border-slate-800">
                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                    <h2 class="text-xs font-black text-slate-900 dark:text-white">{{ __('dashboard.quick_shortcuts_heading') }}</h2>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button
                        type="button"
                        @click="isAllocateModalOpen = true"
                        class="p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800 text-start flex items-center gap-2.5 transition-colors cursor-pointer group"
                    >
                        <div class="w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate">{{ __('dashboard.action_new_line_title') }}</span>
                        </div>
                    </button>

                    <Link
                        :href="route('requests.index')"
                        class="p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800 text-start flex items-center gap-2.5 transition-colors group"
                    >
                        <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate">{{ __('dashboard.action_corporate_title') }}</span>
                        </div>
                    </Link>

                    <Link
                        :href="route('api-tokens.index')"
                        class="p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800 text-start flex items-center gap-2.5 transition-colors group"
                    >
                        <div class="w-8 h-8 rounded-xl bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate">{{ __('dashboard.action_api_title') }}</span>
                        </div>
                    </Link>

                    <Link
                        :href="route('tickets.create')"
                        class="p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800 text-start flex items-center gap-2.5 transition-colors group"
                    >
                        <div class="w-8 h-8 rounded-xl bg-violet-50 dark:bg-violet-950/60 text-violet-600 dark:text-violet-400 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate">{{ __('dashboard.action_ticket_title') }}</span>
                        </div>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Standard Allocation Modal -->
        <AllocateNumberModal
            :show="isAllocateModalOpen"
            :default-prefix="props.default_prefix"
            :active-prefixes="props.active_prefixes"
            :remaining-quota="props.quota.remaining"
            :max-quota="props.quota.max"
            @close="isAllocateModalOpen = false"
            @success="() => { isAllocateModalOpen = false; showToast(); }"
        />
    </UserLayout>
</template>
