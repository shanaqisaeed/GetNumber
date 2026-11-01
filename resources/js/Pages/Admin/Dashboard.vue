<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface UserItem {
    id: number;
    name: string;
    username: string;
    role: string;
    status: string;
    created_at: string;
}

interface PendingRequestItem {
    id: number;
    user: {
        id: number;
        name: string;
        username: string;
    };
    requested_length: number;
    preferred_number: string | null;
    service_title: string | null;
    created_at: string;
    created_at_human: string;
}

interface AuditFeedItem {
    id: number;
    action: string;
    action_label: string;
    action_badge: string;
    user: {
        id: number;
        name: string;
        username: string;
    } | null;
    ip_address: string | null;
    created_at: string;
    created_at_human: string;
}

const props = defineProps<{
    stats: {
        users: {
            total: number;
            active: number;
            suspended: number;
            admins: number;
            new_today: number;
        };
        numbers: {
            total: number;
            allocated: number;
            available: number;
            standard: number;
            service: number;
            platform: number;
            utilization_rate: number;
        };
        messages: {
            total: number;
            today: number;
            inbound: number;
            outbound: number;
            delivered: number;
            failed: number;
            success_rate: number;
        };
        requests: {
            pending: number;
            approved: number;
            rejected: number;
        };
        tokens: {
            total: number;
            active: number;
            revoked: number;
        };
    };
    recentUsers: UserItem[];
    pendingRequests: PendingRequestItem[];
    recentAudits: AuditFeedItem[];
    systemHealth: {
        database: string;
        queue: string;
        polling: string;
        security: string;
    };
}>();

function formatRole(role: string): string {
    return role === 'admin' ? 'راهبر ارشد' : 'کاربر سازمانی';
}

function formatStatus(status: string): string {
    if (status === 'active') return 'فعال';
    if (status === 'suspended') return 'تعلیق‌شده';
    return 'غیرفعال';
}
</script>

<template>
    <Head title="پیشخوان مدیریت و پایش متمرکز" />

    <AdminLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-base font-bold text-slate-900 dark:text-white">کنسول راهبری و مانیتورینگ یکپارچه سامانه</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">وضعیت لحظه‌ای کاربران، خطوط سازمانی، ترافیک پیام‌ها و رخدادهای امنیتی</p>
                </div>

                <!-- System Operational Status Pills -->
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>سامانه عملیاتی</span>
                    </span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-mono text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800" dir="ltr">
                        v2.0 Enterprise
                    </span>
                </div>
            </div>
        </template>

        <!-- Top 4 Primary KPI Analytics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- 1. Users KPI -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">کل کاربران سامانه</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stats.users.total }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-[11px]">
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold">{{ stats.users.active }} فعال</span>
                    <span class="text-amber-600 dark:text-amber-400 font-medium">{{ stats.users.suspended }} تعلیق</span>
                    <span class="text-rose-600 dark:text-rose-400 font-medium">{{ stats.users.admins }} راهبر</span>
                </div>
            </div>

            <!-- 2. Numbers Pool KPI -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">استخر خطوط و سرشماره‌ها</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stats.numbers.total }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="flex justify-between text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">
                        <span>ضریب تخصیص</span>
                        <span>{{ stats.numbers.utilization_rate }}٪</span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full transition-all duration-500" :style="{ width: `${stats.numbers.utilization_rate}%` }"></div>
                    </div>
                    <div class="flex items-center justify-between text-[10px] text-slate-400 mt-2">
                        <span>{{ stats.numbers.allocated }} تخصیص‌یافته</span>
                        <span>{{ stats.numbers.available }} آزاد در استخر</span>
                    </div>
                </div>
            </div>

            <!-- 3. Messages Traffic KPI -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">ترافیک پیام‌ها (SMS)</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stats.messages.total }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-[11px]">
                    <span class="text-sky-600 dark:text-sky-400 font-bold">{{ stats.messages.today }} پیام امروز</span>
                    <span class="text-slate-500 dark:text-slate-400">موفقیت: {{ stats.messages.success_rate }}٪</span>
                </div>
            </div>

            <!-- 4. Requests & Security KPI -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">درخواست‌های در انتظار</p>
                        <p
                            class="text-2xl font-black mt-1"
                            :class="stats.requests.pending > 0 ? 'text-rose-600 dark:text-rose-400 animate-pulse' : 'text-slate-900 dark:text-white'"
                        >
                            {{ stats.requests.pending }}
                        </p>
                    </div>
                    <div
                        class="w-12 h-12 rounded-2xl flex items-center justify-center"
                        :class="stats.requests.pending > 0 ? 'bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400' : 'bg-slate-50 dark:bg-slate-800 text-slate-500'"
                    >
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-[11px]">
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold">{{ stats.requests.approved }} تأیید شده</span>
                    <span class="text-slate-400">{{ stats.tokens.active }} کلید فعال</span>
                </div>
            </div>
        </div>

        <!-- Quick Administration Actions Bar -->
        <div class="rounded-3xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 border border-slate-800 p-5 text-white shadow-lg mb-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-sm font-black flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span>دسترسی‌های سریع مدیریتی</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">میانبر به عملیات‌های کلیدی راهبری و نظارت بر پلتفرم</p>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5">
                <!-- Review Requests with pending badge -->
                <Link
                    :href="route('admin.requests.index')"
                    class="relative p-3 rounded-2xl bg-slate-800/80 hover:bg-rose-950/40 border border-slate-700/60 hover:border-rose-500/50 flex flex-col items-center justify-center text-center transition-all group"
                >
                    <span
                        v-if="stats.requests.pending > 0"
                        class="absolute -top-1.5 -right-1.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-500 text-white shadow"
                    >
                        {{ stats.requests.pending }}
                    </span>
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-rose-400 mb-1.5 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    <span class="text-xs font-bold text-slate-200 group-hover:text-white">بررسی درخواست‌ها</span>
                </Link>

                <!-- Messages Monitor -->
                <Link
                    :href="route('admin.messages.index')"
                    class="p-3 rounded-2xl bg-slate-800/80 hover:bg-sky-950/40 border border-slate-700/60 hover:border-sky-500/50 flex flex-col items-center justify-center text-center transition-all group"
                >
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-sky-400 mb-1.5 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    <span class="text-xs font-bold text-slate-200 group-hover:text-white">پایش زنده پیام‌ها</span>
                </Link>

                <!-- Manage Numbers -->
                <Link
                    :href="route('admin.numbers.index')"
                    class="p-3 rounded-2xl bg-slate-800/80 hover:bg-emerald-950/40 border border-slate-700/60 hover:border-emerald-500/50 flex flex-col items-center justify-center text-center transition-all group"
                >
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-emerald-400 mb-1.5 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <span class="text-xs font-bold text-slate-200 group-hover:text-white">مدیریت خطوط</span>
                </Link>

                <!-- Audit Logs & Security -->
                <Link
                    :href="route('admin.audit-logs.index')"
                    class="p-3 rounded-2xl bg-slate-800/80 hover:bg-amber-950/40 border border-slate-700/60 hover:border-amber-500/50 flex flex-col items-center justify-center text-center transition-all group"
                >
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-amber-400 mb-1.5 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span class="text-xs font-bold text-slate-200 group-hover:text-white">گزارش رویدادها</span>
                </Link>

                <!-- Issue API Token -->
                <Link
                    :href="route('admin.api-tokens.index')"
                    class="p-3 rounded-2xl bg-slate-800/80 hover:bg-indigo-950/40 border border-slate-700/60 hover:border-indigo-500/50 flex flex-col items-center justify-center text-center transition-all group"
                >
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-indigo-400 mb-1.5 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span class="text-xs font-bold text-slate-200 group-hover:text-white">کلیدهای دسترسی</span>
                </Link>

                <!-- Create User -->
                <Link
                    :href="route('admin.users.create')"
                    class="p-3 rounded-2xl bg-slate-800/80 hover:bg-purple-950/40 border border-slate-700/60 hover:border-purple-500/50 flex flex-col items-center justify-center text-center transition-all group"
                >
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-purple-400 mb-1.5 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                    <span class="text-xs font-bold text-slate-200 group-hover:text-white">تعریف حساب جدید</span>
                </Link>
            </div>
        </div>

        <!-- Middle Section: 2 Columns Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Left 2 Cols: Pending Requests + Recent Audit Logs -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Pending Requests Widget -->
                <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm">
                    <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                            <h2 class="text-sm font-bold text-slate-900 dark:text-white">درخواست‌های سرشماره در انتظار تصمیم</h2>
                        </div>
                        <Link
                            :href="route('admin.requests.index')"
                            class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline flex items-center gap-1"
                        >
                            <span>مشاهده همه</span>
                            <svg class="w-3.5 h-3.5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </Link>
                    </div>

                    <div v-if="pendingRequests.length > 0" class="divide-y divide-slate-100 dark:divide-slate-800 mt-2">
                        <div
                            v-for="req in pendingRequests"
                            :key="req.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <div class="flex items-center gap-3 truncate">
                                <div class="w-9 h-9 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ req.requested_length }}رقم
                                </div>
                                <div class="truncate">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-xs text-slate-900 dark:text-white truncate">
                                            {{ req.service_title || 'درخواست سرشماره' }}
                                        </span>
                                        <span v-if="req.preferred_number" class="text-[10px] font-mono px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300" dir="ltr">
                                            {{ req.preferred_number }}
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-400 flex items-center gap-2 mt-0.5">
                                        <span>کاربر: <strong class="text-slate-700 dark:text-slate-300">{{ req.user.name }}</strong> (@{{ req.user.username }})</span>
                                        <span>•</span>
                                        <span>{{ req.created_at_human }}</span>
                                    </div>
                                </div>
                            </div>

                            <Link
                                :href="route('admin.requests.index')"
                                class="px-3 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 hover:bg-rose-100 dark:hover:bg-rose-900/60 text-rose-600 dark:text-rose-400 text-xs font-bold shrink-0 transition-colors"
                            >
                                بررسی و اقدام
                            </Link>
                        </div>
                    </div>

                    <div v-else class="py-8 text-center text-slate-400">
                        <svg class="w-9 h-9 mx-auto text-slate-300 dark:text-slate-700 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-xs font-bold text-slate-600 dark:text-slate-400">تمامی درخواست‌های سرشماره بررسی شده‌اند.</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">هیچ موردی در صف انتظار تأیید قرار ندارد.</p>
                    </div>
                </div>

                <!-- Recent System Audit Feed -->
                <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm">
                    <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <h2 class="text-sm font-bold text-slate-900 dark:text-white">فید لحظه‌ای رخدادها و امنیت</h2>
                        </div>
                        <Link
                            :href="route('admin.audit-logs.index')"
                            class="text-xs font-bold text-amber-600 dark:text-amber-400 hover:underline flex items-center gap-1"
                        >
                            <span>مشاهده همه لاگ‌ها</span>
                            <svg class="w-3.5 h-3.5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </Link>
                    </div>

                    <div v-if="recentAudits.length > 0" class="divide-y divide-slate-100 dark:divide-slate-800 mt-2">
                        <div
                            v-for="audit in recentAudits"
                            :key="audit.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <div class="flex items-center gap-3 truncate">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold shrink-0"
                                    :class="audit.action_badge"
                                >
                                    {{ audit.action_label }}
                                </span>
                                <div class="truncate">
                                    <div class="text-xs text-slate-800 dark:text-slate-200 font-medium truncate">
                                        توسط: <strong class="text-slate-900 dark:text-white">{{ audit.user ? audit.user.name : 'سیستم' }}</strong>
                                        <span v-if="audit.user" class="text-slate-400 font-mono text-[10px] mr-1" dir="ltr">(@{{ audit.user.username }})</span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-mono" dir="ltr">{{ audit.ip_address || '127.0.0.1' }}</span>
                                </div>
                            </div>

                            <span class="text-[11px] text-slate-400 font-mono shrink-0" dir="ltr">
                                {{ audit.created_at_human }}
                            </span>
                        </div>
                    </div>

                    <div v-else class="py-8 text-center text-slate-400">
                        <p class="text-xs font-medium">هیچ رخدادی ثبت نشده است.</p>
                    </div>
                </div>
            </div>

            <!-- Right 1 Col: Platform Lines Breakdown & Recent Users -->
            <div class="space-y-6">
                <!-- Numbers Distribution Card -->
                <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white pb-3 border-b border-slate-100 dark:border-slate-800">
                        تفکیک خطوط سامانه
                    </h2>
                    <div class="space-y-3 mt-4 text-xs">
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                            <span class="font-medium text-slate-600 dark:text-slate-300">خطوط استاندارد (۹ رقمی)</span>
                            <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400">{{ stats.numbers.standard }}</span>
                        </div>
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                            <span class="font-medium text-slate-600 dark:text-slate-300">سرشماره‌های اختصاصی کاربران</span>
                            <span class="font-mono font-bold text-amber-600 dark:text-amber-400">{{ stats.numbers.service }}</span>
                        </div>
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                            <span class="font-medium text-slate-600 dark:text-slate-300">سرشماره‌های اختصاصی پلتفرم</span>
                            <span class="font-mono font-bold text-rose-600 dark:text-rose-400">{{ stats.numbers.platform }}</span>
                        </div>
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                            <span class="font-medium text-slate-600 dark:text-slate-300">کلیدهای فعال وب‌سرویس</span>
                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">{{ stats.tokens.active }}</span>
                        </div>
                    </div>
                </div>

                <!-- Recent Registered Users Widget -->
                <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">آخرین کاربران ثبت‌شده</h2>
                        <Link :href="route('admin.users.index')" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                            مدیریت
                        </Link>
                    </div>

                    <div class="divide-y divide-slate-100 dark:divide-slate-800 mt-2">
                        <div
                            v-for="u in recentUsers"
                            :key="u.id"
                            class="py-3 flex items-center justify-between gap-3"
                        >
                            <div class="truncate">
                                <p class="text-xs font-bold text-slate-900 dark:text-white truncate">{{ u.name }}</p>
                                <p class="text-[10px] text-slate-400 font-mono" dir="ltr">@{{ u.username }}</p>
                            </div>
                            <span
                                class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold shrink-0"
                                :class="u.status === 'active' ? 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400' : 'bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400'"
                            >
                                {{ formatStatus(u.status) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
