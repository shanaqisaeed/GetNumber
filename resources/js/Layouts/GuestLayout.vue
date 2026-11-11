<script setup lang="ts">
import { useTheme } from '@/Composables/useTheme';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const { toggleTheme } = useTheme();

const isMobileMenuOpen = ref(false);
</script>

<template>
    <div class="min-h-screen flex flex-col relative overflow-x-hidden selection:bg-indigo-600 selection:text-white transition-colors duration-300">
        <!-- 3D Liquid Canvas Ambient Glows -->
        <div class="ambient-mesh" aria-hidden="true">
            <div class="liquid-orb orb-1"></div>
            <div class="liquid-orb orb-2"></div>
            <div class="liquid-orb orb-3"></div>
        </div>

        <!-- Sticky Header with Responsive Glass Blur in Both Themes -->
        <header class="sticky top-0 z-40 backdrop-blur-xl bg-white/85 dark:bg-slate-950/80 border-b border-slate-200/80 dark:border-white/10 shadow-sm dark:shadow-none transition-colors duration-300">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
                <!-- Brand Link -->
                <Link href="/" class="flex items-center gap-3 group shrink-0">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/25 group-hover:scale-105 transition-transform duration-300">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm sm:text-base font-black tracking-tight text-slate-900 dark:text-white">{{ __('ui.layouts.guest.brand_title') }}</span>
                            <span class="glass-pill text-[10px] text-indigo-600 dark:text-indigo-400 font-bold hidden sm:inline-flex">{{ __('ui.layouts.guest.brand_badge') }}</span>
                        </div>
                    </div>
                </Link>

                <!-- Desktop Navigation Actions (hidden on mobile) -->
                <div class="hidden md:flex items-center gap-3">
                    <button
                        type="button"
                        class="app-theme-toggle-btn"
                        @click="toggleTheme"
                        :title="__('ui.layouts.guest.theme_toggle')"
                    >
                        <svg class="theme-icon-sun w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                        <svg class="theme-icon-moon w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>

                    <Link
                        :href="route('api.docs')"
                        class="glass-pill !py-2 !px-3.5 !text-xs font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors inline-flex items-center gap-1.5"
                    >
                        <svg class="w-3.5 h-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                        <span>{{ __('ui.layouts.guest.api_docs') }}</span>
                    </Link>

                    <template v-if="user">
                        <Link
                            :href="route('dashboard')"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                        >
                            <span>{{ __('ui.layouts.guest.dashboard') }}</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="glass-pill !py-2 !px-3.5 !text-xs font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                        >
                            {{ __('ui.layouts.guest.login') }}
                        </Link>
                        <Link
                            :href="route('register')"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                        >
                            <span>{{ __('ui.layouts.guest.register') }}</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                    </template>
                </div>

                <!-- Mobile Actions (Theme Toggle + Hamburger Menu Button) -->
                <div class="flex md:hidden items-center gap-2">
                    <button
                        type="button"
                        class="app-theme-toggle-btn !w-9 !h-9"
                        @click="toggleTheme"
                        :title="__('ui.layouts.guest.theme_toggle')"
                    >
                        <svg class="theme-icon-sun w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                        <svg class="theme-icon-moon w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>

                    <!-- Hamburger Toggle Button -->
                    <button
                        type="button"
                        @click="isMobileMenuOpen = !isMobileMenuOpen"
                        class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 flex items-center justify-center transition-colors cursor-pointer"
                        :aria-label="__('ui.layouts.guest.menu_aria')"
                    >
                        <svg v-if="!isMobileMenuOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg v-else class="w-5 h-5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile Slide-Down Dropdown Menu -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="-translate-y-4 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="-translate-y-4 opacity-0"
            >
                <div
                    v-if="isMobileMenuOpen"
                    class="md:hidden border-t border-slate-200 dark:border-white/10 bg-white/95 dark:bg-slate-900/95 backdrop-blur-2xl p-4 space-y-3 shadow-xl"
                >
                    <Link
                        :href="route('api.docs')"
                        @click="isMobileMenuOpen = false"
                        class="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/60 text-xs font-bold text-slate-800 dark:text-slate-200"
                    >
                        <span class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                            </svg>
                            <span>{{ __('ui.layouts.guest.api_docs_full') }}</span>
                        </span>
                        <span class="text-[10px] text-indigo-500 font-mono">REST</span>
                    </Link>

                    <div v-if="user" class="pt-1">
                        <Link
                            :href="route('dashboard')"
                            @click="isMobileMenuOpen = false"
                            class="glass-btn-primary !w-full !py-3 !text-xs !rounded-xl flex items-center justify-center gap-2"
                        >
                            <span>{{ __('ui.layouts.guest.dashboard_user') }}</span>
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                    </div>
                    <div v-else class="grid grid-cols-2 gap-2 pt-1">
                        <Link
                            :href="route('login')"
                            @click="isMobileMenuOpen = false"
                            class="py-2.5 px-3 rounded-xl text-center text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700 hover:bg-slate-200 transition-colors"
                        >
                            {{ __('ui.layouts.guest.login') }}
                        </Link>
                        <Link
                            :href="route('register')"
                            @click="isMobileMenuOpen = false"
                            class="glass-btn-primary !w-full !py-2.5 !px-3 !text-xs !rounded-xl flex items-center justify-center gap-1.5"
                        >
                            <span>{{ __('ui.layouts.guest.register') }}</span>
                        </Link>
                    </div>
                </div>
            </transition>
        </header>

        <!-- Main Body -->
        <main class="flex-1 relative z-10">
            <slot />
        </main>

        <!-- Structured Operational Footer -->
        <footer class="mt-auto border-t border-slate-200/80 dark:border-white/10 bg-white/60 dark:bg-slate-950/60 backdrop-blur-md py-8 px-4 text-center text-xs text-slate-500 dark:text-slate-400 relative z-10">
            <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('ui.layouts.guest.footer_desc') }}</span>
                </div>
                <div class="flex items-center gap-4 text-xs font-medium">
                    <Link :href="route('api.docs')" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ __('ui.layouts.guest.api_docs') }}</Link>
                    <span>•</span>
                    <Link :href="route('login')" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ __('ui.layouts.guest.login') }}</Link>
                    <span>•</span>
                    <Link :href="route('register')" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ __('ui.layouts.guest.register') }}</Link>
                </div>
                <div class="text-[11px] text-slate-400">
                    &copy; {{ new Date().getFullYear() }} {{ __('ui.layouts.guest.copyright') }}
                </div>
            </div>
        </footer>
    </div>
</template>
