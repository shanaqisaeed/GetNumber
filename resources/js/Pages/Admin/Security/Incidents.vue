<script setup lang="ts">
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';

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
        links: any[];
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
                <div class="glass-card !p-4">
                    <span class="text-xs font-bold text-slate-400 block">کل رخدادها</span>
                    <span class="text-xl font-black text-slate-900 dark:text-white font-mono">{{ stats.total }}</span>
                </div>
                <div class="glass-card !p-4">
                    <span class="text-xs font-bold text-amber-500 block">در انتظار بررسی</span>
                    <span class="text-xl font-black text-amber-500 font-mono">{{ stats.unresolved }}</span>
                </div>
                <div class="glass-card !p-4">
                    <span class="text-xs font-bold text-rose-500 block">بحرانی / پرخطر</span>
                    <span class="text-xl font-black text-rose-500 font-mono">{{ stats.critical }}</span>
                </div>
                <div class="glass-card !p-4">
                    <span class="text-xs font-bold text-sky-500 block">رخدادهای امروز</span>
                    <span class="text-xl font-black text-sky-500 font-mono">{{ stats.today }}</span>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="glass-card !p-4 flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px]">
                    <input
                        v-model="search"
                        @keydown.enter="applyFilters"
                        type="text"
                        placeholder="جستجو در نوع رخداد، کاربر، IP..."
                        class="glass-input !py-2 !px-3 !text-xs w-full"
                    />
                </div>

                <select v-model="severity" @change="applyFilters" class="glass-input !py-2 !px-3 !text-xs w-36">
                    <option value="">همه شدت‌ها</option>
                    <option value="critical">بحرانی</option>
                    <option value="high">بالا</option>
                    <option value="medium">متوسط</option>
                    <option value="low">پایین</option>
                </select>

                <select v-model="type" @change="applyFilters" class="glass-input !py-2 !px-3 !text-xs w-48">
                    <option value="">همه انواع رخداد</option>
                    <option value="phishing_attempt">فیشینگ و کلاهبرداری</option>
                    <option value="rate_limit_tripped">تخطی از سقف ارسال</option>
                    <option value="suspicious_behavior_auto_restricted">رفتار مشکوک</option>
                    <option value="failed_2fa_challenge">شکست احراز هویت</option>
                </select>

                <button
                    type="button"
                    @click="applyFilters"
                    class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs"
                >
                    اعمال فیلتر
                </button>
            </div>

            <!-- Incidents Table -->
            <div class="glass-card !p-0 overflow-hidden">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-100/80 dark:bg-slate-800/80 text-slate-500 border-b border-slate-200 dark:border-white/10">
                        <tr>
                            <th class="p-3 font-bold">شناسه</th>
                            <th class="p-3 font-bold">کاربر</th>
                            <th class="p-3 font-bold">نوع رخداد</th>
                            <th class="p-3 font-bold">شدت</th>
                            <th class="p-3 font-bold">اقدام انجام‌شده</th>
                            <th class="p-3 font-bold">آدرس IP</th>
                            <th class="p-3 font-bold">وضعیت</th>
                            <th class="p-3 font-bold text-left">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                        <tr v-for="inc in incidents.data" :key="inc.id" class="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors">
                            <td class="p-3 font-mono font-bold">#{{ inc.id }}</td>
                            <td class="p-3">
                                <div v-if="inc.user" class="space-y-0.5">
                                    <div class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                        <span>{{ inc.user.username }}</span>
                                        <span class="text-[9px] px-1.5 py-0.2 rounded font-mono font-bold bg-slate-200 dark:bg-slate-700">
                                            {{ inc.user.trust_tier }}
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-mono">ریسک: {{ inc.user.risk_score }}</span>
                                </div>
                                <span v-else class="text-slate-400 font-mono">-</span>
                            </td>
                            <td class="p-3 font-bold text-slate-800 dark:text-slate-200">
                                {{ typeLabel(inc.type) }}
                            </td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded-full border text-[10px] font-bold" :class="severityBadgeClass(inc.severity)">
                                    {{ inc.severity }}
                                </span>
                            </td>
                            <td class="p-3 font-mono text-[11px] text-indigo-600 dark:text-indigo-400">
                                {{ inc.action_taken }}
                            </td>
                            <td class="p-3 font-mono text-[11px] text-slate-500" dir="ltr">
                                {{ inc.ip_address || '-' }}
                            </td>
                            <td class="p-3">
                                <span v-if="inc.resolved_at" class="text-emerald-500 font-bold text-[10px] flex items-center gap-1">
                                    ✓ حل‌شده
                                </span>
                                <span v-else class="text-amber-500 font-bold text-[10px]">
                                    در انتظار بررسی
                                </span>
                            </td>
                            <td class="p-3 text-left space-x-2 rtl:space-x-reverse">
                                <button
                                    v-if="!inc.resolved_at"
                                    type="button"
                                    @click="resolveIncident(inc.id)"
                                    class="px-2.5 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-[11px] font-bold"
                                >
                                    بستن رخداد
                                </button>
                                <button
                                    v-if="inc.user"
                                    type="button"
                                    @click="openTierModal(inc.user)"
                                    class="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-[11px] font-bold"
                                >
                                    تنظیم سطح
                                </button>
                            </td>
                        </tr>

                        <tr v-if="incidents.data.length === 0">
                            <td colspan="8" class="p-8 text-center text-slate-400 text-xs">
                                هیچ رخداد امنیتی ثبت‌شده‌ای یافت نشد.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tier Update Modal -->
            <div
                v-if="isTierModalOpen && targetUser"
                class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                @click.self="isTierModalOpen = false"
            >
                <div class="glass-card !p-5 w-full max-w-sm shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                    <h3 class="text-sm font-black">مدیریت سطح اعتبار: {{ targetUser.username }}</h3>

                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300">سطح دسترسی و اعتبار:</label>
                        <select v-model="selectedTier" class="w-full glass-input !py-2.5 !px-3 !text-xs">
                            <option value="new">کاربر جدید (New - محدودیت سخت‌گیرانه)</option>
                            <option value="standard">کاربر استاندارد (Standard - نرخ معمولی)</option>
                            <option value="trusted">کاربر تاییدشده (Trusted - سقف بالا)</option>
                            <option value="restricted">حساب محدود شده (Restricted - مسدود)</option>
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input v-model="resetRisk" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-0" />
                        <span>ریست کردن امتیاز ریسک کاربر به صفر</span>
                    </label>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-white/10">
                        <button
                            type="button"
                            @click="isTierModalOpen = false"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400"
                        >
                            انصراف
                        </button>
                        <button
                            type="button"
                            @click="submitTierUpdate"
                            :disabled="isUpdatingTier"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                        >
                            <span>ذخیره تغییرات</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

