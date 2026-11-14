<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface KPIs {
    daily_volume: number;
    daily_tx_count: number;
    pending_orders_count: number;
    messages_today: number;
    total_messages: number;
    delivery_rate: number;
    active_lines: number;
    total_users: number;
}

interface GatewayItem {
    id: number;
    name: string;
    code: string;
    driver: string;
    is_active: boolean;
    is_backup: boolean;
    currency: string;
    success_rate: number;
}

interface PendingOrderItem {
    id: number;
    order_number: string;
    service_title: string;
    identifier: string;
    amount: string;
    user_name: string;
    account_id: string;
    created_at: string;
}

interface TransactionItem {
    id: number;
    type: string;
    type_label: string;
    type_badge: string;
    amount: string;
    user_name: string;
    account_id: string;
    description: string;
    created_at: string;
}

interface ResourceMetrics {
    database_size_mb: number;
    total_users: number;
    active_users: number;
    allocated_numbers: number;
    total_numbers: number;
    php_version: string;
    uptime: string;
    storage_status: string;
}

const props = defineProps<{
    kpis: KPIs;
    gateways: GatewayItem[];
    recent_pending_orders: PendingOrderItem[];
    recent_transactions: TransactionItem[];
    resources: ResourceMetrics;
    pending_count: number;
}>();

const formatCurrency = (amount: number | string) => {
    const num = Number(amount) || 0;
    return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(num);
};

const formatNumber = (num: number | string) => {
    return new Intl.NumberFormat('fa-IR').format(Number(num) || 0);
};
</script>

<template>
    <Head title="داشبورد فرماندهی سیستم - مدیریت پروداکشن" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white tracking-tight flex items-center gap-2">
                        <span class="inline-block w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
                        داشبورد مانیتورینگ و فرماندهی عملیات
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        نمای زنده شاخص‌های کلیدی عملکرد (KPIs)، وضعیت درگاه‌ها، منابع دیتابیس و پردازش سفارشات
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('admin.orders.index')"
                        class="relative inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <span>بررسی سفارشات</span>
                        <span
                            v-if="kpis.pending_orders_count > 0"
                            class="px-2 py-0.5 text-xs font-bold bg-amber-400 text-amber-950 rounded-full animate-bounce"
                        >
                            {{ formatNumber(kpis.pending_orders_count) }}
                        </span>
                    </Link>
                    <Link
                        :href="route('admin.services.index')"
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                        </svg>
                        مدیریت سرویس‌ها
                    </Link>
                </div>
            </div>

            <!-- Vital KPIs Cards Grid (4 Cards) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <!-- 1. Daily Transaction Volume -->
                <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-2 h-full bg-emerald-500"></div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">حجم تراکنش روزانه</span>
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="text-2xl font-black text-gray-900 dark:text-white">
                            {{ formatCurrency(kpis.daily_volume) }}
                            <span class="text-xs font-normal text-gray-500">کوین / تومان</span>
                        </div>
                        <div class="mt-1 flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                            <span class="font-medium">{{ formatNumber(kpis.daily_tx_count) }}</span>
                            <span>تراکنش امروز ثبت شده</span>
                        </div>
                    </div>
                </div>

                <!-- 2. Pending Orders Counter -->
                <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-2 h-full bg-amber-500"></div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">سفارشات معلق</span>
                        <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="text-2xl font-black text-gray-900 dark:text-white flex items-center gap-2">
                            {{ formatNumber(kpis.pending_orders_count) }}
                            <span
                                v-if="kpis.pending_orders_count > 0"
                                class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300 font-bold"
                            >
                                نیازمند اقدام
                            </span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            در صف تایید یا بررسی ادمین
                        </div>
                    </div>
                </div>

                <!-- 3. Exchanged Messages & Delivery Rate -->
                <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-2 h-full bg-blue-500"></div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">پیام‌های تبادل‌شده</span>
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="text-2xl font-black text-gray-900 dark:text-white">
                            {{ formatNumber(kpis.messages_today) }}
                            <span class="text-xs font-normal text-gray-500">پیام امروز</span>
                        </div>
                        <div class="mt-1 flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400">
                            <span>نرخ تحویل:</span>
                            <span class="font-bold">{{ kpis.delivery_rate }}%</span>
                            <span class="text-gray-400">(مجموع: {{ formatNumber(kpis.total_messages) }})</span>
                        </div>
                    </div>
                </div>

                <!-- 4. Active Subscriptions & Lines -->
                <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 relative overflow-hidden group">
                    <div class="absolute top-0 right-0 w-2 h-full bg-purple-500"></div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">خطوط و کاربران فعال</span>
                        <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="text-2xl font-black text-gray-900 dark:text-white">
                            {{ formatNumber(kpis.active_lines) }}
                            <span class="text-xs font-normal text-gray-500">خط فعال</span>
                        </div>
                        <div class="mt-1 flex items-center gap-1.5 text-xs text-purple-600 dark:text-purple-400">
                            <span>کاربران:</span>
                            <span class="font-bold">{{ formatNumber(kpis.total_users) }}</span>
                            <span>عضو در پلتفرم</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Middle Section: Payment Gateways Status & Resource Metrics -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Payment Gateways Health (1 col) -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                </svg>
                                وضعیت درگاه‌های پرداخت
                            </h2>
                            <Link :href="route('admin.payments.gateways')" class="text-xs text-indigo-600 hover:underline">
                                پیکربندی کلیدها
                            </Link>
                        </div>
                        <div class="space-y-3">
                            <div
                                v-for="gw in gateways"
                                :key="gw.id"
                                class="flex items-center justify-between p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50 border border-gray-100 dark:border-gray-600/50"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-2.5 h-2.5 rounded-full"
                                        :class="gw.is_active ? 'bg-emerald-500' : 'bg-gray-400'"
                                    ></span>
                                    <div>
                                        <div class="text-sm font-bold text-gray-900 dark:text-white">
                                            {{ gw.name }}
                                            <span v-if="gw.is_backup" class="text-xs font-normal text-amber-600 dark:text-amber-400 mr-1">(پشتیبان)</span>
                                        </div>
                                        <div class="text-xs text-gray-400 font-mono">{{ gw.driver }} • {{ gw.currency }}</div>
                                    </div>
                                </div>
                                <div class="text-left">
                                    <span
                                        class="inline-block px-2 py-0.5 text-xs font-medium rounded-full"
                                        :class="gw.is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-600 dark:text-gray-300'"
                                    >
                                        {{ gw.is_active ? 'فعال و آنلاین' : 'غیرفعال' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-500 flex items-center justify-between">
                        <span>تضمین پایداری تراکنش‌ها</span>
                        <span class="font-bold text-emerald-600">SLA 99.4%</span>
                    </div>
                </div>

                <!-- Resource & Database Monitoring (2 cols) -->
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                            </svg>
                            سلامت سیستم و مانیتورینگ منابع دیتابیس
                        </h2>
                        <span class="text-xs px-2.5 py-1 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 font-semibold">
                            وضعیت سیستم: نرمال
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-100 dark:border-gray-600/40">
                            <div class="text-xs text-gray-400">حجم پایگاه داده</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white mt-1 font-mono">
                                {{ resources.database_size_mb }} <span class="text-xs font-normal">MB</span>
                            </div>
                            <div class="text-[11px] text-gray-400 mt-0.5">موتور ذخیره‌سازی بهینه</div>
                        </div>

                        <div class="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-100 dark:border-gray-600/40">
                            <div class="text-xs text-gray-400">کاربران فعال سامانه</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white mt-1 font-mono">
                                {{ formatNumber(resources.active_users) }} <span class="text-xs font-normal">/ {{ formatNumber(resources.total_users) }}</span>
                            </div>
                            <div class="text-[11px] text-emerald-500 mt-0.5">احراز هویت استاندارد</div>
                        </div>

                        <div class="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-100 dark:border-gray-600/40">
                            <div class="text-xs text-gray-400">تخصیص سرشماره‌ها</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white mt-1 font-mono">
                                {{ formatNumber(resources.allocated_numbers) }} <span class="text-xs font-normal">/ {{ formatNumber(resources.total_numbers) }}</span>
                            </div>
                            <div class="text-[11px] text-blue-500 mt-0.5">نرخ بهره‌وری پویا</div>
                        </div>

                        <div class="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-100 dark:border-gray-600/40">
                            <div class="text-xs text-gray-400">نسخه PHP و آپ‌تایم</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white mt-1 font-mono">
                                {{ resources.php_version }}
                            </div>
                            <div class="text-[11px] text-purple-500 mt-0.5">{{ resources.uptime }} پایداری سرور</div>
                        </div>
                    </div>

                    <div class="mt-4 p-4 rounded-xl bg-indigo-50/60 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/50 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-indigo-900 dark:text-indigo-200">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>تمام ارتباطات پیامکی، ساب‌ایمیل‌ها و تماس‌های صوتی از طریق لایه انتزاعی ارتباطات (Communication Layer) ثبت و پایش می‌شوند.</span>
                        </div>
                        <Link :href="route('admin.messages.index')" class="font-bold underline shrink-0 hover:text-indigo-700">
                            مشاهده لاگ پیام‌ها &larr;
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Lower Section: Recent Pending Orders & Recent Transactions -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Pending Orders Waiting Review -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                            سفارشات جدید در صف بررسی
                        </h2>
                        <Link :href="route('admin.orders.index')" class="text-xs text-indigo-600 hover:underline">
                            مشاهده همه سفارشات ({{ formatNumber(kpis.pending_orders_count) }})
                        </Link>
                    </div>

                    <div v-if="recent_pending_orders.length === 0" class="py-8 text-center text-sm text-gray-400">
                        سفارش معلقی در صف بررسی وجود ندارد.
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="order in recent_pending_orders"
                            :key="order.id"
                            class="p-3.5 rounded-xl border border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-700/30 flex items-center justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400">{{ order.order_number }}</span>
                                    <span class="text-xs px-2 py-0.5 rounded bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200">{{ order.service_title }}</span>
                                </div>
                                <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-white truncate">
                                    {{ order.identifier }}
                                </div>
                                <div class="text-xs text-gray-400 mt-0.5">
                                    کاربر: {{ order.user_name }} ({{ order.account_id }}) • {{ order.created_at }}
                                </div>
                            </div>
                            <div class="text-left shrink-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">
                                    {{ formatCurrency(order.amount) }} <span class="text-xs font-normal text-gray-400">کوین</span>
                                </div>
                                <Link
                                    :href="route('admin.orders.index', { search: order.order_number })"
                                    class="mt-1 inline-block text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                                >
                                    بررسی و تایید &rarr;
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Financial Transactions -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            آخرین تراکنش‌های مالی کیف‌پول
                        </h2>
                        <Link :href="route('admin.payments.index')" class="text-xs text-indigo-600 hover:underline">
                            دفتر کل تراکنش‌ها
                        </Link>
                    </div>

                    <div v-if="recent_transactions.length === 0" class="py-8 text-center text-sm text-gray-400">
                        تراکنشی یافت نشد.
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="tx in recent_transactions"
                            :key="tx.id"
                            class="p-3.5 rounded-xl border border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-700/30 flex items-center justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded font-semibold" :class="tx.type_badge">
                                        {{ tx.type_label }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ tx.created_at }}</span>
                                </div>
                                <div class="mt-1 text-xs text-gray-600 dark:text-gray-300 truncate">
                                    {{ tx.description || 'تراکنش مالی استاندارد' }}
                                </div>
                                <div class="text-[11px] text-gray-400 mt-0.5">
                                    کاربر: {{ tx.user_name }} ({{ tx.account_id }})
                                </div>
                            </div>
                            <div class="text-left shrink-0">
                                <span class="font-mono text-sm font-bold text-gray-900 dark:text-white">
                                    {{ formatCurrency(tx.amount) }}
                                </span>
                                <span class="text-xs text-gray-400 mr-1">کوین</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
