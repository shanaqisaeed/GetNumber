<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

interface UserActor {
    id: number;
    name: string;
    username: string;
    is_admin: boolean;
}

interface AuditLogItem {
    id: number;
    action: string;
    action_label: string;
    action_badge: string;
    user: UserActor | null;
    entity_type: string | null;
    entity_id: number | null;
    payload: Record<string, any> | null;
    ip_address: string | null;
    created_at: string;
    created_at_human: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    logs: {
        data: AuditLogItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
        action?: string;
        user_id?: string;
        date_from?: string;
        date_to?: string;
    };
    stats: {
        total: number;
        today: number;
        number_events: number;
        security_events: number;
    };
    actionOptions: Array<{ value: string; label: string }>;
}>();

// Filter states
const search = ref(props.filters.search || '');
const action = ref(props.filters.action || '');
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');

// Inspection Modal state
const selectedLog = ref<AuditLogItem | null>(null);
const isModalOpen = ref(false);
const copySuccess = ref(false);

function applyFilters() {
    router.get(
        route('admin.audit-logs.index'),
        {
            search: search.value || undefined,
            action: action.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    action.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
}

function openInspectModal(log: AuditLogItem) {
    selectedLog.value = log;
    isModalOpen.value = true;
    copySuccess.value = false;
}

function closeInspectModal() {
    isModalOpen.value = false;
    selectedLog.value = null;
    copySuccess.value = false;
}

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        closeInspectModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleKeydown));

async function copyPayload() {
    if (!selectedLog.value?.payload) return;
    try {
        await navigator.clipboard.writeText(JSON.stringify(selectedLog.value.payload, null, 2));
        copySuccess.value = true;
        setTimeout(() => {
            copySuccess.value = false;
        }, 2000);
    } catch (e) {
        // clipboard fallback
    }
}
</script>

<template>
    <Head title="گزارش رویدادها و ممیزی سیستم" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-base font-bold text-slate-900 dark:text-white">گزارش رویدادها، امنیت و ممیزی سیستم</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">پایش کلیه رخدادهای حساس، تغییرات خطوط، صدور توکن‌ها و دسترسی‌های کاربران</p>
                </div>
            </div>
        </template>

        <!-- KPI Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">کل رخدادهای ثبت‌شده</p>
                    <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stats.total }}</p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">رخدادهای امروز</p>
                    <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.today }}</p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">عملیات خطوط سازمانی</p>
                    <p class="text-2xl font-black text-rose-600 dark:text-rose-400 mt-1">{{ stats.number_events }}</p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">رویدادهای امنیتی و کاربری</p>
                    <p class="text-2xl font-black text-amber-600 dark:text-amber-400 mt-1">{{ stats.security_events }}</p>
                </div>
                <div class="w-11 h-11 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Filter Controls Card -->
        <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                <!-- Search input -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">جستجو در رخدادها</label>
                    <div class="relative">
                        <input
                            v-model="search"
                            @keydown.enter="applyFilters"
                            type="text"
                            placeholder="شناسه، آی‌پی، نام یا نام کاربری..."
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white placeholder-slate-400 focus:border-rose-500 focus:ring-rose-500 pl-8 pr-3 py-2"
                        />
                        <button
                            type="button"
                            @click="applyFilters"
                            class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Action filter -->
                <div>
                    <label class="form-label mb-1.5">نوع رخداد</label>
                    <select
                        v-model="action"
                        @change="applyFilters"
                        class="form-select w-full text-xs rounded-xl py-2"
                    >
                        <option v-for="opt in actionOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                </div>

                <!-- Date from -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">از تاریخ</label>
                    <input
                        v-model="dateFrom"
                        @change="applyFilters"
                        type="date"
                        class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-rose-500 py-2"
                    />
                </div>

                <!-- Date to & Clear Button -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">تا تاریخ</label>
                    <div class="flex gap-2">
                        <input
                            v-model="dateTo"
                            @change="applyFilters"
                            type="date"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-rose-500 py-2"
                        />
                        <button
                            type="button"
                            @click="clearFilters"
                            class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors shrink-0"
                            title="حذف فیلترها"
                        >
                            پاکسازی
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Audit Logs Table -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden mb-6">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">فهرست رخدادهای سیستمی</h2>
                    <p class="text-xs text-slate-400 mt-0.5">نمایش آخرین رخدادها با جزئیات کامل لاگ و امضای امنیتی</p>
                </div>
                <span class="text-xs font-bold text-slate-400">مجموع: {{ logs.total }} رخداد</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                            <th class="py-3 px-4 font-bold">شناسه</th>
                            <th class="py-3 px-4 font-bold">عنوان / نوع رخداد</th>
                            <th class="py-3 px-4 font-bold">موجودیت هدف</th>
                            <th class="py-3 px-4 font-bold">کاربر مجری</th>
                            <th class="py-3 px-4 font-bold">آدرس IP</th>
                            <th class="py-3 px-4 font-bold">زمان وقوع</th>
                            <th class="py-3 px-4 font-bold text-center">جزئیات Payload</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors"
                        >
                            <!-- ID -->
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                #{{ log.id }}
                            </td>

                            <!-- Action -->
                            <td class="py-3.5 px-4">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-lg text-[11px] font-bold"
                                    :class="log.action_badge"
                                >
                                    {{ log.action_label }}
                                </span>
                                <span class="block text-[10px] text-slate-400 font-mono mt-0.5" dir="ltr">
                                    {{ log.action }}
                                </span>
                            </td>

                            <!-- Entity -->
                            <td class="py-3.5 px-4">
                                <div v-if="log.entity_type">
                                    <span class="font-medium text-slate-900 dark:text-white">{{ log.entity_type }}</span>
                                    <span v-if="log.entity_id" class="text-slate-400 font-mono text-[11px] mr-1" dir="ltr">
                                        (ID: {{ log.entity_id }})
                                    </span>
                                </div>
                                <span v-else class="text-slate-400 text-[11px]">-</span>
                            </td>

                            <!-- User Actor -->
                            <td class="py-3.5 px-4">
                                <div v-if="log.user" class="flex items-center gap-1.5">
                                    <span class="font-bold text-slate-900 dark:text-white">{{ log.user.name }}</span>
                                    <span class="font-mono text-[10px] text-slate-400" dir="ltr">(@{{ log.user.username }})</span>
                                    <span
                                        v-if="log.user.is_admin"
                                        class="px-1.5 py-0.2 rounded text-[9px] font-bold bg-rose-500/20 text-rose-400 border border-rose-500/30"
                                    >
                                        راهبر
                                    </span>
                                </div>
                                <span v-else class="text-slate-400 font-medium">سامانه خودکار</span>
                            </td>

                            <!-- IP Address -->
                            <td class="py-3.5 px-4 font-mono text-slate-500 dark:text-slate-400 text-[11px]" dir="ltr">
                                {{ log.ip_address || '—' }}
                            </td>

                            <!-- Timestamp -->
                            <td class="py-3.5 px-4">
                                <span class="font-mono text-[11px] text-slate-900 dark:text-white block" dir="ltr">
                                    {{ log.created_at }}
                                </span>
                                <span class="text-[10px] text-slate-400">
                                    {{ log.created_at_human }}
                                </span>
                            </td>

                            <!-- Action Inspect Button -->
                            <td class="py-3.5 px-4 text-center">
                                <button
                                    v-if="log.payload && Object.keys(log.payload).length > 0"
                                    type="button"
                                    @click="openInspectModal(log)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 font-bold text-[11px] transition-colors"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    <span>مشاهده Payload</span>
                                </button>
                                <span v-else class="text-slate-400 text-[11px]">بدون متادیتا</span>
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr v-if="logs.data.length === 0">
                            <td colspan="7" class="py-12 text-center text-slate-400">
                                <svg class="w-12 h-12 mx-auto text-slate-300 dark:text-slate-700 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p class="text-sm font-bold text-slate-700 dark:text-slate-300">هیچ رویدادی با این شرایط یافت نشد.</p>
                                <p class="text-xs text-slate-400 mt-1">می‌توانید فیلترهای جستجو را پاکسازی کنید.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div
                v-if="logs.links && logs.links.length > 3"
                class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between"
            >
                <div class="text-xs text-slate-400">
                    نمایش صفحه {{ logs.current_page }} از {{ logs.last_page }}
                </div>
                <div class="flex items-center gap-1">
                    <Link
                        v-for="(link, idx) in logs.links"
                        :key="idx"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="[
                            link.active
                                ? 'bg-rose-600 text-white shadow-sm'
                                : link.url
                                ? 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'
                                : 'text-slate-300 dark:text-slate-600 cursor-not-allowed'
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <!-- JSON Payload Inspector Modal -->
        <div
            v-if="isModalOpen && selectedLog"
            @click.self="closeInspectModal"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm animate-fade-in"
        >
            <div
                class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 max-w-2xl w-full max-h-[calc(100dvh-2.5rem)] flex flex-col shadow-2xl overflow-hidden"
                @click.stop
            >
                <!-- Modal Header -->
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">
                                بررسی متادیتا و Payload رویداد #{{ selectedLog.id }}
                            </h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ selectedLog.action_label }} ({{ selectedLog.action }})
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="closeInspectModal"
                        class="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Event Quick Info Grid -->
                <div class="px-5 py-3 bg-slate-50/70 dark:bg-slate-800/30 border-b border-slate-100 dark:border-slate-800 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div>
                        <span class="text-slate-400 block text-[10px]">کاربر مجری</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">
                            {{ selectedLog.user ? selectedLog.user.username : 'سیستم' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px]">آدرس IP</span>
                        <span class="font-mono text-slate-800 dark:text-slate-200" dir="ltr">
                            {{ selectedLog.ip_address || '—' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px]">موجودیت</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">
                            {{ selectedLog.entity_type || '—' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[10px]">زمان ثبت</span>
                        <span class="font-mono text-slate-800 dark:text-slate-200 text-[11px]" dir="ltr">
                            {{ selectedLog.created_at }}
                        </span>
                    </div>
                </div>

                <!-- JSON Content Viewer -->
                <div class="flex-1 p-5 overflow-y-auto">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300">محتوای ساختاریافته (JSON Payload)</span>
                        <button
                            type="button"
                            @click="copyPayload"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold transition-colors"
                            :class="copySuccess ? 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <span>{{ copySuccess ? 'کپی شد!' : 'کپی JSON' }}</span>
                        </button>
                    </div>

                    <pre class="p-4 rounded-2xl bg-slate-900 text-emerald-400 font-mono text-xs overflow-x-auto leading-relaxed border border-slate-800" dir="ltr"><code>{{ JSON.stringify(selectedLog.payload, null, 2) }}</code></pre>
                </div>

                <!-- Modal Footer -->
                <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                    <button
                        type="button"
                        @click="closeInspectModal"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-colors"
                    >
                        بستن
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
