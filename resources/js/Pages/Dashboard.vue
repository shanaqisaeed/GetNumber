<script setup lang="ts">
import AllocateNumberModal from '@/Components/AllocateNumberModal.vue';
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
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

interface SecurityOverview {
    two_factor_enabled: boolean;
    connected_identities_count: number;
    trust_tier: string;
    web_push_subscribed: boolean;
}

const props = defineProps<{
    quota: Quota;
    stats: Stats;
    recent_numbers: RecentNumber[];
    unread_messages: UnreadSenderSummary[];
    default_prefix: string;
    active_prefixes?: any[];
    wallet?: WalletInfo;
    security_overview?: SecurityOverview;
}>();

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showToast = (msg?: string) => {
    toastMessage.value = msg || __('dashboard.op_success');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
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
const baseLinePrice = computed(() => Math.floor(Number(props.wallet?.number_price_coin || 1)));
const recentTransactions = computed(() => props.wallet?.recent_transactions || []);

// Dynamic Greeting based on current hour
const greetingText = computed(() => {
    const hour = new Date().getHours();
    if (hour >= 5 && hour < 12) return __('dashboard.greeting_morning');
    if (hour >= 12 && hour < 17) return __('dashboard.greeting_afternoon');
    return __('dashboard.greeting_evening');
});
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
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
                >
                    <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-6">
            <!-- ========================================================================= -->
            <!-- 1. MODERN COMMAND HEADER HERO                                             -->
            <!-- ========================================================================= -->
            <div class="p-6 sm:p-8 rounded-3xl bg-gradient-to-br from-indigo-900/80 via-slate-900 to-slate-950 border border-indigo-500/20 text-white shadow-xl relative overflow-hidden">
                <!-- Background decorative elements -->
                <div class="absolute -end-16 -top-16 w-64 h-64 rounded-full bg-indigo-500/10 blur-3xl pointer-events-none"></div>
                <div class="absolute -start-16 -bottom-16 w-64 h-64 rounded-full bg-purple-500/10 blur-3xl pointer-events-none"></div>

                <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-bold mb-3">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span>{{ __('dashboard.header_badge') }}</span>
                        </div>
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-white">
                            {{ greetingText }}، {{ currentUser?.name || __('dashboard.dear_user') }}
                        </h1>
                        <p class="text-xs sm:text-sm text-slate-300 mt-1 max-w-xl leading-relaxed">
                            {{ __('dashboard.hero_lead') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3 shrink-0 flex-wrap">
                        <button
                            type="button"
                            @click="isAllocateModalOpen = true"
                            class="px-5 py-3 rounded-2xl bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white text-xs font-bold shadow-lg shadow-indigo-500/25 flex items-center gap-2 transition-all cursor-pointer hover:scale-[1.02] active:scale-[0.98]"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            <span>{{ __('dashboard.get_new_number_btn') }}</span>
                        </button>

                        <Link
                            :href="route('wallet.deposit.create')"
                            class="px-4 py-3 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/15 text-white text-xs font-bold backdrop-blur-md flex items-center gap-2 transition-all cursor-pointer"
                        >
                            <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ __('dashboard.charge_wallet_btn') }}</span>
                            <span class="px-2 py-0.5 rounded-lg bg-amber-500/20 text-amber-300 font-mono text-[11px] font-bold">{{ creditBalance }} TRX</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 2. ACTIONABLE NOTIFICATION BANNERS                                        -->
            <!-- ========================================================================= -->
            <div v-if="props.wallet?.pending_deposit" class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 backdrop-blur-md flex items-center justify-between gap-4 shadow-sm">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/30">
                        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-black text-slate-900 dark:text-white truncate">{{ __('dashboard.pending_invoice_title') }}</span>
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-500/20 text-amber-700 dark:text-amber-300">
                                {{ props.wallet.pending_deposit.status_label }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 font-mono truncate mt-0.5" dir="ltr">
                            #{{ props.wallet.pending_deposit.tracking_code }} • {{ props.wallet.pending_deposit.coin_amount }} {{ props.wallet.pending_deposit.currency }}
                        </p>
                    </div>
                </div>
                <Link
                    :href="route('wallet.deposit.show', props.wallet.pending_deposit.tracking_code)"
                    class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-black transition-all shrink-0 shadow-sm"
                >
                    {{ __('dashboard.continue_payment') }}
                </Link>
            </div>

            <div v-if="(props.stats.answered_tickets || 0) > 0" class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 backdrop-blur-md flex items-center justify-between gap-4 shadow-sm">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 border border-emerald-500/30">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-black text-slate-900 dark:text-white block truncate">
                            {{ __('dashboard.answered_tickets', { count: props.stats.answered_tickets }) }}
                        </span>
                        <span class="text-xs text-slate-600 dark:text-slate-400 block truncate">
                            {{ __('dashboard.answered_tickets_desc') }}
                        </span>
                    </div>
                </div>
                <Link
                    :href="route('tickets.index', { status: 'answered' })"
                    class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-black transition-all shrink-0 shadow-sm"
                >
                    {{ __('dashboard.view_reply') }}
                </Link>
            </div>

            <!-- ========================================================================= -->
            <!-- 3. FOUR KPI METRIC TILES                                                  -->
            <!-- ========================================================================= -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Tile 1: Active Lines & Quota -->
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col justify-between hover:border-indigo-500/40 transition-colors">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-slate-400 block">{{ __('dashboard.active_numbers_title') }}</span>
                            <div class="flex items-baseline gap-2 mt-1">
                                <span class="text-2xl font-black font-mono text-slate-900 dark:text-white">{{ props.quota.active }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">/ {{ props.quota.max }} {{ __('dashboard.quota_lines') }}</span>
                            </div>
                        </div>
                        <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80">
                        <div class="flex justify-between text-[11px] font-bold text-slate-500 dark:text-slate-400 mb-1.5">
                            <span>{{ __('dashboard.quota_used') }}</span>
                            <span>{{ props.quota.percentage }}%</span>
                        </div>
                        <div class="w-full h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full bg-indigo-500 rounded-full transition-all duration-500" :style="{ width: `${props.quota.percentage}%` }"></div>
                        </div>
                    </div>
                </div>

                <!-- Tile 2: Wallet Balance -->
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col justify-between hover:border-amber-500/40 transition-colors">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-slate-400 block">{{ __('dashboard.wallet_balance_label') }}</span>
                            <div class="flex items-baseline gap-1.5 mt-1">
                                <span class="text-2xl font-black font-mono text-amber-600 dark:text-amber-400">{{ creditBalance }}</span>
                                <span class="text-xs font-extrabold text-slate-500 dark:text-slate-400">TRX</span>
                            </div>
                        </div>
                        <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-[11px]">
                        <span class="text-slate-500 dark:text-slate-400">{{ __('dashboard.regular_line_from', { price: baseLinePrice }) }}</span>
                        <Link :href="route('wallet.deposit.create')" class="text-amber-600 dark:text-amber-400 font-bold hover:underline">
                            + {{ __('dashboard.charge_wallet_btn') }}
                        </Link>
                    </div>
                </div>

                <!-- Tile 3: Messages Traffic -->
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col justify-between hover:border-emerald-500/40 transition-colors">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-slate-400 block">{{ __('dashboard.total_sms_title') }}</span>
                            <div class="flex items-baseline gap-2 mt-1">
                                <span class="text-2xl font-black font-mono text-slate-900 dark:text-white">{{ props.stats.total_messages }}</span>
                                <span v-if="props.stats.unread_messages > 0" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-600 dark:text-rose-400 animate-pulse">
                                    {{ props.stats.unread_messages }} {{ __('dashboard.unread_badge') }}
                                </span>
                            </div>
                        </div>
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-[11px]">
                        <span class="text-emerald-600 dark:text-emerald-400 font-bold">
                            {{ props.stats.today_messages ?? 0 }} {{ __('dashboard.today_received') }}
                        </span>
                        <Link :href="route('messages.index')" class="text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 font-medium">
                            {{ __('dashboard.view_inbox') }} →
                        </Link>
                    </div>
                </div>

                <!-- Tile 4: Service Numbers & API Tokens -->
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm flex flex-col justify-between hover:border-purple-500/40 transition-colors">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-slate-400 block">{{ __('dashboard.corporate_numbers_title') }}</span>
                            <div class="flex items-baseline gap-2 mt-1">
                                <span class="text-2xl font-black font-mono text-purple-600 dark:text-purple-400">{{ props.stats.service_numbers ?? 0 }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400 font-bold">({{ props.stats.active_tokens ?? 0 }} {{ __('dashboard.active_tokens') }})</span>
                            </div>
                        </div>
                        <div class="w-12 h-12 rounded-2xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-[11px]">
                        <span v-if="props.stats.pending_requests > 0" class="text-amber-600 dark:text-amber-400 font-bold">
                            {{ props.stats.pending_requests }} {{ __('dashboard.pending_shortcodes') }}
                        </span>
                        <span v-else class="text-slate-500 dark:text-slate-400">
                            {{ __('dashboard.corporate_ready') }}
                        </span>
                        <Link :href="route('requests.index')" class="text-purple-600 dark:text-purple-400 font-bold hover:underline">
                            {{ __('dashboard.requests_link') }} →
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 4. BALANCED 2-COLUMN MAIN LAYOUT (8 cols main + 4 cols sidebar)          -->
            <!-- ========================================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- MAIN COLUMN: Active Numbers & Recent Inbound SMS (8 cols) -->
                <div class="lg:col-span-8 space-y-6">
                    <!-- Section: My Active Numbers -->
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2.5">
                                <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('dashboard.my_active_numbers_heading') }}</h2>
                            </div>
                            <Link
                                :href="route('numbers.index')"
                                class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
                            >
                                <span>{{ __('dashboard.view_all_lines') }}</span>
                                <svg class="w-3.5 h-3.5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </Link>
                        </div>

                        <!-- Has numbers -->
                        <div v-if="props.recent_numbers && props.recent_numbers.length > 0" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div
                                v-for="num in props.recent_numbers"
                                :key="num.id"
                                class="p-4 rounded-2xl bg-slate-50/80 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-800 flex flex-col justify-between hover:border-indigo-500/40 transition-all group"
                            >
                                <div>
                                    <div class="flex items-center justify-between gap-2 mb-2">
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg leading-none">{{ num.prefix_flag || '📱' }}</span>
                                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                                                {{ num.type_label }}
                                            </span>
                                            <span v-if="num.rond_tier && num.rond_tier !== 'regular'" class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                                                {{ num.rond_tier_label || num.rond_tier }}
                                            </span>
                                        </div>

                                        <span v-if="num.accepts_incoming_sms" class="inline-flex items-center gap-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-bold" :title="__('dashboard.two_way_sms')">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            {{ __('dashboard.two_way_badge') }}
                                        </span>
                                    </div>

                                    <!-- Phone Number Display & Copy -->
                                    <div class="flex items-center justify-between gap-2 my-2">
                                        <div class="font-mono font-black text-lg text-slate-900 dark:text-white tracking-wider" dir="ltr">
                                            {{ num.formatted_number || num.number }}
                                        </div>
                                        <button
                                            type="button"
                                            @click="copyToClipboard(num.number, num.id)"
                                            class="w-8 h-8 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-500 text-slate-500 dark:text-slate-400 hover:text-indigo-600 flex items-center justify-center transition-colors cursor-pointer"
                                            :title="__('dashboard.copy_number')"
                                        >
                                            <svg v-if="copiedId === num.id" class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                                            </svg>
                                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" stroke-width="2"></rect>
                                                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" stroke-width="2"></path>
                                            </svg>
                                        </button>
                                    </div>

                                    <p v-if="num.display_title" class="text-xs text-slate-500 dark:text-slate-400 truncate">
                                        {{ num.display_title }}
                                    </p>
                                </div>

                                <div class="mt-3 pt-2.5 border-t border-slate-200/60 dark:border-slate-700/60 flex items-center justify-between text-[11px]">
                                    <span class="text-slate-400">{{ num.allocated_ago }}</span>
                                    <Link
                                        :href="route('messages.index', { phone_number_id: num.id })"
                                        class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline inline-flex items-center gap-1"
                                    >
                                        <span>{{ __('dashboard.open_chat') }}</span>
                                        <svg class="w-3 h-3 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="5" y1="12" x2="19" y2="12"></line>
                                            <polyline points="12 5 19 12 12 19"></polyline>
                                        </svg>
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Empty state when no numbers -->
                        <div v-else class="mt-6 p-8 text-center rounded-2xl bg-slate-50/50 dark:bg-slate-800/30 border border-dashed border-slate-200 dark:border-slate-800">
                            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">{{ __('dashboard.no_lines_yet_title') }}</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                                {{ __('dashboard.no_lines_yet_desc') }}
                            </p>
                            <button
                                type="button"
                                @click="isAllocateModalOpen = true"
                                class="mt-4 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 inline-flex items-center gap-2 cursor-pointer"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <span>{{ __('dashboard.get_first_line_btn') }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Section: Recent Inbound Messages -->
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2.5">
                                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('dashboard.recent_inbox_heading') }}</h2>
                            </div>
                            <Link
                                :href="route('messages.index')"
                                class="text-xs font-bold text-emerald-600 dark:text-emerald-400 hover:underline flex items-center gap-1"
                            >
                                <span>{{ __('dashboard.view_all_messages') }}</span>
                                <svg class="w-3.5 h-3.5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </Link>
                        </div>

                        <!-- Has Messages -->
                        <div v-if="props.unread_messages && props.unread_messages.length > 0" class="mt-4 divide-y divide-slate-100 dark:divide-slate-800/80">
                            <Link
                                v-for="(msg, idx) in props.unread_messages"
                                :key="idx"
                                :href="route('messages.index', { phone_number_id: msg.phone_number_id, search: msg.sender })"
                                class="py-3 px-2 flex items-center justify-between gap-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 rounded-xl transition-colors group"
                            >
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-10 h-10 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 font-bold font-mono text-xs">
                                        SMS
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-black font-mono text-slate-900 dark:text-white" dir="ltr">{{ msg.sender }}</span>
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                                                {{ msg.count }} {{ __('dashboard.unread_count_badge') }}
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                            {{ __('dashboard.sent_to_line') }}: <span class="font-mono font-bold" dir="ltr">{{ msg.destination_number }}</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="text-end shrink-0">
                                    <span class="text-[10px] text-slate-400 block">{{ msg.received_at }}</span>
                                    <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400 group-hover:underline">
                                        {{ __('dashboard.reply_btn') }} →
                                    </span>
                                </div>
                            </Link>
                        </div>

                        <!-- Empty state when no messages -->
                        <div v-else class="mt-4 py-8 text-center text-xs text-slate-500 dark:text-slate-400">
                            <svg class="w-8 h-8 mx-auto mb-2 text-slate-300 dark:text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                            </svg>
                            <span>{{ __('dashboard.no_messages_state') }}</span>
                        </div>
                    </div>
                </div>

                <!-- SIDEBAR COLUMN: Financial Activity & Shortcuts & Security (4 cols) -->
                <div class="lg:col-span-4 space-y-6">
                    <!-- Widget: Recent Transactions -->
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('dashboard.recent_transactions_heading') }}</h2>
                            </div>
                            <Link
                                :href="route('wallet.transactions')"
                                class="text-xs font-bold text-amber-600 dark:text-amber-400 hover:underline"
                            >
                                {{ __('dashboard.view_all_transactions') }}
                            </Link>
                        </div>

                        <div v-if="recentTransactions.length > 0" class="mt-4 space-y-3">
                            <div
                                v-for="tx in recentTransactions"
                                :key="tx.id"
                                class="p-3 rounded-2xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate">
                                        {{ tx.description || tx.type_label }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-medium block mt-0.5">
                                        {{ tx.created_ago }}
                                    </span>
                                </div>
                                <span
                                    class="text-xs font-mono font-black shrink-0 px-2.5 py-1 rounded-xl"
                                    :class="tx.is_credit ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400'"
                                    dir="ltr"
                                >
                                    {{ tx.is_credit ? '+' : '-' }}{{ tx.amount }} TRX
                                </span>
                            </div>
                        </div>

                        <div v-else class="mt-4 py-6 text-center text-xs text-slate-400">
                            {{ __('dashboard.no_transactions_yet') }}
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                :href="route('wallet.deposit.create')"
                                class="w-full py-2.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 border border-amber-500/20 text-xs font-bold flex items-center justify-center gap-1.5 transition-colors"
                            >
                                <span>+ {{ __('dashboard.charge_wallet_btn') }}</span>
                            </Link>
                        </div>
                    </div>

                    <!-- Widget: Quick Operational Shortcuts -->
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center gap-2 pb-4 border-b border-slate-100 dark:border-slate-800">
                            <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('dashboard.quick_shortcuts_heading') }}</h2>
                        </div>

                        <div class="mt-4 space-y-2">
                            <button
                                type="button"
                                @click="isAllocateModalOpen = true"
                                class="w-full p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800 text-start flex items-center justify-between transition-colors cursor-pointer group"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">{{ __('dashboard.action_new_line_title') }}</span>
                                        <span class="text-[10px] text-slate-400 block">{{ __('dashboard.action_new_line_desc') }}</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 font-bold">→</span>
                            </button>

                            <Link
                                :href="route('requests.index')"
                                class="w-full p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800 text-start flex items-center justify-between transition-colors group"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">{{ __('dashboard.action_corporate_title') }}</span>
                                        <span class="text-[10px] text-slate-400 block">{{ __('dashboard.action_corporate_desc') }}</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400 group-hover:text-purple-600 dark:group-hover:text-purple-400 font-bold">→</span>
                            </Link>

                            <Link
                                :href="route('api-tokens.index')"
                                class="w-full p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800 text-start flex items-center justify-between transition-colors group"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">{{ __('dashboard.action_api_title') }}</span>
                                        <span class="text-[10px] text-slate-400 block">{{ __('dashboard.action_api_desc') }}</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400 group-hover:text-sky-600 dark:group-hover:text-sky-400 font-bold">→</span>
                            </Link>

                            <Link
                                :href="route('tickets.create')"
                                class="w-full p-3 rounded-2xl bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800 text-start flex items-center justify-between transition-colors group"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-violet-50 dark:bg-violet-950/60 text-violet-600 dark:text-violet-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">{{ __('dashboard.action_ticket_title') }}</span>
                                        <span class="text-[10px] text-slate-400 block">{{ __('dashboard.action_ticket_desc') }}</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400 group-hover:text-violet-600 dark:group-hover:text-violet-400 font-bold">→</span>
                            </Link>
                        </div>
                    </div>

                    <!-- Widget: Security Health -->
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                        <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-rose-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('dashboard.security_health_heading') }}</h2>
                            </div>
                            <Link
                                :href="route('profile.edit', { tab: 'security' })"
                                class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                            >
                                {{ __('dashboard.settings_btn') }}
                            </Link>
                        </div>

                        <div class="mt-4 space-y-3 text-xs">
                            <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50">
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('dashboard.two_factor_auth') }}</span>
                                <span
                                    class="px-2 py-0.5 rounded-md font-bold text-[10px]"
                                    :class="props.security_overview?.two_factor_enabled ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'"
                                >
                                    {{ props.security_overview?.two_factor_enabled ? __('dashboard.two_factor_active') : __('dashboard.two_factor_inactive') }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50">
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('dashboard.connected_identities') }}</span>
                                <span class="font-mono font-bold text-slate-900 dark:text-white">
                                    {{ props.security_overview?.connected_identities_count ?? 1 }} {{ __('dashboard.identities_unit') }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50">
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('dashboard.account_tier') }}</span>
                                <span class="uppercase font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                    {{ props.security_overview?.trust_tier || 'standard' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Allocation Modal -->
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
