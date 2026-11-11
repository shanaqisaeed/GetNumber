<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { __ } from '@/Composables/useTrans';

interface TelemetryMetrics {
    server: {
        php_version: string;
        os: string;
        memory_usage_mb: number;
        memory_peak_mb: number;
        timestamp: string;
    };
    health: {
        status: string;
        checks: {
            database: { status: string; latency_ms?: number };
            cache: { status: string; latency_ms?: number };
            storage: { status: string; writable?: boolean };
            queue: { status: string; pending_jobs?: number; failed_jobs?: number };
            websocket: { status: string; driver?: string; configured_port?: number };
        };
    };
    queue: {
        pending_jobs: number;
        failed_jobs: number;
        status: string;
    };
    messages: {
        today_count: number;
        total_count: number;
    };
    errors: {
        unresolved: number;
        critical: number;
        today: number;
    };
    security: {
        unresolved_incidents: number;
    };
}

interface ErrorLogItem {
    id: number;
    source: string;
    level: string;
    message: string;
    file: string | null;
    line: number | null;
    stack_trace: string | null;
    url: string | null;
    occurrences_count: number;
    last_seen_at: string;
    resolved_at: string | null;
}

const props = defineProps<{
    metrics: TelemetryMetrics;
    recentErrors: ErrorLogItem[];
}>();

const liveMetrics = ref<TelemetryMetrics>(props.metrics);
const errorList = ref<ErrorLogItem[]>([...props.recentErrors]);
const isAutoRefresh = ref(true);
let refreshTimer: ReturnType<typeof setInterval> | null = null;

// Stack trace modal
const selectedTrace = ref<string | null>(null);

const fetchMetrics = async () => {
    try {
        const res = await fetch(route('admin.monitoring.metrics'), {
            headers: { 'Accept': 'application/json' },
        });
        if (res.ok) {
            const data = await res.json();
            if (data.metrics) {
                liveMetrics.value = data.metrics;
            }
        }
    } catch {
        // Silently tolerate
    }
};

const resolveErrorItem = async (id: number) => {
    try {
        const res = await fetch(route('admin.monitoring.errors.resolve', id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });
        if (res.ok) {
            const target = errorList.value.find(e => e.id === id);
            if (target) {
                target.resolved_at = new Date().toISOString();
            }
            if (liveMetrics.value.errors.unresolved > 0) {
                liveMetrics.value.errors.unresolved -= 1;
            }
        }
    } catch {
        // Silently tolerate
    }
};

onMounted(() => {
    refreshTimer = setInterval(() => {
        if (isAutoRefresh.value) {
            fetchMetrics();
        }
    }, 5000);
});

onUnmounted(() => {
    if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<template>
    <AdminLayout>
        <Head :title="__('admin.monitoring.page_title')" />

        <div class="space-y-6">
            <!-- Header & Auto Refresh Toggle -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <span>{{ __('admin.monitoring.heading') }}</span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('admin.monitoring.subheading') }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input
                            v-model="isAutoRefresh"
                            type="checkbox"
                            class="rounded border-slate-300 text-indigo-600 focus:ring-0"
                        />
                        <span>{{ __('admin.monitoring.live_sync') }}</span>
                    </label>

                    <button
                        type="button"
                        @click="fetchMetrics"
                        class="glass-pill !py-1.5 !px-3 text-xs flex items-center gap-1.5"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>{{ __('admin.monitoring.refresh') }}</span>
                    </button>
                </div>
            </div>

            <!-- Health Status Banner -->
            <div
                class="p-4 rounded-3xl border flex flex-wrap items-center justify-between gap-4"
                :class="liveMetrics.health.status === 'READY'
                    ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-950 dark:text-emerald-200'
                    : 'bg-rose-500/10 border-rose-500/25 text-rose-950 dark:text-rose-200'"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-2xl flex items-center justify-center font-black text-sm"
                        :class="liveMetrics.health.status === 'READY' ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'"
                    >
                        {{ liveMetrics.health.status === 'READY' ? 'UP' : 'ERR' }}
                    </div>
                    <div>
                        <span class="text-xs font-black block">
                            {{ __('admin.monitoring.system_status_label', { status: liveMetrics.health.status === 'READY' ? __('admin.monitoring.system_ready') : __('admin.monitoring.system_warning') }) }}
                        </span>
                        <span class="text-[10px] opacity-75 font-mono">
                            {{ __('admin.monitoring.last_check_label', { time: new Date(liveMetrics.server.timestamp).toLocaleTimeString() }) }}
                        </span>
                    </div>
                </div>

                <!-- Probes Chips -->
                <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
                    <span class="px-2.5 py-1 rounded-xl bg-white/70 dark:bg-black/30 border flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full" :class="liveMetrics.health.checks.database?.status === 'UP' ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                        <span>DB Latency: {{ liveMetrics.health.checks.database?.latency_ms ?? 0 }}ms</span>
                    </span>
                    <span class="px-2.5 py-1 rounded-xl bg-white/70 dark:bg-black/30 border flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full" :class="liveMetrics.health.checks.cache?.status === 'UP' ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                        <span>Cache: {{ liveMetrics.health.checks.cache?.latency_ms ?? 0 }}ms</span>
                    </span>
                    <span class="px-2.5 py-1 rounded-xl bg-white/70 dark:bg-black/30 border flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full" :class="liveMetrics.health.checks.storage?.status === 'UP' ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                        <span>Disk: OK</span>
                    </span>
                    <span class="px-2.5 py-1 rounded-xl bg-white/70 dark:bg-black/30 border flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full" :class="liveMetrics.queue.status === 'HEALTHY' ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                        <span>Queue: {{ liveMetrics.queue.status }}</span>
                    </span>
                </div>
            </div>

            <!-- Key Metric Cards Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <!-- RAM Usage -->
                <div class="glass-card !p-4">
                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                        <span>{{ __('admin.monitoring.ram_usage') }}</span>
                        <span class="font-mono text-[10px]">PHP {{ liveMetrics.server.php_version }}</span>
                    </div>
                    <div class="text-xl font-black text-slate-900 dark:text-white font-mono">
                        {{ liveMetrics.server.memory_usage_mb }} <span class="text-xs text-slate-400">MB</span>
                    </div>
                    <span class="text-[10px] text-slate-400 font-mono mt-1 block">
                        {{ __('admin.monitoring.peak_usage', { mb: liveMetrics.server.memory_peak_mb }) }}
                    </span>
                </div>

                <!-- Queue Status -->
                <div class="glass-card !p-4">
                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                        <span>{{ __('admin.monitoring.queue_label') }}</span>
                        <span class="font-mono text-[10px]" :class="liveMetrics.queue.failed_jobs > 0 ? 'text-rose-500 font-bold' : 'text-slate-400'">
                            {{ __('admin.monitoring.failed_jobs', { count: liveMetrics.queue.failed_jobs }) }}
                        </span>
                    </div>
                    <div class="text-xl font-black text-indigo-600 dark:text-indigo-400 font-mono">
                        {{ __('admin.monitoring.in_queue', { count: liveMetrics.queue.pending_jobs }) }}
                    </div>
                    <span class="text-[10px] text-slate-400 mt-1 block">
                        {{ __('admin.monitoring.bg_workers') }}
                    </span>
                </div>

                <!-- Messages Velocity -->
                <div class="glass-card !p-4">
                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                        <span>{{ __('admin.monitoring.today_messages') }}</span>
                        <span class="font-mono text-[10px]">{{ __('admin.monitoring.total_count', { count: liveMetrics.messages.total_count }) }}</span>
                    </div>
                    <div class="text-xl font-black text-slate-900 dark:text-white font-mono">
                        {{ liveMetrics.messages.today_count }}
                    </div>
                    <span class="text-[10px] text-emerald-500 font-bold mt-1 block">
                        {{ __('admin.monitoring.normal_traffic_rate') }}
                    </span>
                </div>

                <!-- Unresolved Errors -->
                <div class="glass-card !p-4">
                    <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                        <span>{{ __('admin.monitoring.unresolved_errors') }}</span>
                        <span class="font-mono text-[10px] text-rose-500 font-bold">
                            {{ __('admin.monitoring.critical_count', { count: liveMetrics.errors.critical }) }}
                        </span>
                    </div>
                    <div class="text-xl font-black text-slate-900 dark:text-white font-mono" :class="liveMetrics.errors.unresolved > 0 ? 'text-rose-600' : ''">
                        {{ liveMetrics.errors.unresolved }}
                    </div>
                    <span class="text-[10px] text-slate-400 font-mono mt-1 block">
                        {{ __('admin.monitoring.today_errors', { count: liveMetrics.errors.today }) }}
                    </span>
                </div>
            </div>

            <!-- Error Tracker Table -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ __('admin.monitoring.error_tracker_title') }}</span>
                    </h3>
                </div>

                <div class="glass-card !p-0 overflow-hidden">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-100/80 dark:bg-slate-800/80 text-slate-500 border-b border-slate-200 dark:border-white/10">
                            <tr>
                                <th class="p-3 font-bold">{{ __('admin.monitoring.th_level') }}</th>
                                <th class="p-3 font-bold">{{ __('admin.monitoring.th_source') }}</th>
                                <th class="p-3 font-bold">{{ __('admin.monitoring.th_message') }}</th>
                                <th class="p-3 font-bold">{{ __('admin.monitoring.th_file') }}</th>
                                <th class="p-3 font-bold">{{ __('admin.monitoring.th_occurrences') }}</th>
                                <th class="p-3 font-bold">{{ __('admin.monitoring.th_last_seen') }}</th>
                                <th class="p-3 font-bold text-left">{{ __('admin.monitoring.th_action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            <tr v-for="err in errorList" :key="err.id" class="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors">
                                <td class="p-3">
                                    <span
                                        class="px-2 py-0.5 rounded-full border text-[10px] font-bold font-mono"
                                        :class="err.level === 'critical' ? 'bg-rose-500/20 text-rose-500 border-rose-500/30' : 'bg-amber-500/20 text-amber-500 border-amber-500/30'"
                                    >
                                        {{ err.level }}
                                    </span>
                                </td>
                                <td class="p-3 font-mono text-[11px] text-slate-500">
                                    {{ err.source }}
                                </td>
                                <td class="p-3 font-medium text-slate-900 dark:text-white max-w-xs truncate" :title="err.message">
                                    {{ err.message }}
                                </td>
                                <td class="p-3 font-mono text-[10px] text-slate-400 max-w-[180px] truncate" dir="ltr" :title="err.file ? `${err.file}:${err.line}` : ''">
                                    {{ err.file ? `${err.file}:${err.line}` : '-' }}
                                </td>
                                <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                    ×{{ err.occurrences_count }}
                                </td>
                                <td class="p-3 font-mono text-[10px] text-slate-500">
                                    {{ new Date(err.last_seen_at).toLocaleTimeString('fa-IR') }}
                                </td>
                                <td class="p-3 text-left space-x-2 rtl:space-x-reverse">
                                    <button
                                        v-if="err.stack_trace"
                                        type="button"
                                        @click="selectedTrace = err.stack_trace"
                                        class="px-2 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-slate-700 dark:text-slate-300 text-[10px] font-bold"
                                    >
                                        Stack Trace
                                    </button>
                                    <button
                                        v-if="!err.resolved_at"
                                        type="button"
                                        @click="resolveErrorItem(err.id)"
                                        class="px-2 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-[10px] font-bold"
                                    >{{ __('admin.monitoring.resolve_action') }}</button>
                                    <span v-else class="text-[10px] text-emerald-500 font-bold">{{ __('admin.monitoring.resolved_badge') }}</span>
                                </td>
                            </tr>

                            <tr v-if="errorList.length === 0">
                                <td colspan="7" class="p-8 text-center text-slate-400 text-xs">
                                    {{ __('admin.monitoring.no_errors_found') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Stack Trace Modal -->
            <div
                v-if="selectedTrace"
                class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                @click.self="selectedTrace = null"
            >
                <div class="glass-card !p-5 w-full max-w-2xl shadow-2xl relative space-y-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-white/10">
                        <h4 class="text-xs font-black">{{ __('admin.monitoring.stack_trace_title') }}</h4>
                        <button type="button" @click="selectedTrace = null" class="text-slate-400 hover:text-white">✕</button>
                    </div>

                    <pre dir="ltr" class="p-3 rounded-2xl bg-slate-950 text-slate-200 font-mono text-[11px] overflow-x-auto max-h-96 leading-relaxed select-all">{{ selectedTrace }}</pre>

                    <div class="flex justify-end pt-2">
                        <button type="button" @click="selectedTrace = null" class="glass-pill !py-1.5 !px-4 text-xs">{{ __('admin.monitoring.close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

