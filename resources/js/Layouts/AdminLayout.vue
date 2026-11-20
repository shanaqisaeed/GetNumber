<script setup lang="ts">
import { __ } from '@/Composables/useTrans';
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import NotificationPermissionModal from '@/Components/NotificationPermissionModal.vue';
import { useRealtimeMessages } from '@/Composables/useRealtimeMessages';
import { useWebNotifications } from '@/Composables/useWebNotifications';
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);

const isMobileSidebarOpen = ref(false);

// Close responsive menu automatically on Inertia route navigation
watch(
    () => page.url,
    () => {
        isMobileSidebarOpen.value = false;
    }
);

const { showLocalNotification } = useWebNotifications();

useRealtimeMessages({
    userId: user.value?.id,
    isAdmin: true,
    pollUrl: route('admin.messages.poll'),
    onMessage: (msg) => {
        if (msg.direction === 'inbound' || (!msg.direction && msg.sender)) {
            const sender = msg.peer_number || msg.sender;
            showLocalNotification(sender, msg.id);
        }
    },
});

const adminNavigation = [
    {
        name: computed(() => __('ui.layouts.admin.nav.dashboard')),
        href: route('admin.dashboard'),
        active: route().current('admin.dashboard'),
        icon: 'M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.users')),
        href: route('admin.users.index'),
        active: route().current('admin.users.*'),
        icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.services')),
        href: route('admin.services.index'),
        active: route().current('admin.services.*'),
        icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.numbers')),
        href: route('admin.numbers.index'),
        active: route().current('admin.numbers.*'),
        icon: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.prefixes')),
        href: route('admin.prefixes.index'),
        active: route().current('admin.prefixes.*'),
        icon: 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.tariffs')),
        href: route('admin.tariffs.index'),
        active: route().current('admin.tariffs.*'),
        icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.orders')),
        href: route('admin.orders.index'),
        active: route().current('admin.orders.*') || route().current('admin.requests.*'),
        badge: computed(() => (page.props as any).pending_count || 0),
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.messages')),
        href: route('admin.messages.index'),
        active: route().current('admin.messages.*'),
        icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.calls')),
        href: route('admin.calls.index'),
        active: route().current('admin.calls.*'),
        icon: 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.payments')),
        href: route('admin.payments.index'),
        active: route().current('admin.payments.*'),
        icon: 'M13 10V3L4 14h7v7l9-11h-7z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.tickets')),
        href: route('admin.tickets.index'),
        active: route().current('admin.tickets.*'),
        icon: 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.audit_logs')),
        href: route('admin.audit-logs.index'),
        active: route().current('admin.audit-logs.*'),
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    },
    {
        name: computed(() => __('ui.layouts.admin.nav.settings_unified')),
        href: route('admin.settings.index'),
        active: route().current('admin.settings.*'),
        icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    },
];

const resolveLabel = (val: any): string => {
    if (typeof val === 'function') return val();
    if (val && typeof val === 'object' && 'value' in val) return val.value;
    return String(val ?? '');
};

const resolveBadge = (val: any): number => {
    if (!val) return 0;
    if (typeof val === 'function') return Number(val()) || 0;
    if (typeof val === 'object' && 'value' in val) return Number(val.value) || 0;
    return Number(val) || 0;
};

const activeNavItem = computed(() => {
    return adminNavigation.find((item) => item.active) || adminNavigation[0];
});

// Bottom dock items for mobile viewport (< lg)
const mobileBottomDockItems = computed(() => [
    adminNavigation[0], // Dashboard
    adminNavigation[1], // Users
    adminNavigation[5], // Orders
    adminNavigation[6], // Messages
]);
</script>

<template>
    <!-- Viewport bounded container: fixed desktop sidebar (lg+) with dedicated scrolling main area -->
    <div class="h-screen h-dvh max-h-screen w-full bg-slate-50/70 dark:bg-slate-950 text-slate-800 dark:text-slate-100 flex flex-col lg:flex-row overflow-hidden transition-colors duration-300">
        <!-- Automatic Notification Permission Request Modal -->
        <NotificationPermissionModal />

        <!-- Admin Desktop Sidebar (Modern Glassmorphic Style, Matching User & Org Panels) -->
        <aside class="hidden lg:flex flex-col w-72 h-screen max-h-screen sticky top-0 shrink-0 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl border-l border-slate-200/80 dark:border-slate-800/80 select-none z-20">
            <!-- Brand & Admin Header with Theme/Language Pill -->
            <div class="p-4 border-b border-slate-200/80 dark:border-slate-800/80 shrink-0">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-purple-600/30 font-black text-base shrink-0">
                            🛡️
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-black text-sm text-slate-900 dark:text-white truncate">{{ __('ui.layouts.admin.header.title') }}</span>
                                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 shrink-0">{{ __('ui.layouts.admin.header.badge') }}</span>
                            </div>
                            <span class="block text-[11px] text-slate-500 dark:text-slate-400 truncate">{{ __('ui.layouts.admin.header.subtitle') }}</span>
                        </div>
                    </div>

                    <!-- Top Controls: Language Switcher & Theme Toggle Capsule -->
                    <div class="flex items-center gap-1 p-0.5 rounded-xl bg-slate-100/90 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 shadow-xs shrink-0">
                        <LanguageSwitcher buttonClass="!h-7 !rounded-lg !px-1.5 !text-[11px]" />
                        <div class="w-px h-3 bg-slate-200 dark:bg-slate-700" aria-hidden="true"></div>
                        <ThemeSwitcher />
                    </div>
                </div>
            </div>

            <!-- Admin Navigation (Internal Smooth Scrollable) -->
            <nav class="flex-1 p-3.5 space-y-1 overflow-y-auto overscroll-contain">
                <Link
                    v-for="item in adminNavigation"
                    :key="resolveLabel(item.name)"
                    :href="item.href"
                    class="flex items-center justify-between gap-3 px-3.5 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-all"
                    :class="[
                        item.active
                            ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-md shadow-indigo-600/25 font-bold'
                            : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100/80 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white'
                    ]"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                        </svg>
                        <span class="truncate">{{ resolveLabel(item.name) }}</span>
                    </div>
                    <span
                        v-if="resolveBadge(item.badge) > 0"
                        class="px-2 py-0.5 rounded-full text-[10px] font-black bg-purple-500 text-white animate-pulse shrink-0"
                    >
                        {{ resolveBadge(item.badge) }}
                    </span>
                </Link>
            </nav>

            <!-- Bottom Return to User Area / Profile -->
            <div class="p-3.5 border-t border-slate-200/80 dark:border-slate-800/80 space-y-2 shrink-0">
                <Link
                    :href="route('dashboard')"
                    class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50/70 dark:bg-indigo-950/30 hover:bg-indigo-100 border border-indigo-200/60 dark:border-indigo-800/40 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>{{ __('ui.layouts.admin.header.switch_to_user') }}</span>
                </Link>

                <div class="p-3 rounded-2xl bg-slate-100/80 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/60 flex items-center justify-between">
                    <div class="flex items-center gap-2.5 truncate">
                        <div class="w-8 h-8 rounded-xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center font-bold text-xs shrink-0">
                            🛡️
                        </div>
                        <div class="truncate">
                            <p class="text-xs font-bold text-slate-900 dark:text-white truncate">{{ user?.name }}</p>
                            <p class="text-[10px] text-purple-600 dark:text-purple-400 font-medium">{{ __('ui.layouts.admin.header.super_admin') }}</p>
                        </div>
                    </div>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="p-1.5 text-slate-400 hover:text-rose-500 rounded-lg hover:bg-slate-200/60 dark:hover:bg-slate-700 transition-colors cursor-pointer"
                        :title="__('ui.layouts.admin.header.logout')"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </Link>
                </div>
            </div>
        </aside>

        <!-- Admin Responsive Slide-Over Sidebar Drawer (Mobile & Tablet < lg) -->
        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isMobileSidebarOpen" class="fixed inset-0 z-50 lg:hidden flex">
                <!-- Backdrop -->
                <div
                    class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm transition-opacity"
                    @click="isMobileSidebarOpen = false"
                ></div>

                <!-- Drawer Container -->
                <div class="relative w-80 max-w-[86vw] bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 h-full max-h-screen flex flex-col border-l border-slate-200 dark:border-slate-800 z-10 shadow-2xl">
                    <!-- Header & Admin Badge -->
                    <div class="h-16 flex items-center justify-between px-5 border-b border-slate-200 dark:border-slate-800 shrink-0">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-purple-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-purple-600/30">
                                🛡️
                            </div>
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-bold text-xs text-slate-900 dark:text-white">{{ __('ui.layouts.admin.header.title') }}</span>
                                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25">{{ __('ui.layouts.admin.header.badge') }}</span>
                                </div>
                                <span class="block text-[10px] text-slate-400">{{ __('ui.layouts.admin.header.subtitle') }}</span>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="isMobileSidebarOpen = false"
                            class="p-2 text-slate-400 hover:text-slate-800 dark:hover:text-white rounded-xl bg-slate-100 dark:bg-slate-800 transition-colors cursor-pointer"
                            :aria-label="__('ui.layouts.admin.header.close_menu')"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Admin Navigation Links with smooth scroll & badges -->
                    <nav class="flex-1 min-h-0 p-3.5 space-y-1 overflow-y-auto overscroll-contain">
                        <Link
                            v-for="item in adminNavigation"
                            :key="resolveLabel(item.name)"
                            :href="item.href"
                            @click="isMobileSidebarOpen = false"
                            class="flex items-center justify-between gap-3 px-3.5 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-all"
                            :class="[
                                item.active
                                    ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-md shadow-indigo-600/25 font-bold'
                                    : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white'
                            ]"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                                </svg>
                                <span class="truncate">{{ resolveLabel(item.name) }}</span>
                            </div>
                            <span
                                v-if="resolveBadge(item.badge) > 0"
                                class="px-2 py-0.5 rounded-full text-[10px] font-black bg-purple-500 text-white animate-pulse shrink-0"
                            >
                                {{ resolveBadge(item.badge) }}
                            </span>
                        </Link>
                    </nav>

                    <!-- Bottom Controls in Mobile Drawer -->
                    <div class="p-4 border-t border-slate-200 dark:border-slate-800 shrink-0 space-y-2">
                        <Link
                            :href="route('dashboard')"
                            @click="isMobileSidebarOpen = false"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            <span>{{ __('ui.layouts.admin.header.switch_to_user') }}</span>
                        </Link>

                        <div class="p-3 rounded-2xl bg-slate-100 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 flex items-center justify-between">
                            <div class="flex items-center gap-2.5 truncate">
                                <div class="w-8 h-8 rounded-xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center font-bold text-xs shrink-0">
                                    🛡️
                                </div>
                                <div class="truncate">
                                    <p class="text-xs font-bold text-slate-900 dark:text-white truncate">{{ user?.name }}</p>
                                    <p class="text-[10px] text-purple-600 dark:text-purple-400 font-medium">{{ __('ui.layouts.admin.header.super_admin') }}</p>
                                </div>
                            </div>
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="p-1.5 text-slate-400 hover:text-rose-500 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors cursor-pointer"
                                :title="__('ui.layouts.admin.header.logout')"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Main Content Area: Takes Remaining Width, Independent Scroll Area -->
        <div class="flex-1 flex flex-col min-w-0 h-screen h-dvh max-h-screen overflow-hidden">
            <!-- Top Admin Header -->
            <header class="min-h-16 shrink-0 bg-white/95 dark:bg-slate-900/95 backdrop-blur-md border-b border-slate-200/80 dark:border-slate-800/80 px-3 sm:px-6 py-2.5 flex items-center justify-between gap-2 sm:gap-3 sticky top-0 z-30 transition-colors">
                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                    <!-- Prominent Responsive Menu Button (< lg) -->
                    <button
                        type="button"
                        @click="isMobileSidebarOpen = true"
                        class="lg:hidden inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-purple-500/10 hover:bg-purple-500/20 dark:bg-purple-500/15 dark:hover:bg-purple-500/25 text-purple-600 dark:text-purple-400 border border-purple-500/30 font-extrabold text-xs transition-all shrink-0 cursor-pointer shadow-xs"
                        :aria-label="__('ui.layouts.admin.header.open_menu')"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <span>{{ __('ui.layouts.admin.header.menu_button') }}</span>
                    </button>

                    <!-- Page Header Slot or Automatic Active Section Title on Responsive Screens -->
                    <div class="min-w-0 flex-1">
                        <slot name="header">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-xs sm:text-sm font-black text-slate-800 dark:text-slate-100 truncate">
                                    {{ resolveLabel(activeNavItem?.name) }}
                                </span>
                                <span class="hidden sm:inline-block text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25">
                                    {{ __('ui.layouts.admin.header.badge') }}
                                </span>
                            </div>
                        </slot>
                    </div>
                </div>

                <div class="flex items-center gap-1.5 sm:gap-2.5 shrink-0">
                    <!-- Quick Switch to User Portal on Mobile/Tablet -->
                    <Link
                        :href="route('dashboard')"
                        class="lg:hidden inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 text-[11px] font-bold transition-colors"
                        :title="__('ui.layouts.admin.header.switch_to_user')"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span class="hidden sm:inline">{{ __('ui.layouts.admin.header.user_panel_short') }}</span>
                    </Link>

                    <div class="flex items-center gap-1 p-0.5 rounded-xl bg-slate-100/90 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 shadow-xs shrink-0">
                        <LanguageSwitcher buttonClass="!h-7 !rounded-lg !px-1.5 !text-[11px]" />
                        <div class="w-px h-3 bg-slate-200 dark:bg-slate-700" aria-hidden="true"></div>
                        <ThemeSwitcher />
                    </div>
                </div>
            </header>

            <!-- Admin Body (Smooth Independent Scrolling, standardized max-w-7xl mx-auto) -->
            <main class="flex-1 overflow-y-auto p-3.5 sm:p-6 lg:p-8 pb-24 lg:pb-8 overscroll-contain">
                <div class="max-w-7xl mx-auto w-full space-y-5">
                    <!-- Global Admin Breadcrumb Navigation -->
                    <div class="pb-1">
                        <Breadcrumb homeRoute="admin.dashboard" :homeLabel="__('ui.layouts.admin.nav.dashboard')" />
                    </div>

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

            <!-- Responsive Bottom Quick Navigation Bar (Visible on Mobile & Tablet < lg) -->
            <nav
                :aria-label="__('ui.layouts.admin.bottom_bar.aria_label')"
                class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl border-t border-slate-200 dark:border-slate-800 px-2 py-1.5 flex items-center justify-around shadow-2xl"
            >
                <Link
                    v-for="item in mobileBottomDockItems"
                    :key="'dock-' + resolveLabel(item.name)"
                    :href="item.href"
                    class="relative flex flex-col items-center justify-center gap-1 py-1 px-2.5 rounded-xl text-[10px] font-bold transition-all min-w-[60px]"
                    :class="item.active ? 'text-indigo-600 dark:text-indigo-400 bg-indigo-500/15' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span
                        v-if="resolveBadge(item.badge) > 0"
                        class="absolute top-0.5 right-2 px-1.5 py-0.2 rounded-full text-[9px] font-black bg-purple-500 text-white"
                    >
                        {{ resolveBadge(item.badge) }}
                    </span>
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                    </svg>
                    <span class="truncate max-w-[68px]">{{ resolveLabel(item.name) }}</span>
                </Link>

                <!-- Trigger slide drawer menu -->
                <button
                    type="button"
                    @click="isMobileSidebarOpen = true"
                    class="relative flex flex-col items-center justify-center gap-1 py-1 px-2.5 rounded-xl text-[10px] font-bold transition-all min-w-[60px] cursor-pointer text-slate-500 hover:text-slate-900 dark:hover:text-white"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <span>{{ __('ui.layouts.admin.header.menu_button') }}</span>
                </button>
            </nav>
        </div>
    </div>
</template>
