<script setup lang="ts">
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface IncidentUser {
    id: number;
    name: string;
    username: string;
    trust_tier: string;
    risk_score: number;
}

interface IncidentItem {
    id: number;
    user_id: number | null;
    user?: IncidentUser | null;
    phone_number_id: number | null;
    type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    details: Record<string, any> | null;
    ip_address: string | null;
    user_agent: string | null;
    action_taken: string;
    resolved_at: string | null;
    resolver?: { name: string } | null;
    created_at: string;
}

const props = defineProps<{
    incidents: {
        data: IncidentItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    stats: {
        total: number;
        unresolved: number;
        critical: number;
        today: number;
    };
    filters: {
        search?: string;
        severity?: string;
        type?: string;
        resolved?: string;
    };
}>();

const search = ref(props.filters.search || '');
const severity = ref(props.filters.severity || '');
const type = ref(props.filters.type || '');
const resolved = ref(props.filters.resolved || '');

// Tier update modal state
const isTierModalOpen = ref(false);
const targetUser = ref<IncidentUser | null>(null);
const selectedTier = ref('standard');
const resetRisk = ref(true);
const isUpdatingTier = ref(false);

const applyFilters = () => {
    router.get(
        route('admin.security.incidents.index'),
        {
            search: search.value || undefined,
            severity: severity.value || undefined,
            type: type.value || undefined,
            resolved: resolved.value || undefined,
        },
        { preserveState: true }
    );
};

const resetFilters = () => {
    search.value = '';
    severity.value = '';
    type.value = '';
    resolved.value = '';
    applyFilters();
};

const resolveIncident = (id: number) => {
    if (!confirm(__('admin.security.confirm_resolve'))) {
        router.post(route('admin.security.incidents.resolve', id), {}, { preserveScroll: true });
    }
};

const openTierModal = (user: IncidentUser) => {
    targetUser.value = user;
    selectedTier.value = user.trust_tier || 'standard';
    resetRisk.value = true;
    isTierModalOpen.value = true;
};

const submitTierUpdate = () => {
    if (!targetUser.value) return;
    isUpdatingTier.value = true;

    router.patch(
        route('admin.security.users.tier', targetUser.value.id),
        {
            trust_tier: selectedTier.value,
            reset_risk_score: resetRisk.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                isUpdatingTier.value = false;
                isTierModalOpen.value = false;
            },
        }
    );
};

const severityBadgeClass = (sev: string) => {
    switch (sev) {
        case 'critical':
            return 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border-rose-500/30';
        case 'high':
            return 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'medium':
            return 'bg-sky-500/20 text-sky-600 dark:text-sky-400 border-sky-500/30';
        default:
            return 'bg-slate-500/20 text-slate-600 dark:text-slate-400 border-slate-500/30';
    }
};

const typeLabel = (t: string) => {
    switch (t) {
        case 'phishing_attempt':
            return __('admin.security.incident_phishing');
        case 'rate_limit_tripped':
            return __('admin.security.incident_rate_limit');
        case 'suspicious_behavior_auto_restricted':
            return __('admin.security.incident_auto_restrict');
        case 'suspicious_activity_warning':
            return __('admin.security.incident_suspicious_warning');
        case 'failed_2fa_challenge':
            return __('admin.security.incident_failed_2fa');
        default:
            return t;
    }
};
</script>

<template>
    <AdminLayout>
        <Head :title="__('admin.security.page_title')" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-6 h-6 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span>{{ __('admin.security.heading') }}</span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('admin.security.subheading') }}
                    </p>
                </div>
            </div>

            <!-- Stats Overview Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block">{{ __('admin.security.total_incidents') }}</span>
                        <span class="text-2xl font-black text-slate-900 dark:text-white font-mono mt-1 block">{{ stats.total }}</span>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-amber-500 block">{{ __('admin.security.pending_review') }}</span>
                        <span class="text-2xl font-black text-amber-500 font-mono mt-1 block">{{ stats.unresolved }}</span>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-500 flex items-center justify-center font-bold">
                        ⏳
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-rose-500 block">{{ __('admin.security.critical_risk') }}</span>
                        <span class="text-2xl font-black text-rose-500 font-mono mt-1 block">{{ stats.critical }}</span>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-rose-500/15 text-rose-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-sky-500 block">{{ __('admin.security.today_incidents') }}</span>
                        <span class="text-2xl font-black text-sky-500 font-mono mt-1 block">{{ stats.today }}</span>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-sky-500/15 text-sky-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="p-4 sm:p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5 items-end">
                    <!-- Search Input -->
                    <div class="sm:col-span-2 lg:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.security.search_label') }}</label>
                        <div class="relative">
                            <input
                                v-model="search"
                                @keydown.enter="applyFilters"
                                type="text"
                                :placeholder="__('admin.security.search_placeholder')"
                                class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white placeholder-slate-400 focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 pr-9 pl-3 transition-colors"
                            />
                            <div class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Severity Filter -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.security.severity_label') }}</label>
                        <select
                            v-model="severity"
                            @change="applyFilters"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 px-3 transition-colors"
                        >
                            <option value="">{{ __('admin.security.all_severities') }}</option>
                            <option value="critical">{{ __('admin.security.severity_critical') }}</option>
                            <option value="high">{{ __('admin.security.severity_high') }}</option>
                            <option value="medium">{{ __('admin.security.severity_medium') }}</option>
                            <option value="low">{{ __('admin.security.severity_low') }}</option>
                        </select>
                    </div>

                    <!-- Type Filter -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.security.type_label') }}</label>
                        <select
                            v-model="type"
                            @change="applyFilters"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 px-3 transition-colors"
                        >
                            <option value="">{{ __('admin.security.all_types') }}</option>
                            <option value="phishing_attempt">{{ __('admin.security.type_phishing') }}</option>
                            <option value="rate_limit_tripped">{{ __('admin.security.type_rate_limit') }}</option>
                            <option value="suspicious_behavior_auto_restricted">{{ __('admin.security.type_suspicious') }}</option>
                            <option value="suspicious_activity_warning">{{ __('admin.security.type_suspicious_warning') }}</option>
                            <option value="failed_2fa_challenge">{{ __('admin.security.type_failed_2fa') }}</option>
                        </select>
                    </div>

                    <!-- Resolved Status Filter -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.security.status_label') }}</label>
                        <select
                            v-model="resolved"
                            @change="applyFilters"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 px-3 transition-colors"
                        >
                            <option value="">{{ __('admin.security.all_statuses') }}</option>
                            <option value="0">{{ __('admin.security.status_pending') }}</option>
                            <option value="1">{{ __('admin.security.status_resolved') }}</option>
                        </select>
                    </div>

                    <!-- Action Buttons -->
                    <div class="lg:col-span-2 flex items-center gap-2">
                        <button
                            type="button"
                            @click="applyFilters"
                            class="flex-1 py-2.5 px-3 rounded-xl bg-rose-600 hover:bg-rose-500 active:bg-rose-700 text-white text-xs font-bold transition-all shadow-md shadow-rose-600/20 flex items-center justify-center gap-1.5 cursor-pointer"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                            <span>{{ __('admin.security.apply_btn') }}</span>
                        </button>

                        <button
                            v-if="search || severity || type || resolved"
                            type="button"
                            @click="resetFilters"
                            class="py-2.5 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold transition-colors shrink-0"
                            :title="__('admin.security.clear_filters_title')"
                        >{{ __('admin.security.clear') }}</button>
                    </div>
                </div>
            </div>

            <!-- Incidents Table -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 border-b border-slate-200/80 dark:border-slate-800/80">
                            <tr>
                                <th class="p-3.5 font-bold">{{ __('admin.security.th_id') }}</th>
                                <th class="p-3.5 font-bold">{{ __('admin.security.th_user') }}</th>
                                <th class="p-3.5 font-bold">{{ __('admin.security.type_label') }}</th>
                                <th class="p-3.5 font-bold">{{ __('admin.security.th_severity') }}</th>
                                <th class="p-3.5 font-bold">{{ __('admin.security.th_action_taken') }}</th>
                                <th class="p-3.5 font-bold">{{ __('admin.security.th_ip') }}</th>
                                <th class="p-3.5 font-bold">{{ __('admin.security.th_status') }}</th>
                                <th class="p-3.5 font-bold text-left">{{ __('admin.security.th_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <tr v-for="inc in incidents.data" :key="inc.id" class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                <td class="p-3.5 font-mono font-bold text-slate-500 dark:text-slate-400">#{{ inc.id }}</td>
                                <td class="p-3.5">
                                    <div v-if="inc.user" class="space-y-0.5">
                                        <div class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                            <span>{{ inc.user.username }}</span>
                                            <span class="text-[9px] px-1.5 py-0.2 rounded font-mono font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                {{ inc.user.trust_tier }}
                                            </span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 font-mono">{{ __('admin.security.risk_score', { score: inc.user.risk_score }) }}</span>
                                    </div>
                                    <span v-else class="text-slate-400 font-mono">-</span>
                                </td>
                                <td class="p-3.5 font-bold text-slate-800 dark:text-slate-200">
                                    {{ typeLabel(inc.type) }}
                                </td>
                                <td class="p-3.5">
                                    <span class="px-2.5 py-0.5 rounded-full border text-[10px] font-bold" :class="severityBadgeClass(inc.severity)">
                                        {{ inc.severity }}
                                    </span>
                                </td>
                                <td class="p-3.5 font-mono text-[11px] text-indigo-600 dark:text-indigo-400">
                                    {{ inc.action_taken }}
                                </td>
                                <td class="p-3.5 font-mono text-[11px] text-slate-500" dir="ltr">
                                    {{ inc.ip_address || '-' }}
                                </td>
                                <td class="p-3.5">
                                    <span v-if="inc.resolved_at" class="text-emerald-500 font-bold text-[10px] flex items-center gap-1">{{ __('admin.security.resolved_badge') }}</span>
                                    <span v-else class="text-amber-500 font-bold text-[10px] flex items-center gap-1">
                                        ⏳ {{ __('admin.security.pending_review') }}
                                    </span>
                                </td>
                                <td class="p-3.5 text-left space-x-2 rtl:space-x-reverse">
                                    <button
                                        v-if="!inc.resolved_at"
                                        type="button"
                                        @click="resolveIncident(inc.id)"
                                        class="px-2.5 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-[11px] font-bold transition-colors cursor-pointer"
                                    >{{ __('admin.security.close_incident') }}</button>
                                    <button
                                        v-if="inc.user"
                                        type="button"
                                        @click="openTierModal(inc.user)"
                                        class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-[11px] font-bold transition-colors cursor-pointer"
                                    >{{ __('admin.security.set_trust_level') }}</button>
                                </td>
                            </tr>

                            <tr v-if="incidents.data.length === 0">
                                <td colspan="8" class="p-12 text-center text-slate-400 text-xs">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                        </svg>
                                        <span>{{ __('admin.security.no_incidents') }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div
                    v-if="incidents.links && incidents.links.length > 3"
                    class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3"
                >
                    <div class="text-xs text-slate-400">
                        {{ __('admin.security.pagination_summary', { current: incidents.current_page, last: incidents.last_page, total: incidents.total }) }}
                    </div>
                    <div class="flex items-center gap-1">
                        <Link
                            v-for="(link, idx) in incidents.links"
                            :key="idx"
                            :href="link.url || '#'"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                            :class="[
                                link.active
                                    ? 'bg-rose-600 text-white shadow-sm'
                                    : link.url
                                    ? 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'
                                    : 'text-slate-300 dark:text-slate-600 cursor-not-allowed pointer-events-none'
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>

            <!-- Tier Update Modal -->
            <div
                v-if="isTierModalOpen && targetUser"
                class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                @click.self="isTierModalOpen = false"
            >
                <div class="w-full max-w-sm shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white rounded-3xl p-6 animate-in zoom-in-95 duration-200">
                    <h3 class="text-sm font-black flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                        <span>{{ __('admin.security.modal_trust_title', { username: targetUser.username }) }}</span>
                    </h3>

                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300">{{ __('admin.security.trust_level_label') }}</label>
                        <select
                            v-model="selectedTier"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white py-2.5 px-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="new">{{ __('admin.security.trust_new') }}</option>
                            <option value="standard">{{ __('admin.security.trust_standard') }}</option>
                            <option value="trusted">{{ __('admin.security.trust_trusted') }}</option>
                            <option value="restricted">{{ __('admin.security.trust_restricted') }}</option>
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer pt-1">
                        <input v-model="resetRisk" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <span>{{ __('admin.security.reset_risk_label') }}</span>
                    </label>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <button
                            type="button"
                            @click="isTierModalOpen = false"
                            class="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >{{ __('admin.security.cancel') }}</button>
                        <button
                            type="button"
                            @click="submitTierUpdate"
                            :disabled="isUpdatingTier"
                            class="py-2 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-md shadow-indigo-600/20 transition-all cursor-pointer disabled:opacity-50"
                        >
                            <span>{{ __('admin.security.save_changes') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
