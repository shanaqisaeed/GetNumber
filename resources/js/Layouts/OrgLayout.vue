<script setup lang="ts">
import { __ } from '@/Composables/useTrans';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import PwaInstallBanner from '@/Components/PwaInstallBanner.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const organization = computed(() => (page.props as any).organization || null);

const isMobileSidebarOpen = ref(false);
const isDark = ref(false);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark') ||
        localStorage.getItem('theme') === 'dark' ||
        (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (isDark.value) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
});

const toggleTheme = () => {
    isDark.value = !isDark.value;
    if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
};

const navigation = [
    {
        name: computed(() => __('organization.nav.dashboard')),
        href: route('org.dashboard'),
        active: route().current('org.dashboard'),
        icon: 'dashboard',
    },
    {
        name: computed(() => __('organization.nav.team')),
        href: route('org.team.index'),
        active: route().current('org.team.*'),
        icon: 'team',
    },
    {
        name: computed(() => __('organization.nav.lines')),
        href: route('org.lines.index'),
        active: route().current('org.lines.*'),
        icon: 'lines',
    },
    {
        name: computed(() => __('organization.nav.requests')),
        href: route('org.requests.index'),
        active: route().current('org.requests.*'),
        icon: 'requests',
    },
    {
        name: computed(() => __('organization.nav.bulk_sms')),
        href: route('org.bulk_sms.index'),
        active: route().current('org.bulk_sms.*'),
        icon: 'bulk_sms',
    },
    {
        name: computed(() => __('organization.nav.contacts')),
        href: route('org.contacts.index'),
        active: route().current('org.contacts.*'),
        icon: 'contacts',
    },
    {
        name: computed(() => __('organization.nav.wallet')),
        href: route('org.wallet.index'),
        active: route().current('org.wallet.*'),
        icon: 'wallet',
    },
    {
        name: computed(() => __('organization.nav.api_tokens')),
        href: route('org.api-tokens.index'),
        active: route().current('org.api-tokens.*'),
        icon: 'api_tokens',
    },
    {
        name: computed(() => __('organization.nav.docs')),
        href: route('org.docs'),
        active: route().current('org.docs'),
        icon: 'docs',
    },
];
</script>

<template>
    <div class="h-screen overflow-hidden bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 flex flex-col md:flex-row transition-colors duration-200">
        <!-- Mobile Header Bar -->
        <header class="md:hidden shrink-0 sticky top-0 z-40 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 px-4 py-3 flex items-center justify-between">
            <button
                type="button"
                @click="isMobileSidebarOpen = !isMobileSidebarOpen"
                class="p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800"
            >
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-purple-600"></span>
                <span class="text-sm font-black">{{ organization?.name || __('organization.dashboard.title') }}</span>
            </div>

            <Link
                :href="route('dashboard')"
                class="px-2.5 py-1.5 rounded-xl bg-indigo-600 text-white text-xs font-bold shadow-sm"
            >
                {{ __('organization.nav.switch_to_softphone') }}
            </Link>
        </header>

        <!-- Sidebar (Desktop & Mobile Drawer) -->
        <aside
            :class="[
                'fixed inset-y-0 right-0 md:static z-50 w-72 h-screen shrink-0 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl border-l border-slate-200 dark:border-slate-800 flex flex-col justify-between transition-transform duration-300 md:translate-x-0 overflow-hidden',
                isMobileSidebarOpen ? 'translate-x-0' : 'translate-x-full'
            ]"
        >
            <div class="flex-1 min-h-0 flex flex-col">
                <!-- Brand & Organization Title with Language & Theme Switches -->
                <div class="p-3.5 border-b border-slate-200/80 dark:border-slate-800/80 shrink-0">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                            <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-purple-600/30 font-black text-lg shrink-0">
                                🏢
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-xs font-black truncate text-slate-900 dark:text-white" :title="organization?.name || __('organization.dashboard.title')">
                                    {{ organization?.name || __('organization.dashboard.title') }}
                                </h2>
                                <p class="text-[10px] text-purple-600 dark:text-purple-400 font-bold truncate">
                                    {{ __('organization.nav.dashboard') }}
                                </p>
                            </div>
                        </div>

                        <!-- Top Controls: Language Switcher & Theme Toggle Capsule -->
                        <div class="flex items-center gap-1 p-0.5 rounded-xl bg-slate-100/90 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 shadow-xs shrink-0">
                            <LanguageSwitcher buttonClass="!h-7 !rounded-lg !px-1.5 !text-[11px]" />
                            <div class="w-px h-3 bg-slate-200 dark:bg-slate-700" aria-hidden="true"></div>
                            <button
                                type="button"
                                @click="toggleTheme"
                                class="w-7 h-7 rounded-lg flex items-center justify-center text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white hover:bg-slate-200/60 dark:hover:bg-slate-700 transition-colors"
                            >
                                <svg v-if="isDark" class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="5"></circle>
                                    <line x1="12" y1="1" x2="12" y2="3"></line>
                                    <line x1="12" y1="21" x2="12" y2="23"></line>
                                </svg>
                                <svg v-else class="w-3.5 h-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                                </svg>
                            </button>
                        </div>

                        <button
                            type="button"
                            @click="isMobileSidebarOpen = false"
                            class="md:hidden p-1 text-slate-400 hover:text-slate-600 dark:hover:text-white rounded-lg shrink-0"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <!-- Navigation List -->
                <nav class="p-3.5 space-y-1 overflow-y-auto flex-1 min-h-0">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-bold transition-all"
                        :class="[
                            item.active
                                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/25'
                                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800/60'
                        ]"
                        @click="isMobileSidebarOpen = false"
                    >
                        <span class="w-5 h-5 flex items-center justify-center shrink-0">
                            <!-- Dashboard Icon -->
                            <svg v-if="item.icon === 'dashboard'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                            </svg>
                            <!-- Team Icon -->
                            <svg v-else-if="item.icon === 'team'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <!-- Lines Icon -->
                            <svg v-else-if="item.icon === 'lines'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <!-- Requests Icon -->
                            <svg v-else-if="item.icon === 'requests'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                            </svg>
                            <!-- Bulk SMS Icon -->
                            <svg v-else-if="item.icon === 'bulk_sms'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                            <!-- Contacts Icon -->
                            <svg v-else-if="item.icon === 'contacts'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <!-- Wallet Icon -->
                            <svg v-else-if="item.icon === 'wallet'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                            <!-- API Tokens Icon -->
                            <svg v-else-if="item.icon === 'api_tokens'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                            <!-- Docs Icon -->
                            <svg v-else-if="item.icon === 'docs'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </span>
                        <span>{{ typeof item.name === 'object' && 'value' in item.name ? item.name.value : item.name }}</span>
                    </Link>
                </nav>
            </div>

            <!-- Sidebar Bottom: Switch to Softphone & User Info -->
            <div class="p-3.5 border-t border-slate-200/80 dark:border-slate-800/80 space-y-2 shrink-0">
                <!-- Admin Dashboard Link (For Admins & Superadmins) -->
                <div v-if="$page.props.auth?.user?.is_admin">
                    <Link
                        :href="route('admin.dashboard')"
                        class="w-full py-2.5 px-3 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 border border-amber-500/30 text-xs font-bold flex items-center justify-between gap-2 transition-all active:scale-95 shadow-xs"
                    >
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                            <span>{{ __('ui.layouts.user.nav.switch_to_admin') }}</span>
                        </div>
                        <span class="text-[10px]">⚙️</span>
                    </Link>
                </div>

                <Link
                    :href="route('dashboard')"
                    class="w-full px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs font-black shadow-md shadow-indigo-600/20 flex items-center justify-center gap-2 transition-all active:scale-95"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                    </svg>
                    <span>{{ __('organization.nav.switch_to_softphone') }}</span>
                </Link>

                <div class="flex items-center gap-2.5 pt-0.5">
                    <div class="w-8 h-8 rounded-xl bg-slate-200 dark:bg-slate-800 flex items-center justify-center font-bold text-xs text-slate-700 dark:text-slate-300 shrink-0">
                        {{ user?.name ? user.name.charAt(0) : 'U' }}
                    </div>
                    <div class="min-w-0 flex-1 truncate">
                        <p class="text-xs font-extrabold text-slate-800 dark:text-slate-200 truncate">{{ user?.name }}</p>
                        <p class="text-[10px] text-slate-400 truncate">{{ user?.email }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Backdrop for mobile drawer -->
        <div
            v-if="isMobileSidebarOpen"
            @click="isMobileSidebarOpen = false"
            class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm md:hidden"
        ></div>

        <!-- Main Body Area -->
        <main class="flex-1 min-w-0 h-screen overflow-y-auto p-4 sm:p-6 lg:p-8">
            <PwaInstallBanner />
            <slot />
        </main>
    </div>
</template>
