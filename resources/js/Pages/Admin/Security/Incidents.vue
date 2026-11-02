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
    if (confirm('آیا از علامت‌گذاری این رخداد امنیتی به عنوان حل‌شده اطمینان دارید؟')) {
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
            return 'تلاش برای فیشینگ / کلاهبرداری';
        case 'rate_limit_tripped':
            return 'تجاوز از سقف Rate Limit';
        case 'suspicious_behavior_auto_restricted':
            return 'محدودسازی خودکار رفتار مشکوک';
        case 'suspicious_activity_warning':
            return 'هشدار فعالیت مشکوک';
        case 'failed_2fa_challenge':
            return 'کد تایید دو مرحله‌ای نادرست';
        default:
            return t;
    }
};
</script>

<template>
    <AdminLayout>
        <Head title="دیده‌بانی امنیت و رخدادها" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-6 h-6 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span>دیده‌بانی امنیت و رخدادهای مشکوک</span>
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        نظارت بر تخلفات، حملات فیشینگ، تخطی از محدودیت‌ها و تنظیم سطوح دسترسی کاربران
                    </p>
                </div>
            </div>

            <!-- Stats Overview Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 block">کل رخدادها</span>
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
                        <span class="text-xs font-bold text-amber-500 block">در انتظار بررسی</span>
                        <span class="text-2xl font-black text-amber-500 font-mono mt-1 block">{{ stats.unresolved }}</span>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-500 flex items-center justify-center font-bold">
                        ⏳
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-rose-500 block">بحرانی / پرخطر</span>
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
                        <span class="text-xs font-bold text-sky-500 block">رخدادهای امروز</span>
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
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">جستجو در رخدادها</label>
                        <div class="relative">
                            <input
                                v-model="search"
                                @keydown.enter="applyFilters"
                                type="text"
                                placeholder="جستجو در نوع رخداد، نام کاربر، IP..."
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
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">سطح شدت</label>
                        <select
                            v-model="severity"
                            @change="applyFilters"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 px-3 transition-colors"
                        >
                            <option value="">همه شدت‌ها</option>
                            <option value="critical">بحرانی (Critical)</option>
                            <option value="high">بالا (High)</option>
                            <option value="medium">متوسط (Medium)</option>
                            <option value="low">پایین (Low)</option>
                        </select>
                    </div>

                    <!-- Type Filter -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">نوع رخداد</label>
                        <select
                            v-model="type"
                            @change="applyFilters"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 px-3 transition-colors"
                        >
                            <option value="">همه انواع رخداد</option>
                            <option value="phishing_attempt">فیشینگ و کلاهبرداری</option>
                            <option value="rate_limit_tripped">تخطی از سقف ارسال</option>
                            <option value="suspicious_behavior_auto_restricted">رفتار مشکوک</option>
                            <option value="suspicious_activity_warning">هشدار رفتار مشکوک</option>
                            <option value="failed_2fa_challenge">شکست تایید دومرحله‌ای</option>
                        </select>
                    </div>

                    <!-- Resolved Status Filter -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">وضعیت پیگیری</label>
                        <select
                            v-model="resolved"
                            @change="applyFilters"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 py-2.5 px-3 transition-colors"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option value="0">در انتظار بررسی (حل‌نشده)</option>
                            <option value="1">حل و خاتمه‌یافته</option>
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
                            <span>اعمال</span>
                        </button>

                        <button
                            v-if="search || severity || type || resolved"
                            type="button"
                            @click="resetFilters"
                            class="py-2.5 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold transition-colors shrink-0"
                            title="پاکسازی فیلترها"
                        >
                            پاکسازی
                        </button>
                    </div>
                </div>
            </div>

            <!-- Incidents Table -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 border-b border-slate-200/80 dark:border-slate-800/80">
                            <tr>
                                <th class="p-3.5 font-bold">شناسه</th>
                                <th class="p-3.5 font-bold">کاربر</th>
                                <th class="p-3.5 font-bold">نوع رخداد</th>
                                <th class="p-3.5 font-bold">شدت</th>
                                <th class="p-3.5 font-bold">اقدام انجام‌شده</th>
                                <th class="p-3.5 font-bold">آدرس IP</th>
                                <th class="p-3.5 font-bold">وضعیت</th>
                                <th class="p-3.5 font-bold text-left">عملیات</th>
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
                                        <span class="text-[10px] text-slate-400 font-mono">ریسک: {{ inc.user.risk_score }}</span>
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
                                    <span v-if="inc.resolved_at" class="text-emerald-500 font-bold text-[10px] flex items-center gap-1">
                                        ✓ حل‌شده
                                    </span>
                                    <span v-else class="text-amber-500 font-bold text-[10px] flex items-center gap-1">
                                        ⏳ در انتظار بررسی
                                    </span>
                                </td>
                                <td class="p-3.5 text-left space-x-2 rtl:space-x-reverse">
                                    <button
                                        v-if="!inc.resolved_at"
                                        type="button"
                                        @click="resolveIncident(inc.id)"
                                        class="px-2.5 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-[11px] font-bold transition-colors cursor-pointer"
                                    >
                                        بستن رخداد
                                    </button>
                                    <button
                                        v-if="inc.user"
                                        type="button"
                                        @click="openTierModal(inc.user)"
                                        class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-[11px] font-bold transition-colors cursor-pointer"
                                    >
                                        تنظیم سطح
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="incidents.data.length === 0">
                                <td colspan="8" class="p-12 text-center text-slate-400 text-xs">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                        </svg>
                                        <span>هیچ رخداد امنیتی ثبت‌شده‌ای یافت نشد.</span>
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
                        نمایش صفحه {{ incidents.current_page }} از {{ incidents.last_page }} (مجموع {{ incidents.total }} رخداد)
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
                        <span>مدیریت سطح اعتبار: {{ targetUser.username }}</span>
                    </h3>

                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300">سطح دسترسی و اعتبار:</label>
                        <select
                            v-model="selectedTier"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white py-2.5 px-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        >
                            <option value="new">کاربر جدید (New - محدودیت سخت‌گیرانه)</option>
                            <option value="standard">کاربر استاندارد (Standard - نرخ معمولی)</option>
                            <option value="trusted">کاربر تاییدشده (Trusted - سقف بالا)</option>
                            <option value="restricted">حساب محدود شده (Restricted - مسدود)</option>
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer pt-1">
                        <input v-model="resetRisk" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <span>ریست کردن امتیاز ریسک کاربر به صفر</span>
                    </label>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                        <button
                            type="button"
                            @click="isTierModalOpen = false"
                            class="px-3.5 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            انصراف
                        </button>
                        <button
                            type="button"
                            @click="submitTierUpdate"
                            :disabled="isUpdatingTier"
                            class="py-2 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-md shadow-indigo-600/20 transition-all cursor-pointer disabled:opacity-50"
                        >
                            <span>ذخیره تغییرات</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
