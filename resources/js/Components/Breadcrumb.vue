<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

const props = withDefaults(defineProps<{
    items?: BreadcrumbItem[];
    homeRoute?: string;
    homeLabel?: string;
}>(), {
    items: () => [],
    homeRoute: 'dashboard',
    homeLabel: 'پیشخوان',
});

// Auto-detected trail mapping when props.items is empty
const autoItems = computed<BreadcrumbItem[]>(() => {
    if (props.items && props.items.length > 0) {
        return props.items;
    }

    if (typeof window === 'undefined' || !(window as any).route) {
        return [];
    }

    const ziggy = (window as any).route;
    const currentRoute: string = ziggy().current() || '';

    // Route dictionaries for automatic breadcrumb trail
    const routeMap: Record<string, BreadcrumbItem[]> = {
        // User Routes
        'numbers.index': [{ label: 'خطوط من' }],
        'numbers.create': [{ label: 'خطوط من', href: ziggy().has?.('numbers.index') ? ziggy('numbers.index') : undefined }, { label: 'تخصیص خط جدید' }],
        'messages.index': [{ label: 'پیامک‌ها و صندوق گفتگو' }],
        'requests.index': [{ label: 'درخواست‌های سرشماره' }],
        'requests.create': [{ label: 'درخواست‌های سرشماره', href: ziggy().has?.('requests.index') ? ziggy('requests.index') : undefined }, { label: 'ثبت درخواست جدید' }],
        'tickets.index': [{ label: 'میز خدمت و پشتیبانی' }],
        'tickets.create': [{ label: 'میز خدمت و پشتیبانی', href: ziggy().has?.('tickets.index') ? ziggy('tickets.index') : undefined }, { label: 'ارسال تیکت جدید' }],
        'tickets.show': [{ label: 'میز خدمت و پشتیبانی', href: ziggy().has?.('tickets.index') ? ziggy('tickets.index') : undefined }, { label: 'مشاهده گفتگو' }],
        'payments.index': [{ label: 'امور مالی و تراکنش‌ها' }],
        'payments.checkout': [{ label: 'امور مالی و تراکنش‌ها', href: ziggy().has?.('payments.index') ? ziggy('payments.index') : undefined }, { label: 'فاکتور پرداخت' }],
        'profile.edit': [{ label: 'پروفایل و تنظیمات امنیتی' }],
        'api-tokens.index': [{ label: 'کلیدهای ارتباطی و وب‌سرویس' }],

        // Admin Routes
        'admin.users.index': [{ label: 'مدیریت کاربران و دسترسی‌ها' }],
        'admin.numbers.index': [{ label: 'مدیریت خطوط و سرشماره‌ها' }],
        'admin.prefixes.index': [{ label: 'مدیریت پیش‌شماره‌ها' }],
        'admin.requests.index': [{ label: 'بررسی درخواست‌های سرشماره' }],
        'admin.messages.index': [{ label: 'مدیریت و نظارت بر پیام‌ها' }],
        'admin.payments.index': [{ label: 'درگاه‌ها و تراکنش‌های مالی' }],
        'admin.api-tokens.index': [{ label: 'کلیدهای ارتباطی وب‌سرویس' }],
        'admin.tickets.index': [{ label: 'میز خدمت و تیکت‌ها' }],
        'admin.monitoring.index': [{ label: 'دیده‌بانی و مانیتورینگ سیستم' }],
        'admin.security.incidents.index': [{ label: 'رخدادهای امنیتی و فیشینگ' }],
        'admin.audit-logs.index': [{ label: 'گزارش رویدادها و لاگ‌های امنیتی' }],
    };

    for (const [key, trail] of Object.entries(routeMap)) {
        if (currentRoute === key || currentRoute.startsWith(key.replace('.index', ''))) {
            return trail;
        }
    }

    return [];
});
</script>

<template>
    <nav aria-label="مسیر راهنما" class="flex items-center text-xs text-slate-500 dark:text-slate-400 py-1.5 overflow-x-auto no-scrollbar">
        <ol class="flex items-center gap-1.5 min-w-max">
            <!-- Home Link -->
            <li class="inline-flex items-center">
                <Link
                    :href="route(props.homeRoute)"
                    class="inline-flex items-center gap-1 text-slate-600 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400 font-bold transition-colors"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <span>{{ props.homeLabel }}</span>
                </Link>
            </li>

            <!-- Dynamic Trail Items -->
            <template v-for="(item, idx) in autoItems" :key="idx">
                <li class="inline-flex items-center text-slate-400 dark:text-slate-600">
                    <svg class="w-3 h-3 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </li>

                <li class="inline-flex items-center">
                    <Link
                        v-if="item.href && idx < autoItems.length - 1"
                        :href="item.href"
                        class="hover:text-indigo-600 dark:hover:text-indigo-400 font-medium transition-colors"
                    >
                        {{ item.label }}
                    </Link>
                    <span
                        v-else
                        class="font-black text-slate-900 dark:text-slate-200"
                        aria-current="page"
                    >
                        {{ item.label }}
                    </span>
                </li>
            </template>
        </ol>
    </nav>
</template>
