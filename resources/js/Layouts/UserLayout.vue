<script setup lang="ts">
import { useRealtimeMessages, RealtimeMessagePayload } from '@/Composables/useRealtimeMessages';
import { useTheme } from '@/Composables/useTheme';
import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const page = usePage<PageProps>();
const user = computed(() => page.props.auth?.user);
const liveUnreadCount = ref<number>(page.props.auth?.unread_messages_count || 0);

watch(
    () => page.props.auth?.unread_messages_count,
    (val) => {
        if (typeof val === 'number') {
            liveUnreadCount.value = val;
        }
    }
);

const { isDark, toggleTheme } = useTheme();

// More Menu / Services Sheet state
const isMoreMenuOpen = ref(false);

// Realtime incoming toast banner
const toastNotification = ref<{ title: string; body: string; peer?: string; lineId?: number } | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showIncomingToast = (msg: RealtimeMessagePayload) => {
    toastNotification.value = {
        title: `پیامک جدید از ${msg.peer_number || msg.sender}`,
        body: msg.body.length > 55 ? msg.body.slice(0, 52) + '...' : msg.body,
        peer: msg.peer_number || msg.sender,
        lineId: msg.phone_number_id,
    };
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastNotification.value = null;
    }, 5000);
};

const { connectionLabel, connectionStatusDotClass } = useRealtimeMessages({
    userId: user.value?.id,
    isAdmin: !!user.value?.is_admin,
    onMessage: (msg) => {
        if (msg.direction === 'inbound') {
            liveUnreadCount.value += 1;
            if (typeof window !== 'undefined' && (window as any).route && !(window as any).route().current('messages.*')) {
                showIncomingToast(msg);
            }
        }
    },
    onUnreadUpdate: (newTotal) => {
        liveUnreadCount.value = newTotal;
    },
});

const navItems = computed(() => [
    {
        name: 'پیشخوان',
        route: 'dashboard',
        href: route('dashboard'),
        active: route().current('dashboard'),
        icon: 'home',
    },
    {
        name: 'خطوط من',
        route: 'numbers.*',
        href: route('numbers.index'),
        active: route().current('numbers.*'),
        icon: 'phone',
    },
    {
        name: 'پیامک‌ها',
        route: 'messages.*',
        href: route('messages.index'),
        active: route().current('messages.*'),
        icon: 'chat',
        badge: liveUnreadCount.value,
    },
    {
        name: 'درخواست‌ها',
        route: 'requests.*',
        href: route('requests.index'),
        active: route().current('requests.*'),
        icon: 'requests',
    },
    {
        name: 'پروفایل',
        route: 'profile.*',
        href: route('profile.edit'),
        active: route().current('profile.*') || route().current('api-tokens.*') || route().current('tickets.*'),
        icon: 'user',
    },
]);
</script>

<template>
    <div class="min-h-screen w-full flex flex-col items-center bg-[var(--bg-canvas)] relative transition-colors duration-300">
        <!-- Floating Live Message Toast -->
        <transition enter-active-class="transition ease-out duration-300" enter-from-class="-translate-y-8 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition ease-in duration-200" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-8 opacity-0">
            <div v-if="toastNotification" class="fixed top-4 left-1/2 -translate-x-1/2 z-50 w-[92%] max-w-[420px] p-3 rounded-2xl border border-indigo-500/40 shadow-2xl flex items-center justify-between gap-3 backdrop-blur-xl bg-slate-900/90 text-white">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-black truncate">{{ toastNotification.title }}</p>
                        <p class="text-[11px] text-slate-300 truncate">{{ toastNotification.body }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <Link
                        v-if="toastNotification.peer"
                        :href="route('messages.index', { phone_number_id: toastNotification.lineId, peer: toastNotification.peer })"
                        class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-[11px] font-bold text-white transition-all shadow-sm"
                        @click="toastNotification = null"
                    >
                        مشاهده
                    </Link>
                    <button @click="toastNotification = null" class="p-1 text-slate-400 hover:text-white text-xs">
                        ✕
                    </button>
                </div>
            </div>
        </transition>

        <!-- 3D Liquid Canvas Ambient Glows (Fixed in background) -->
        <div class="ambient-mesh">
            <div class="liquid-orb orb-1"></div>
            <div class="liquid-orb orb-2"></div>
            <div class="liquid-orb orb-3"></div>
        </div>

        <!-- Natural Flow Container (Same centered mobile width, but NOT inside an artificial restricted box) -->
        <div class="w-full max-w-[460px] min-h-screen flex flex-col relative z-10 px-3.5 sm:px-4">
            <!-- Sticky Top Header Bar -->
            <header class="sticky top-0 z-30 pt-3 pb-2.5 flex items-center justify-between border-b border-slate-200/80 dark:border-white/10 backdrop-blur-xl bg-[var(--bg-canvas)]/85 transition-colors">
                <!-- User Avatar & Status -->
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="relative shrink-0">
                        <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white font-extrabold text-sm shadow-md shadow-indigo-500/25">
                            {{ user?.name ? user.name.charAt(0) : 'U' }}
                        </div>
                        <span class="status-dot-pulse absolute -bottom-0.5 -right-0.5 border-2 border-[var(--bg-canvas)]"></span>
                    </div>

                    <div class="truncate">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs sm:text-sm font-extrabold text-[var(--text-primary)] truncate">
                                {{ user?.name || 'کاربر گرامی' }}
                            </span>
                            <span
                                class="w-2 h-2 rounded-full transition-all shrink-0 cursor-help"
                                :class="connectionStatusDotClass"
                                :title="connectionLabel"
                            ></span>
                        </div>
                        <div class="flex items-center gap-1 text-[10px] text-indigo-500 dark:text-indigo-400 font-semibold">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                            </svg>
                            <span>{{ user?.is_admin ? 'راهبر ارشد سیستم' : 'کاربر سازمانی فعال' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Header Actions (Direct Theme Toggle & More Services Drawer) -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <!-- Wallet Balance Pill -->
                    <Link
                        :href="route('wallet.index')"
                        class="px-2 py-1 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-600 dark:text-amber-400 flex items-center gap-1.5 hover:bg-amber-500/25 transition-all text-xs font-bold shadow-xs"
                        title="کیف پول و موجودی اعتباری"
                    >
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"></span>
                        <span class="font-mono font-black text-[11px]">{{ $page.props.auth?.wallet_balance || '0' }}</span>
                        <span class="text-[9px] font-extrabold text-amber-500">COIN</span>
                    </Link>

                    <!-- Admin Shortcut Button if Admin -->
                    <Link
                        v-if="user?.is_admin"
                        :href="route('admin.dashboard')"
                        class="w-9 h-9 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-500 flex items-center justify-center hover:bg-rose-500/25 transition-all"
                        title="ورود به پنل مدیریت"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </Link>

                    <!-- Direct Theme Switcher (Moon / Sun) -->
                    <button
                        type="button"
                        @click="toggleTheme"
                        class="app-theme-toggle-btn !w-9 !h-9 !rounded-xl"
                        :title="isDark ? 'تغییر به پوسته روشن' : 'تغییر به پوسته تیره'"
                        aria-label="تغییر پوسته تاریک و روشن"
                    >
                        <!-- Sun Icon (shown in dark mode) -->
                        <svg v-if="isDark" class="w-4 h-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                            <line x1="1" y1="12" x2="3" y2="12"></line>
                            <line x1="21" y1="12" x2="23" y2="12"></line>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                        </svg>
                        <!-- Moon Icon (shown in light mode) -->
                        <svg v-else class="w-4 h-4 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>

                    <!-- More Services Menu Trigger -->
                    <button
                        type="button"
                        @click="isMoreMenuOpen = !isMoreMenuOpen"
                        class="w-9 h-9 rounded-xl glass-card !p-0 flex items-center justify-center text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-indigo-500/40 transition-all"
                        title="منوی امکانات و خدمات"
                        aria-label="امکانات سامانه"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Natural Flowing Page Content (With generous pb-36 so content NEVER goes under the floating dock) -->
            <main class="flex-1 pt-3 pb-36 relative z-10">
                <!-- Global Flash Notifications -->
                <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                    <div v-if="$page.props.flash?.success" class="mb-3.5 p-3 rounded-2xl bg-emerald-500/15 border border-emerald-500/35 text-emerald-600 dark:text-emerald-300 text-xs font-bold flex items-center gap-2 backdrop-blur-md shadow-sm">
                        <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <span class="flex-1">{{ $page.props.flash.success }}</span>
                    </div>
                </transition>

                <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
                    <div v-if="$page.props.flash?.error" class="mb-3.5 p-3 rounded-2xl bg-rose-500/15 border border-rose-500/35 text-rose-600 dark:text-rose-300 text-xs font-bold flex items-center gap-2 backdrop-blur-md shadow-sm">
                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <span class="flex-1">{{ $page.props.flash.error }}</span>
                    </div>
                </transition>

                <!-- Page Header Slot if present -->
                <div v-if="$slots.header" class="mb-3.5">
                    <slot name="header" />
                </div>

                <!-- Page Specific Content -->
                <slot />

                <!-- Bottom spacer to guarantee total clearance above floating dock -->
                <div class="h-6" aria-hidden="true"></div>
            </main>
        </div>

        <!-- Floating Liquid Glass Bottom Dock Bar (Strictly centered directly below content items) -->
        <nav class="phone-dock-bar">
            <Link
                v-for="item in navItems"
                :key="item.name"
                :href="item.href"
                class="dock-item"
                :class="{ active: item.active }"
            >
                <!-- Unread Messages Badge -->
                <span v-if="item.badge && item.badge > 0" class="dock-badge">
                    {{ item.badge > 99 ? '+99' : item.badge }}
                </span>

                <!-- Icon: Home / Dashboard -->
                <svg v-if="item.icon === 'home'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>

                <!-- Icon: Phone / Numbers -->
                <svg v-else-if="item.icon === 'phone'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <rect x="5" y="2" width="14" height="20" rx="2" stroke-linecap="round" stroke-linejoin="round"></rect>
                    <line x1="12" y1="18" x2="12.01" y2="18" stroke-linecap="round" stroke-linejoin="round"></line>
                </svg>

                <!-- Icon: Messages -->
                <svg v-else-if="item.icon === 'chat'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>

                <!-- Icon: Requests -->
                <svg v-else-if="item.icon === 'requests'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>

                <!-- Icon: User / Profile -->
                <svg v-else-if="item.icon === 'user'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>

                <span>{{ item.name }}</span>
            </Link>
        </nav>

        <!-- More Services Slide-up Sheet (Overlay) -->
        <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="isMoreMenuOpen" class="fixed inset-0 bg-black/60 backdrop-blur-md z-50 flex flex-col justify-end items-center" @click.self="isMoreMenuOpen = false">
                <div class="glass-card w-full max-w-[460px] !rounded-b-none !rounded-t-[32px] p-5 pb-6 border-b-0 space-y-3.5 shadow-2xl animate-in slide-in-from-bottom duration-300">
                    <div class="flex items-center justify-between pb-3 border-b border-white/10 dark:border-white/10">
                        <span class="text-xs font-extrabold text-[var(--text-primary)] flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            خدمات و تنظیمات سامانه
                        </span>
                        <button @click="isMoreMenuOpen = false" class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] p-1">
                            ✕
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">
                        <Link
                            :href="route('wallet.index')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all col-span-2"
                        >
                            <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-500 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <rect x="2" y="5" width="20" height="14" rx="2" stroke-width="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" stroke-width="2" />
                                </svg>
                            </div>
                            <div class="flex-1 flex items-center justify-between">
                                <span>کیف پول و گردش حساب</span>
                                <span class="font-mono text-xs text-amber-500 font-black">{{ $page.props.auth?.wallet_balance || '0' }} COIN</span>
                            </div>
                        </Link>

                        <Link
                            :href="route('tickets.index')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all"
                        >
                            <div class="w-8 h-8 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </div>
                            <span>تیکت‌های پشتیبانی</span>
                        </Link>

                        <Link
                            :href="route('api-tokens.index')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all"
                        >
                            <div class="w-8 h-8 rounded-xl bg-cyan-500/20 text-cyan-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                </svg>
                            </div>
                            <span>کلیدهای API</span>
                        </Link>

                        <Link
                            :href="route('profile.edit')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all"
                        >
                            <div class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </div>
                            <span>تنظیمات پروفایل</span>
                        </Link>

                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/20 flex items-center gap-2.5 text-xs font-bold text-rose-500 transition-all text-right w-full"
                        >
                            <div class="w-8 h-8 rounded-xl bg-rose-500/20 text-rose-500 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                            </div>
                            <span>خروج از سامانه</span>
                        </Link>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>
