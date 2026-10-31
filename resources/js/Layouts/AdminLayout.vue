<script setup lang="ts">
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);

const isMobileSidebarOpen = ref(false);

const adminNavigation = [
    {
        name: 'پیشخوان مدیریت',
        href: route('admin.dashboard'),
        active: route().current('admin.dashboard'),
        icon: 'M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z',
    },
    {
        name: 'مدیریت کاربران و دسترسی‌ها',
        href: route('admin.users.index'),
        active: route().current('admin.users.*'),
        icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    },
    {
        name: 'مدیریت خطوط',
        href: route('admin.numbers.index'),
        active: route().current('admin.numbers.*'),
        icon: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
    },
    {
        name: 'مدیریت پیش‌شماره‌ها',
        href: route('admin.prefixes.index'),
        active: route().current('admin.prefixes.*'),
        icon: 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
    },
    {
        name: 'بررسی درخواست‌های سرشماره',
        href: route('admin.requests.index'),
        active: route().current('admin.requests.*'),
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    },
    {
        name: 'مدیریت و نظارت بر پیام‌ها',
        href: route('admin.messages.index'),
        active: route().current('admin.messages.*'),
        icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
    },
    {
        name: 'مدیریت مالی و کیف‌پول‌ها',
        href: route('admin.wallets.index'),
        active: route().current('admin.wallets.index'),
        icon: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    },
    {
        name: 'دفترکل تراکنش‌های مالی',
        href: route('admin.wallets.transactions'),
        active: route().current('admin.wallets.transactions'),
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
    },
    {
        name: 'واریزی‌های کریپتو و درگاه‌ها',
        href: route('admin.payments.index'),
        active: route().current('admin.payments.*'),
        icon: 'M13 10V3L4 14h7v7l9-11h-7z',
    },
    {
        name: 'کلیدهای ارتباطی و وب‌سرویس',
        href: route('admin.api-tokens.index'),
        active: route().current('admin.api-tokens.*'),
        icon: 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
    },
    {
        name: 'میز خدمت و تیکت‌ها',
        href: route('admin.tickets.index'),
        active: route().current('admin.tickets.*'),
        icon: 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z',
    },
    {
        name: 'گزارش رویدادها و امنیت',
        href: route('admin.audit-logs.index'),
        active: route().current('admin.audit-logs.*'),
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    },
];
</script>

<template>
    <div class="min-h-screen bg-slate-900/5 dark:bg-slate-950 text-slate-900 dark:text-slate-100 flex flex-col md:flex-row transition-colors duration-300">
        <!-- Admin Desktop Sidebar -->
        <aside class="hidden md:flex flex-col w-72 bg-slate-900 text-slate-200 border-l border-slate-800 shrink-0">
            <!-- Brand & Admin Badge -->
            <div class="h-20 flex flex-col justify-center px-6 border-b border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-amber-500 to-rose-600 flex items-center justify-center text-white shadow-md shadow-rose-900/40">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-black text-base text-white">سامانه مدیریت خطوط</span>
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-400 border border-rose-500/30">مدیریت</span>
                        </div>
                        <span class="block text-[11px] text-slate-400">کنسول راهبری سامانه</span>
                    </div>
                </div>
            </div>

            <!-- Admin Navigation -->
            <nav class="flex-1 p-4 space-y-1.5 overflow-y-auto">
                <Link
                    v-for="item in adminNavigation"
                    :key="item.name"
                    :href="item.href"
                    class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all"
                    :class="[
                        item.active
                            ? 'bg-rose-500/20 text-rose-400 font-bold border border-rose-500/30'
                            : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                    ]"
                >
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                    </svg>
                    <span>{{ item.name }}</span>
                </Link>
            </nav>

            <!-- Bottom Return to User Area / Profile -->
            <div class="p-4 border-t border-slate-800 space-y-2">
                <Link
                    :href="route('dashboard')"
                    class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>ورود به پیشخوان کاربری</span>
                </Link>

                <div class="p-3 rounded-2xl bg-slate-800/60 border border-slate-700/60 flex items-center justify-between">
                    <div class="flex items-center gap-2.5 truncate">
                        <div class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-400 border border-rose-500/30 flex items-center justify-center font-bold text-sm shrink-0">
                            A
                        </div>
                        <div class="truncate">
                            <p class="text-xs font-bold text-white truncate">{{ user?.name }}</p>
                            <p class="text-[10px] text-rose-400 font-medium">راهبر ارشد سامانه</p>
                        </div>
                    </div>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="p-1.5 text-slate-400 hover:text-rose-400 rounded-lg hover:bg-slate-700 transition-colors"
                        title="خروج از سامانه"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Admin Mobile Sidebar Drawer (Responsive) -->
        <div v-if="isMobileSidebarOpen" class="fixed inset-0 z-50 md:hidden flex">
            <!-- Backdrop -->
            <div
                class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity"
                @click="isMobileSidebarOpen = false"
            ></div>

            <!-- Drawer Container with guaranteed scroll -->
            <div class="relative w-80 max-w-[85%] bg-slate-900 text-slate-200 h-dvh max-h-screen flex flex-col border-l border-slate-800 z-10 shadow-2xl">
                <!-- Header & Admin Badge -->
                <div class="h-16 flex items-center justify-between px-5 border-b border-slate-800 shrink-0">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-amber-500 to-rose-600 flex items-center justify-center text-white shadow-md shadow-rose-900/40">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-xs text-white">مدیریت خطوط</span>
                                <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-rose-500/20 text-rose-400 border border-rose-500/30">کنسول ادمین</span>
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="isMobileSidebarOpen = false"
                        class="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                        aria-label="بستن منوی مدیریت"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Admin Navigation Links with smooth scroll -->
                <nav class="flex-1 min-h-0 p-4 space-y-1.5 overflow-y-auto overscroll-contain">
                    <Link
                        v-for="item in adminNavigation"
                        :key="item.name"
                        :href="item.href"
                        @click="isMobileSidebarOpen = false"
                        class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all"
                        :class="[
                            item.active
                                ? 'bg-rose-500/20 text-rose-400 font-bold border border-rose-500/30'
                                : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                        ]"
                    >
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                        </svg>
                        <span>{{ item.name }}</span>
                    </Link>
                </nav>

                <!-- Bottom Controls in Mobile Drawer -->
                <div class="p-4 border-t border-slate-800 shrink-0 space-y-2">
                    <Link
                        :href="route('dashboard')"
                        @click="isMobileSidebarOpen = false"
                        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-medium text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-800 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>ورود به پیشخوان کاربری</span>
                    </Link>

                    <div class="p-3 rounded-2xl bg-slate-800/60 border border-slate-700/60 flex items-center justify-between">
                        <div class="flex items-center gap-2.5 truncate">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-400 border border-rose-500/30 flex items-center justify-center font-bold text-xs shrink-0">
                                A
                            </div>
                            <div class="truncate">
                                <p class="text-xs font-bold text-white truncate">{{ user?.name }}</p>
                                <p class="text-[10px] text-rose-400 font-medium">راهبر ارشد سامانه</p>
                            </div>
                        </div>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="p-1.5 text-slate-400 hover:text-rose-400 rounded-lg hover:bg-slate-700 transition-colors"
                            title="خروج از سامانه"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Top Admin Header -->
            <header class="h-16 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 px-4 sm:px-6 flex items-center justify-between sticky top-0 z-30">
                <div class="flex items-center gap-3 min-w-0">
                    <button
                        type="button"
                        @click="isMobileSidebarOpen = true"
                        class="md:hidden p-2 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 shrink-0"
                    >
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="truncate">
                        <slot name="header" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <ThemeSwitcher />
                </div>
            </header>

            <!-- Admin Body -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl mx-auto w-full space-y-6">
                    <!-- Global Flash Messages -->
                    <div v-if="$page.props.flash?.success" class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-xs font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-2 shadow-sm">
                        <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ $page.props.flash.success }}</span>
                    </div>

                    <div v-if="$page.props.flash?.error" class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2 shadow-sm">
                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ $page.props.flash.error }}</span>
                    </div>

                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
