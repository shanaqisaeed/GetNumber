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

const copyToClipboard = async (text: string, label?: string) => {
    const finalLabel = label || __('dashboard.copied_number');
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
        showToast(finalLabel);
    } catch {
        showToast(finalLabel);
    }
};

// Standard Number Allocation Modal State
const isAllocateModalOpen = ref(false);

// Computed helpers
const creditBalance = computed(() => Math.floor(Number(props.wallet?.balance || 0)));
const baseLinePrice = computed(() => Math.floor(Number(props.wallet?.number_price_coin || 1)));
const recentTransactions = computed(() => props.wallet?.recent_transactions || []);

// Active activity tab ('lines' | 'transactions')
const activeActivityTab = ref<'lines' | 'transactions'>('lines');

// Feature Hub Category Filter
type FeatureCategory = 'all' | 'telecom' | 'finance_api' | 'security_support';
const selectedCategory = ref<FeatureCategory>('all');

interface FeatureCardItem {
    id: string;
    category: Exclude<FeatureCategory, 'all'>;
    title: string;
    subtitle: string;
    description: string;
    badgeText: string;
    badgeClass: string;
    iconBgClass: string;
    borderHoverClass: string;
    highlights: string[];
    primaryAction: {
        label: string;
        href?: string;
        onClick?: () => void;
        external?: boolean;
    };
    secondaryAction?: {
        label: string;
        href: string;
        external?: boolean;
    };
}

const userAreaFeatures = computed<FeatureCardItem[]>(() => [
    {
        id: 'standard-lines',
        category: 'telecom',
        title: __('dashboard.features.numbers.title'),
        subtitle: __('dashboard.features.numbers.subtitle'),
        description: __('dashboard.features.numbers.desc'),
        badgeText: __('dashboard.features.numbers.badge', { count: props.stats.standard_numbers ?? props.stats.active_numbers }),
        badgeClass: 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/30',
        iconBgClass: 'from-indigo-600 to-blue-600 shadow-indigo-500/25',
        borderHoverClass: 'hover:border-indigo-500/45',
        highlights: [
            __('dashboard.features.numbers.h1'),
            __('dashboard.features.numbers.h2'),
            __('dashboard.features.numbers.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.numbers.btn'),
            href: route('numbers.index'),
        },
        secondaryAction: undefined,
    },
    {
        id: 'commercial-shortcodes',
        category: 'telecom',
        title: __('dashboard.features.requests.title'),
        subtitle: __('dashboard.features.requests.subtitle'),
        description: __('dashboard.features.requests.desc'),
        badgeText: (props.stats.pending_requests || 0) > 0
            ? __('dashboard.features.requests.pending_badge', { count: props.stats.pending_requests })
            : __('dashboard.features.requests.active_badge', { count: props.stats.service_numbers ?? 0 }),
        badgeClass: (props.stats.pending_requests || 0) > 0
            ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30'
            : 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border-purple-500/30',
        iconBgClass: 'from-purple-600 to-fuchsia-600 shadow-purple-500/25',
        borderHoverClass: 'hover:border-purple-500/45',
        highlights: [
            __('dashboard.features.requests.h1'),
            __('dashboard.features.requests.h2'),
            __('dashboard.features.requests.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.requests.btn'),
            href: route('requests.index'),
        },
    },
    {
        id: 'realtime-messenger',
        category: 'telecom',
        title: __('dashboard.features.messages.title'),
        subtitle: __('dashboard.features.messages.subtitle'),
        description: __('dashboard.features.messages.desc'),
        badgeText: (props.stats.unread_messages || 0) > 0
            ? __('dashboard.features.messages.unread_badge', { count: props.stats.unread_messages })
            : __('dashboard.features.messages.total_badge', { count: props.stats.total_messages }),
        badgeClass: (props.stats.unread_messages || 0) > 0
            ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30 animate-pulse'
            : 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
        iconBgClass: 'from-emerald-600 to-teal-600 shadow-emerald-500/25',
        borderHoverClass: 'hover:border-emerald-500/45',
        highlights: [
            __('dashboard.features.messages.h1'),
            __('dashboard.features.messages.h2'),
            __('dashboard.features.messages.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.messages.btn'),
            href: route('messages.index'),
        },
    },
    {
        id: 'crypto-wallet',
        category: 'finance_api',
        title: __('dashboard.features.wallet.title'),
        subtitle: __('dashboard.features.wallet.subtitle'),
        description: __('dashboard.features.wallet.desc'),
        badgeText: __('dashboard.features.wallet.badge', { count: creditBalance.value }),
        badgeClass: 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
        iconBgClass: 'from-amber-500 to-orange-600 shadow-amber-500/25',
        borderHoverClass: 'hover:border-amber-500/45',
        highlights: [
            __('dashboard.features.wallet.h1'),
            __('dashboard.features.wallet.h2'),
            __('dashboard.features.wallet.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.wallet.btn1'),
            href: route('profile.edit', { tab: 'wallet' }),
        },
        secondaryAction: {
            label: __('dashboard.features.wallet.btn2'),
            href: route('wallet.transactions'),
        },
    },
    {
        id: 'developer-api',
        category: 'finance_api',
        title: __('dashboard.features.api.title'),
        subtitle: __('dashboard.features.api.subtitle'),
        description: __('dashboard.features.api.desc'),
        badgeText: __('dashboard.features.api.badge', { count: props.stats.active_tokens ?? 0 }),
        badgeClass: 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 border-cyan-500/30',
        iconBgClass: 'from-cyan-600 to-sky-600 shadow-cyan-500/25',
        borderHoverClass: 'hover:border-cyan-500/45',
        highlights: [
            __('dashboard.features.api.h1'),
            __('dashboard.features.api.h2'),
            __('dashboard.features.api.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.api.btn1'),
            href: route('api-tokens.index'),
        },
        secondaryAction: {
            label: __('dashboard.features.api.btn2'),
            href: route('api.docs'),
        },
    },
    {
        id: 'support-tickets',
        category: 'security_support',
        title: __('dashboard.features.tickets.title'),
        subtitle: __('dashboard.features.tickets.subtitle'),
        description: __('dashboard.features.tickets.desc'),
        badgeText: (props.stats.answered_tickets || 0) > 0
            ? __('dashboard.features.tickets.answered_badge', { count: props.stats.answered_tickets })
            : __('dashboard.features.tickets.open_badge', { count: props.stats.open_tickets ?? 0 }),
        badgeClass: (props.stats.answered_tickets || 0) > 0
            ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
            : 'bg-violet-500/15 text-violet-600 dark:text-violet-400 border-violet-500/30',
        iconBgClass: 'from-violet-600 to-indigo-600 shadow-violet-500/25',
        borderHoverClass: 'hover:border-violet-500/45',
        highlights: [
            __('dashboard.features.tickets.h1'),
            __('dashboard.features.tickets.h2'),
            __('dashboard.features.tickets.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.tickets.btn1'),
            href: route('tickets.index'),
        },
        secondaryAction: {
            label: __('dashboard.features.tickets.btn2'),
            href: route('tickets.create'),
        },
    },
    {
        id: 'security-2fa',
        category: 'security_support',
        title: __('dashboard.features.security.title'),
        subtitle: __('dashboard.features.security.subtitle'),
        description: __('dashboard.features.security.desc'),
        badgeText: props.security_overview?.two_factor_enabled
            ? __('dashboard.features.security.badge_active')
            : __('dashboard.features.security.badge_inactive'),
        badgeClass: props.security_overview?.two_factor_enabled
            ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
            : 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
        iconBgClass: 'from-rose-600 to-pink-600 shadow-rose-500/25',
        borderHoverClass: 'hover:border-rose-500/45',
        highlights: [
            __('dashboard.features.security.h1'),
            __('dashboard.features.security.h2'),
            __('dashboard.features.security.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.security.btn'),
            href: route('profile.edit', { tab: 'security' }),
        },
    },
    {
        id: 'identities-sessions',
        category: 'security_support',
        title: __('dashboard.features.identities.title'),
        subtitle: __('dashboard.features.identities.subtitle'),
        description: __('dashboard.features.identities.desc'),
        badgeText: __('dashboard.features.identities.badge', { count: props.security_overview?.connected_identities_count ?? 1 }),
        badgeClass: 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border-sky-500/30',
        iconBgClass: 'from-sky-600 to-blue-600 shadow-sky-500/25',
        borderHoverClass: 'hover:border-sky-500/45',
        highlights: [
            __('dashboard.features.identities.h1'),
            __('dashboard.features.identities.h2'),
            __('dashboard.features.identities.h3'),
        ],
        primaryAction: {
            label: __('dashboard.features.identities.btn1'),
            href: route('profile.edit', { tab: 'identities' }),
        },
        secondaryAction: {
            label: __('dashboard.features.identities.btn2'),
            href: route('profile.edit', { tab: 'sessions' }),
        },
    },
]);

const filteredFeatures = computed(() => {
    if (selectedCategory.value === 'all') return userAreaFeatures.value;
    return userAreaFeatures.value.filter((f) => f.category === selectedCategory.value);
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

        <div class="space-y-4">
            <!-- ========================================================================= -->
            <!-- 0. LIVE ACTIONABLE ALERTS (Pending Deposit / Answered Tickets)            -->
            <!-- ========================================================================= -->
            <div v-if="props.wallet?.pending_deposit" class="p-3.5 rounded-2xl bg-amber-500/15 border border-amber-500/35 backdrop-blur-md flex items-center justify-between gap-3 shadow-sm">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-9 h-9 rounded-xl bg-amber-500/25 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/30">
                        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs font-black text-slate-900 dark:text-white truncate">{{ __('dashboard.pending_invoice_title') }}</span>
                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-amber-500/20 text-amber-700 dark:text-amber-300">
                                {{ props.wallet.pending_deposit.status_label }}
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-600 dark:text-slate-300 font-mono truncate mt-0.5" dir="ltr">
                            #{{ props.wallet.pending_deposit.tracking_code }} • {{ props.wallet.pending_deposit.coin_amount }} {{ props.wallet.pending_deposit.currency }}
                        </p>
                    </div>
                </div>
                <Link
                    :href="route('wallet.deposit.show', props.wallet.pending_deposit.tracking_code)"
                    class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-black transition-all shrink-0 shadow-sm"
                >{{ __('dashboard.continue_payment') }}</Link>
            </div>

            <div v-if="(props.stats.answered_tickets || 0) > 0" class="p-3.5 rounded-2xl bg-emerald-500/15 border border-emerald-500/35 backdrop-blur-md flex items-center justify-between gap-3 shadow-sm">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-9 h-9 rounded-xl bg-emerald-500/25 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 border border-emerald-500/30">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-black text-slate-900 dark:text-white block truncate">
                            {{ __('dashboard.answered_tickets', { count: props.stats.answered_tickets }) }}
                        </span>
                        <span class="text-[11px] text-slate-600 dark:text-slate-300 block truncate">
                            {{ __('dashboard.answered_tickets_desc') }}
                        </span>
                    </div>
                </div>
                <Link
                    :href="route('tickets.index', { status: 'answered' })"
                    class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-black transition-all shrink-0 shadow-sm"
                >{{ __('dashboard.view_reply') }}</Link>
            </div>

            <!-- ========================================================================= -->
            <!-- 1. UNIFIED COMMAND HERO: WALLET CREDIT & LINE QUOTA CENTER                -->
            <!-- ========================================================================= -->
            <div class="dash-wallet-card !p-4 sm:!p-5 !mb-0 space-y-4">
                <!-- Top Status & Security Posture Bar -->
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200/80 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <span class="status-dot-pulse"></span>
                        <span class="text-xs font-extrabold text-slate-800 dark:text-slate-100">{{ __('dashboard.header_badge') }}</span>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <Link
                            :href="route('profile.edit', { tab: 'security' })"
                            class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border transition-all flex items-center gap-1"
                            :class="props.security_overview?.two_factor_enabled
                                ? 'bg-emerald-500/15 border-emerald-500/35 text-emerald-700 dark:text-emerald-400'
                                : 'bg-amber-500/15 border-amber-500/35 text-amber-700 dark:text-amber-400 hover:bg-amber-500/25'"
                            :title="props.security_overview?.two_factor_enabled ? __('dashboard.two_factor_active_tooltip') : __('dashboard.two_factor_inactive_tooltip')"
                        >
                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                            <span>{{ props.security_overview?.two_factor_enabled ? __('dashboard.two_factor_secure') : __('dashboard.two_factor_enable') }}</span>
                        </Link>

                        <span class="wallet-chip">{{ __('dashboard.account_active') }}</span>
                    </div>
                </div>

                <!-- Wallet Balance & Quick Recharge Section -->
                <div class="p-3.5 rounded-2xl bg-white/75 dark:bg-slate-950/55 border border-slate-200/80 dark:border-white/10 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 block">{{ __('dashboard.wallet_balance_label') }}</span>
                            <div class="wallet-amount !mt-1">
                                <span>{{ creditBalance }}</span>
                                <span class="wallet-currency text-xs font-extrabold">{{ __('dashboard.currency_trx') }}</span>
                            </div>
                        </div>

                        <div class="text-left space-y-1">
                            <span class="px-2 py-0.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-indigo-700 dark:text-indigo-300 text-[10px] font-bold block">
                                {{ __('dashboard.regular_line_from', { price: baseLinePrice }) }}
                            </span>
                            <span class="px-2 py-0.5 rounded-lg bg-purple-500/10 border border-purple-500/20 text-purple-700 dark:text-purple-300 text-[10px] font-bold block">
                                {{ __('dashboard.base_prefix') }} <b class="font-mono" dir="ltr">{{ props.default_prefix }}</b>
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <Link
                            :href="route('profile.edit', { tab: 'wallet' })"
                            class="wallet-btn-topup !py-2.5 !text-xs"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                            </svg>
                            <span>{{ __('dashboard.quick_recharge') }}</span>
                        </Link>

                        <Link
                            :href="route('wallet.transactions')"
                            class="wallet-btn-history !py-2.5 !text-xs gap-1.5"
                        >
                            <svg class="w-4 h-4 text-indigo-500 dark:text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                            <span>{{ __('dashboard.financial_ledger') }}</span>
                        </Link>
                    </div>
                </div>

                <!-- Integrated Line Quota & Instant Allocation Section -->
                <div class="p-3.5 rounded-2xl bg-white/75 dark:bg-slate-950/55 border border-slate-200/80 dark:border-white/10 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 block">{{ __('dashboard.capacity_label') }}</span>
                            <div class="flex items-baseline gap-1.5 mt-0.5">
                                <span class="text-lg font-black font-mono text-slate-900 dark:text-white">{{ props.quota.active }}</span>
                                <span class="text-xs font-bold text-slate-500 dark:text-slate-400">{{ __('dashboard.of_max_quota', { max: props.quota.max }) }}</span>
                            </div>
                        </div>

                        <span
                            class="px-2.5 py-1 rounded-xl text-[10px] font-extrabold border"
                            :class="props.quota.remaining > 0
                                ? 'bg-emerald-500/15 border-emerald-500/35 text-emerald-700 dark:text-emerald-400'
                                : 'bg-rose-500/15 border-rose-500/35 text-rose-700 dark:text-rose-400'"
                        >
                            {{ props.quota.remaining > 0 ? __('dashboard.free_capacity', { count: props.quota.remaining }) : __('dashboard.quota_full') }}
                        </span>
                    </div>

                    <!-- Progress Meter with Light/Dark Support -->
                    <div>
                        <div class="flex items-center justify-between text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1.5">
                            <span>{{ __('dashboard.utilization_rate', { rate: props.quota.percentage }) }}</span>
                            <span>{{ __('dashboard.lines_summary', { standard: props.stats.standard_numbers ?? props.quota.active, service: props.stats.service_numbers ?? 0 }) }}</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-200/90 dark:bg-slate-800/80 overflow-hidden p-0.5 border border-slate-300/70 dark:border-white/10">
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 transition-all duration-700 shadow-sm"
                                :style="{ width: `${Math.max(4, props.quota.percentage)}%` }"
                            ></div>
                        </div>
                    </div>

                    <!-- Line Allocation Quick Actions -->
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button
                            type="button"
                            @click="isAllocateModalOpen = true"
                            :disabled="props.quota.remaining <= 0"
                            class="wallet-btn-topup !py-2.5 !text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                                <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"></line>
                            </svg>
                            <span>{{ __('dashboard.reserve_9digit') }}</span>
                        </button>

                        <Link
                            :href="route('requests.index')"
                            class="wallet-btn-history !py-2.5 !text-xs gap-1.5"
                        >
                            <svg class="w-4 h-4 text-purple-500 dark:text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <span>{{ __('dashboard.request_commercial') }}</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 2. FOUR LIVE OPERATIONAL METRIC CARDS (2x2 GRID)                          -->
            <!-- ========================================================================= -->
            <div class="dash-stats-grid !mb-0">
                <!-- Active Numbers -->
                <Link :href="route('numbers.index')" class="stat-card group flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="stat-icon-wrapper !mb-0 bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 group-hover:scale-105 transition-transform">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                                <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"></line>
                            </svg>
                        </div>
                        <span class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-full">{{ __('dashboard.my_numbers_link') }}</span>
                    </div>
                    <div class="mt-2.5">
                        <div class="stat-val text-slate-900 dark:text-white">
                            {{ props.stats.active_numbers }} <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400">{{ __('dashboard.active_numbers_unit') }}</span>
                        </div>
                        <div class="stat-label font-semibold">
                            {{ __('dashboard.standard_and_service', { standard: props.stats.standard_numbers ?? props.stats.active_numbers, service: props.stats.service_numbers ?? 0 }) }}
                        </div>
                    </div>
                </Link>

                <!-- Total & Today SMS Traffic -->
                <Link :href="route('messages.index')" class="stat-card group flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="stat-icon-wrapper !mb-0 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/25 group-hover:scale-105 transition-transform">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                            </svg>
                        </div>
                        <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                            {{ __('dashboard.today_label', { count: props.stats.today_messages ?? 0 }) }}
                        </span>
                    </div>
                    <div class="mt-2.5">
                        <div class="stat-val text-slate-900 dark:text-white">
                            {{ props.stats.total_messages }} <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">{{ __('dashboard.total_messages_unit') }}</span>
                        </div>
                        <div class="stat-label font-semibold">
                            {{ __('dashboard.inbound_outbound', { inbound: props.stats.inbound_messages ?? props.stats.total_messages, outbound: props.stats.outbound_messages ?? 0 }) }}
                        </div>
                    </div>
                </Link>

                <!-- Unread Messages -->
                <Link :href="route('messages.index')" class="stat-card group flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="stat-icon-wrapper !mb-0 bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/25 group-hover:scale-105 transition-transform">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span
                            class="text-[10px] font-bold px-2 py-0.5 rounded-full"
                            :class="props.stats.unread_messages > 0
                                ? 'bg-rose-500 text-white animate-pulse'
                                : 'bg-slate-500/10 text-slate-500 dark:text-slate-400'"
                        >
                            {{ props.stats.unread_messages > 0 ? __('dashboard.new_messages_badge') : __('dashboard.up_to_date_badge') }}
                        </span>
                    </div>
                    <div class="mt-2.5">
                        <div class="stat-val text-slate-900 dark:text-white">
                            {{ props.stats.unread_messages }} <span class="text-xs font-bold text-rose-600 dark:text-rose-400">{{ __('dashboard.unread_unit') }}</span>
                        </div>
                        <div class="stat-label font-semibold">
                            {{ props.stats.unread_messages > 0 ? __('dashboard.awaiting_review') : __('dashboard.all_read') }}
                        </div>
                    </div>
                </Link>

                <!-- Pending Requests & API / Support -->
                <Link :href="route('requests.index')" class="stat-card group flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="stat-icon-wrapper !mb-0 bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/25 group-hover:scale-105 transition-transform">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <circle cx="12" cy="12" r="10" stroke-width="2"></circle>
                                <polyline points="12 6 12 12 16 14" stroke-width="2"></polyline>
                            </svg>
                        </div>
                        <span class="text-[10px] font-bold text-amber-600 dark:text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-full">
                            {{ __('dashboard.approved_count', { count: props.stats.approved_requests ?? 0 }) }}
                        </span>
                    </div>
                    <div class="mt-2.5">
                        <div class="stat-val text-slate-900 dark:text-white">
                            {{ props.stats.pending_requests }} <span class="text-xs font-bold text-amber-600 dark:text-amber-400">{{ __('dashboard.pending_requests_unit') }}</span>
                        </div>
                        <div class="stat-label font-semibold">
                            {{ __('dashboard.tokens_and_tickets', { tokens: props.stats.active_tokens ?? 0, tickets: props.stats.open_tickets ?? 0 }) }}
                        </div>
                    </div>
                </Link>
            </div>

            <!-- ========================================================================= -->
            <!-- 3. UNREAD INBOUND MESSAGES STREAM (If Any)                                -->
            <!-- ========================================================================= -->
            <div v-if="props.unread_messages.length > 0" class="glass-card !p-4 border-rose-500/30 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                        <span>{{ __('dashboard.unread_inbox_title') }}</span>
                        <span class="text-[10px] font-extrabold text-rose-600 dark:text-rose-400 bg-rose-500/15 border border-rose-500/30 px-2 py-0.5 rounded-full">
                            {{ __('dashboard.messages_count', { count: props.stats.unread_messages }) }}
                        </span>
                    </span>
                    <Link :href="route('messages.index')" class="section-link">{{ __('dashboard.open_inbox') }}</Link>
                </div>

                <div class="space-y-2">
                    <div
                        v-for="item in props.unread_messages"
                        :key="item.sender + '_' + item.phone_number_id"
                        class="p-3 rounded-2xl bg-white/80 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 hover:border-rose-500/40 transition-all flex items-center justify-between gap-2.5"
                    >
                        <div class="w-9 h-9 rounded-xl bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/25 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-extrabold text-xs text-slate-900 dark:text-white font-mono truncate" dir="ltr">
                                    {{ item.sender }}
                                </span>
                                <span class="text-[10px] font-extrabold text-rose-600 dark:text-rose-400 bg-rose-500/15 px-2 py-0.5 rounded-full border border-rose-500/30 shrink-0">
                                    {{ __('dashboard.new_messages_count', { count: item.count }) }}
                                </span>
                            </div>
                            <div class="text-[10px] text-slate-500 dark:text-slate-400 flex items-center gap-1.5 mt-0.5 truncate">
                                <span>{{ __('dashboard.dest_line') }} <b class="font-mono text-slate-700 dark:text-slate-300" dir="ltr">{{ item.destination_number }}</b></span>
                                <span>•</span>
                                <span class="text-rose-500 dark:text-rose-400 font-semibold">{{ item.received_at }}</span>
                            </div>
                        </div>

                        <Link
                            :href="route('messages.index', { phone_number_id: item.phone_number_id, peer: item.sender })"
                            class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-[11px] font-bold shrink-0 shadow-sm transition-all"
                        >{{ __('dashboard.view_action') }}</Link>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 4. USER AREA FEATURES SHOWCASE & QUICK ACCESS HUB                         -->
            <!-- ========================================================================= -->
            <div class="glass-card !p-4 sm:!p-5 space-y-4">
                <!-- Section Header -->
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                </svg>
                            </div>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">
                                {{ __('dashboard.modules_title') }}
                            </h2>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                            {{ __('dashboard.modules_subtitle') }}
                        </p>
                    </div>
                    <span class="px-2.5 py-1 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-600 dark:text-indigo-400 text-[10px] font-black shrink-0">
                        {{ __('dashboard.active_modules_count', { count: userAreaFeatures.length }) }}
                    </span>
                </div>

                <!-- Category Filter Pills -->
                <div class="grid grid-cols-4 gap-1.5 p-1.5 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200/80 dark:border-white/10 text-[11px] font-bold">
                    <button
                        type="button"
                        @click="selectedCategory = 'all'"
                        class="py-2 px-1.5 rounded-xl transition-all text-center truncate cursor-pointer"
                        :class="selectedCategory === 'all'
                            ? 'bg-indigo-600 text-white shadow-sm font-black'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    >{{ __('dashboard.filter_all') }}</button>
                    <button
                        type="button"
                        @click="selectedCategory = 'telecom'"
                        class="py-2 px-1.5 rounded-xl transition-all text-center truncate cursor-pointer"
                        :class="selectedCategory === 'telecom'
                            ? 'bg-indigo-600 text-white shadow-sm font-black'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    >{{ __('dashboard.filter_numbers_messages') }}</button>
                    <button
                        type="button"
                        @click="selectedCategory = 'finance_api'"
                        class="py-2 px-1.5 rounded-xl transition-all text-center truncate cursor-pointer"
                        :class="selectedCategory === 'finance_api'
                            ? 'bg-indigo-600 text-white shadow-sm font-black'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    >{{ __('dashboard.filter_finance_api') }}</button>
                    <button
                        type="button"
                        @click="selectedCategory = 'security_support'"
                        class="py-2 px-1.5 rounded-xl transition-all text-center truncate cursor-pointer"
                        :class="selectedCategory === 'security_support'
                            ? 'bg-indigo-600 text-white shadow-sm font-black'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    >{{ __('dashboard.filter_security_tickets') }}</button>
                </div>

                <!-- Feature Cards List -->
                <div class="space-y-3">
                    <div
                        v-for="feature in filteredFeatures"
                        :key="feature.id"
                        class="p-3.5 rounded-2xl bg-white/85 dark:bg-slate-950/55 border border-slate-200/90 dark:border-white/10 transition-all space-y-2.5"
                        :class="feature.borderHoverClass"
                    >
                        <!-- Card Top Row: Icon, Title/Subtitle, Live Status Badge -->
                        <div class="flex items-start justify-between gap-2.5">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div
                                    class="w-10 h-10 rounded-2xl bg-gradient-to-tr text-white flex items-center justify-center shrink-0 shadow-md"
                                    :class="feature.iconBgClass"
                                >
                                    <!-- 1. Standard Lines Icon -->
                                    <svg v-if="feature.id === 'standard-lines'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                                        <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"></line>
                                    </svg>
                                    <!-- 2. Commercial Shortcodes Icon -->
                                    <svg v-else-if="feature.id === 'commercial-shortcodes'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                    <!-- 3. Realtime Messenger Icon -->
                                    <svg v-else-if="feature.id === 'realtime-messenger'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                    <!-- 4. Crypto Wallet Icon -->
                                    <svg v-else-if="feature.id === 'crypto-wallet'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                    </svg>
                                    <!-- 5. Developer API Icon -->
                                    <svg v-else-if="feature.id === 'developer-api'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                    </svg>
                                    <!-- 6. Support Tickets Icon -->
                                    <svg v-else-if="feature.id === 'support-tickets'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    <!-- 7. Security 2FA Icon -->
                                    <svg v-else-if="feature.id === 'security-2fa'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                    <!-- 8. Identities & Sessions Icon -->
                                    <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </div>

                                <div class="min-w-0">
                                    <h3 class="text-xs font-black text-slate-900 dark:text-white truncate">
                                        {{ feature.title }}
                                    </h3>
                                    <p class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                        {{ feature.subtitle }}
                                    </p>
                                </div>
                            </div>

                            <span
                                class="px-2 py-0.5 rounded-full text-[10px] font-extrabold border shrink-0"
                                :class="feature.badgeClass"
                            >
                                {{ feature.badgeText }}
                            </span>
                        </div>

                        <!-- Description -->
                        <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed">
                            {{ feature.description }}
                        </p>

                        <!-- Highlights Chips & Direct Action Links -->
                        <div class="pt-2 border-t border-slate-200/70 dark:border-white/10 flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-1 flex-wrap">
                                <span
                                    v-for="tag in feature.highlights"
                                    :key="tag"
                                    class="px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-white/5 border border-slate-200/80 dark:border-white/10 text-[10px] font-semibold text-slate-600 dark:text-slate-300"
                                >
                                    {{ tag }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1.5 mr-auto">
                                <button
                                    v-if="feature.id === 'standard-lines' && props.quota.remaining > 0"
                                    type="button"
                                    @click="isAllocateModalOpen = true"
                                    class="px-2.5 py-1.5 rounded-xl bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-700 dark:text-indigo-300 border border-indigo-500/30 text-[11px] font-extrabold transition-all cursor-pointer"
                                >{{ __('dashboard.instant_reserve') }}</button>

                                <Link
                                    v-if="feature.secondaryAction"
                                    :href="feature.secondaryAction.href"
                                    class="px-2.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/15 text-slate-700 dark:text-slate-200 text-[11px] font-bold transition-all"
                                >
                                    {{ feature.secondaryAction.label }}
                                </Link>

                                <Link
                                    v-if="feature.primaryAction.href"
                                    :href="feature.primaryAction.href"
                                    class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-[11px] font-extrabold transition-all flex items-center gap-1 shadow-xs"
                                >
                                    <span>{{ feature.primaryAction.label }}</span>
                                    <svg class="w-3 h-3 rtl:rotate-0 ltr:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 5. RECENT ACTIVE LINES & RECENT FINANCIAL TRANSACTIONS                    -->
            <!-- ========================================================================= -->
            <div class="glass-card !p-4 sm:!p-5 space-y-3.5">
                <!-- Segmented Switcher Header -->
                <div class="flex items-center justify-between gap-2 pb-2.5 border-b border-slate-200/80 dark:border-white/10">
                    <div class="flex items-center gap-1.5 p-1 rounded-xl bg-slate-100 dark:bg-slate-950/60 border border-slate-200/80 dark:border-white/10">
                        <button
                            type="button"
                            @click="activeActivityTab = 'lines'"
                            class="px-3 py-1.5 rounded-lg text-xs font-extrabold transition-all cursor-pointer"
                            :class="activeActivityTab === 'lines'
                                ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                        >
                            {{ __('dashboard.recent_active_numbers', { count: props.stats.active_numbers }) }}
                        </button>
                        <button
                            type="button"
                            @click="activeActivityTab = 'transactions'"
                            class="px-3 py-1.5 rounded-lg text-xs font-extrabold transition-all cursor-pointer"
                            :class="activeActivityTab === 'transactions'
                                ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                        >
                            {{ __('dashboard.recent_transactions', { count: recentTransactions.length }) }}
                        </button>
                    </div>

                    <Link
                        :href="activeActivityTab === 'lines' ? route('numbers.index') : route('wallet.transactions')"
                        class="section-link shrink-0"
                    >{{ __('dashboard.view_all') }}</Link>
                </div>

                <!-- TAB A: Recent Allocated Lines -->
                <div v-if="activeActivityTab === 'lines'">
                    <div v-if="props.recent_numbers.length > 0" class="space-y-2.5">
                        <div
                            v-for="phone in props.recent_numbers"
                            :key="phone.id"
                            class="p-3 rounded-2xl bg-white/85 dark:bg-slate-950/55 border border-slate-200/90 dark:border-white/10 hover:border-indigo-500/40 transition-all flex items-center justify-between gap-2.5"
                        >
                            <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 to-purple-600 text-white shadow-md shadow-indigo-500/20 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                                    <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2.5"></line>
                                </svg>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span dir="ltr" class="font-mono text-sm font-black text-slate-900 dark:text-white tracking-wider truncate">
                                        {{ phone.formatted_number }}
                                    </span>
                                    <span
                                        v-if="phone.rond_tier && phone.rond_tier !== 'regular'"
                                        class="px-2 py-0.5 rounded-full text-[9px] font-extrabold border shrink-0"
                                        :class="phone.rond_tier === 'golden_rond'
                                            ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                            : 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border-sky-500/30'"
                                    >
                                        {{ phone.rond_tier_label }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                                    <span class="status-dot-pulse !w-1.5 !h-1.5"></span>
                                    <span class="font-bold text-slate-700 dark:text-slate-300 truncate">{{ phone.display_title || phone.type_label }}</span>
                                    <span>•</span>
                                    <span>{{ phone.allocated_ago }}</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-1.5 shrink-0">
                                <button
                                    type="button"
                                    @click="copyToClipboard(phone.number)"
                                    class="btn-copy-code"
                                    :title="__('dashboard.copy_number_title')"
                                >{{ __('dashboard.copy_label') }}</button>
                                <Link
                                    :href="route('messages.index', { phone_number_id: phone.id })"
                                    class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 hover:bg-indigo-600 hover:text-white flex items-center justify-center transition-all"
                                    :title="__('dashboard.view_line_messages')"
                                >
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State for Lines -->
                    <div v-else class="p-6 rounded-2xl bg-white/60 dark:bg-slate-950/40 border border-slate-200/80 dark:border-white/10 text-center space-y-3">
                        <div class="w-12 h-12 mx-auto rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                                <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"></line>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xs font-extrabold text-slate-900 dark:text-white">{{ __('dashboard.no_numbers_title') }}</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                {{ __('dashboard.no_numbers_desc') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="isAllocateModalOpen = true"
                            class="glass-btn-primary !w-auto !px-5 !py-2.5 !text-xs mx-auto"
                        >{{ __('dashboard.get_first_number') }}</button>
                    </div>
                </div>

                <!-- TAB B: Recent Wallet Transactions -->
                <div v-else>
                    <div v-if="recentTransactions.length > 0" class="space-y-2.5">
                        <div
                            v-for="tx in recentTransactions"
                            :key="tx.id"
                            class="p-3 rounded-2xl bg-white/85 dark:bg-slate-950/55 border border-slate-200/90 dark:border-white/10 flex items-center justify-between gap-2.5"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="tx.badge_classes">
                                        {{ tx.type_label }}
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-mono">{{ tx.created_ago }}</span>
                                </div>
                                <p class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate mt-1">
                                    {{ tx.description }}
                                </p>
                            </div>

                            <div class="text-left shrink-0 font-mono text-sm font-black" :class="tx.is_credit ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                                {{ tx.is_credit ? '+' : '-' }}{{ tx.amount }} <span class="text-[10px] font-sans">{{ __('dashboard.unit_label') }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-else class="p-6 rounded-2xl bg-white/60 dark:bg-slate-950/40 border border-slate-200/80 dark:border-white/10 text-center space-y-3">
                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">{{ __('dashboard.no_transactions_title') }}</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            {{ __('dashboard.no_transactions_desc') }}
                        </p>
                        <Link
                            :href="route('profile.edit', { tab: 'wallet' })"
                            class="glass-btn-primary !w-auto !px-5 !py-2 !text-xs inline-flex mx-auto"
                        >{{ __('dashboard.recharge_account') }}</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unified Number Allocation Modal -->
        <AllocateNumberModal
            :show="isAllocateModalOpen"
            :default-prefix="props.default_prefix"
            :active-prefixes="props.active_prefixes"
            :remaining-quota="props.quota.remaining"
            :max-quota="props.quota.max"
            @close="isAllocateModalOpen = false"
            @success="showToast"
        />
    </UserLayout>
</template>
