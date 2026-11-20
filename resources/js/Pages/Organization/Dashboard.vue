<script setup lang="ts">
import OrgLayout from '@/Layouts/OrgLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { __ } from '@/Composables/useTrans';

interface DashboardStats {
    total_lines: number;
    assigned_lines: number;
    free_lines: number;
    total_members: number;
    balance: string;
    currency: string;
}

interface TransactionItem {
    id: number;
    type: string;
    amount: number;
    is_credit: boolean;
    description: string;
    user_name: string;
    created_at: string;
}

interface MemberPreview {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
    assigned_line: string | null;
}

const props = defineProps<{
    organization: {
        id: number;
        name: string;
        identifier: string;
        status: string;
    };
    stats: DashboardStats;
    recent_transactions: TransactionItem[];
    members_preview: MemberPreview[];
}>();
</script>

<template>
    <OrgLayout>
        <Head :title="__('organization.nav.dashboard')" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header Banner -->
            <div class="p-6 rounded-3xl bg-gradient-to-r from-purple-900/40 via-indigo-900/20 to-purple-900/10 border border-purple-500/20 backdrop-blur-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full bg-purple-500/20 text-purple-400 border border-purple-500/30 text-xs font-bold font-mono">
                            {{ organization.identifier }}
                        </span>
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span class="text-xs text-slate-400 font-bold uppercase">{{ organization.status }}</span>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                        {{ organization.name }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ __('organization.dashboard.subtitle') }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        :href="route('org.wallet.index')"
                        class="px-4 py-2.5 rounded-2xl bg-purple-600 hover:bg-purple-500 text-white font-extrabold text-xs shadow-lg shadow-purple-600/30 transition-all flex items-center gap-2"
                    >
                        <span>💳</span>
                        <span>{{ __('organization.dashboard.charge_org_wallet') }}</span>
                    </Link>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Total Lines -->
                <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
                    <div class="flex items-center justify-between text-slate-500 dark:text-slate-400">
                        <span class="text-xs font-bold">{{ __('organization.dashboard.total_lines') }}</span>
                        <span class="w-8 h-8 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm font-bold">
                            📞
                        </span>
                    </div>
                    <div class="text-2xl font-black text-slate-900 dark:text-white font-mono">
                        {{ stats.total_lines }}
                    </div>
                    <div class="flex items-center gap-2 text-[11px] text-slate-500">
                        <span class="text-emerald-500 font-bold">{{ stats.assigned_lines }} {{ __('organization.lines.filter_assigned') }}</span>
                        <span>•</span>
                        <span class="text-purple-500 font-bold">{{ stats.free_lines }} {{ __('organization.lines.filter_unassigned') }}</span>
                    </div>
                </div>

                <!-- Team Members -->
                <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
                    <div class="flex items-center justify-between text-slate-500 dark:text-slate-400">
                        <span class="text-xs font-bold">{{ __('organization.dashboard.total_team_members') }}</span>
                        <span class="w-8 h-8 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm font-bold">
                            👥
                        </span>
                    </div>
                    <div class="text-2xl font-black text-slate-900 dark:text-white font-mono">
                        {{ stats.total_members }}
                    </div>
                    <p class="text-[11px] text-slate-500">
                        {{ __('organization.team.assign_rule_notice') }}
                    </p>
                </div>

                <!-- Org Balance -->
                <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
                    <div class="flex items-center justify-between text-slate-500 dark:text-slate-400">
                        <span class="text-xs font-bold">{{ __('organization.dashboard.org_balance') }}</span>
                        <span class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm font-bold">
                            💰
                        </span>
                    </div>
                    <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-mono" dir="ltr">
                        {{ stats.balance }} <span class="text-xs font-bold">{{ stats.currency }}</span>
                    </div>
                    <p class="text-[11px] text-slate-500">
                        {{ __('organization.bulk_sms.cost_source_notice') }}
                    </p>
                </div>

                <!-- Free Available Lines -->
                <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-2">
                    <div class="flex items-center justify-between text-slate-500 dark:text-slate-400">
                        <span class="text-xs font-bold">{{ __('organization.dashboard.free_lines') }}</span>
                        <span class="w-8 h-8 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm font-bold">
                            ⚡
                        </span>
                    </div>
                    <div class="text-2xl font-black text-slate-900 dark:text-white font-mono">
                        {{ stats.free_lines }}
                    </div>
                    <Link :href="route('org.lines.index')" class="text-[11px] text-purple-600 dark:text-purple-400 font-bold hover:underline inline-block">
                        {{ __('organization.lines.buy_new_org_line') }} →
                    </Link>
                </div>
            </div>

            <!-- Quick Action Shortcuts -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <h3 class="text-xs font-black uppercase text-slate-400 tracking-wider">
                    {{ __('organization.dashboard.quick_actions') }}
                </h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <Link
                        :href="route('org.team.index')"
                        class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-purple-50 dark:hover:bg-purple-950/30 border border-slate-200/60 dark:border-slate-800 text-center transition-all group"
                    >
                        <div class="text-2xl mb-1 group-hover:scale-110 transition-transform">👥</div>
                        <span class="text-xs font-extrabold text-slate-700 dark:text-slate-300 group-hover:text-purple-600 dark:group-hover:text-purple-400">
                            {{ __('organization.dashboard.quick_team') }}
                        </span>
                    </Link>

                    <Link
                        :href="route('org.bulk_sms.index')"
                        class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-purple-50 dark:hover:bg-purple-950/30 border border-slate-200/60 dark:border-slate-800 text-center transition-all group"
                    >
                        <div class="text-2xl mb-1 group-hover:scale-110 transition-transform">📢</div>
                        <span class="text-xs font-extrabold text-slate-700 dark:text-slate-300 group-hover:text-purple-600 dark:group-hover:text-purple-400">
                            {{ __('organization.dashboard.quick_bulk_sms') }}
                        </span>
                    </Link>

                    <Link
                        :href="route('org.requests.index')"
                        class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-purple-50 dark:hover:bg-purple-950/30 border border-slate-200/60 dark:border-slate-800 text-center transition-all group"
                    >
                        <div class="text-2xl mb-1 group-hover:scale-110 transition-transform">⭐</div>
                        <span class="text-xs font-extrabold text-slate-700 dark:text-slate-300 group-hover:text-purple-600 dark:group-hover:text-purple-400">
                            {{ __('organization.dashboard.quick_requests') }}
                        </span>
                    </Link>

                    <Link
                        :href="route('org.wallet.index')"
                        class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-purple-50 dark:hover:bg-purple-950/30 border border-slate-200/60 dark:border-slate-800 text-center transition-all group"
                    >
                        <div class="text-2xl mb-1 group-hover:scale-110 transition-transform">💳</div>
                        <span class="text-xs font-extrabold text-slate-700 dark:text-slate-300 group-hover:text-purple-600 dark:group-hover:text-purple-400">
                            {{ __('organization.dashboard.quick_wallet') }}
                        </span>
                    </Link>
                </div>
            </div>

            <!-- Two-Column Grid: Team Preview & Recent Ledger -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Team Members Preview -->
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white">
                            {{ __('organization.team.title') }}
                        </h3>
                        <Link :href="route('org.team.index')" class="text-xs font-bold text-purple-600 dark:text-purple-400 hover:underline">
                            {{ __('organization.dashboard.view_all') }}
                        </Link>
                    </div>

                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        <div
                            v-for="member in members_preview"
                            :key="member.id"
                            class="py-3 flex items-center justify-between gap-3"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ member.name ? member.name.charAt(0) : 'U' }}
                                </div>
                                <div class="truncate">
                                    <p class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate">{{ member.name }}</p>
                                    <p class="text-[11px] text-slate-400 truncate">{{ member.email }}</p>
                                </div>
                            </div>

                            <div class="text-left shrink-0">
                                <span v-if="member.assigned_line" class="inline-block px-2 py-0.5 rounded-lg bg-emerald-500/10 text-emerald-500 font-mono text-xs font-bold" dir="ltr">
                                    {{ member.assigned_line }}
                                </span>
                                <span v-else class="text-[11px] text-slate-400 italic">
                                    {{ __('organization.team.no_line_assigned') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions Preview -->
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white">
                            {{ __('organization.dashboard.recent_activity') }}
                        </h3>
                        <Link :href="route('org.wallet.index')" class="text-xs font-bold text-purple-600 dark:text-purple-400 hover:underline">
                            {{ __('organization.dashboard.view_all') }}
                        </Link>
                    </div>

                    <div v-if="recent_transactions.length === 0" class="py-8 text-center text-xs text-slate-400">
                        {{ __('organization.wallet.no_transactions') }}
                    </div>

                    <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                        <div
                            v-for="tx in recent_transactions"
                            :key="tx.id"
                            class="py-3 flex items-center justify-between gap-3 text-xs"
                        >
                            <div class="min-w-0">
                                <p class="font-bold text-slate-800 dark:text-slate-200 truncate">{{ tx.description }}</p>
                                <p class="text-[10px] text-slate-400">{{ tx.user_name }} • {{ tx.created_at }}</p>
                            </div>

                            <div class="font-mono font-bold shrink-0" :class="tx.is_credit ? 'text-emerald-500' : 'text-rose-500'" dir="ltr">
                                {{ tx.is_credit ? '+' : '-' }}{{ tx.amount.toLocaleString() }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </OrgLayout>
</template>
