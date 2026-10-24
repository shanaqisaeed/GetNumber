<script setup lang="ts">
import { useTheme } from '@/Composables/useTheme';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const { toggleTheme } = useTheme();
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
        <header class="sticky top-0 z-40 backdrop-blur-xl bg-white/80 dark:bg-slate-950/75 border-b border-slate-200/80 dark:border-white/10 shadow-sm dark:shadow-none transition-colors duration-300">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
                <!-- Brand Link -->
                <Link href="/" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/25 group-hover:scale-105 transition-transform duration-300">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-black tracking-tight text-slate-900 dark:text-white">سامانه مدیریت خطوط</span>
                            <span class="glass-pill text-[10px] text-indigo-600 dark:text-indigo-400 font-bold hidden sm:inline-flex">راوی نو</span>
                        </div>
                    </div>
                </Link>

                <!-- Navigation Actions -->
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="app-theme-toggle-btn"
                        @click="toggleTheme"
                        title="تغییر تم تاریک / روشن"
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
                        class="glass-pill !py-2 !px-3.5 !text-xs font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors hidden sm:inline-flex items-center gap-1.5"
                    >
                        <svg class="w-3.5 h-3.5 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                        <span>مستندات API</span>
                    </Link>

                    <template v-if="user">
                        <Link
                            :href="route('dashboard')"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                        >
                            <span>ورود به پیشخوان</span>
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
                            ورود به سامانه
                        </Link>
                        <Link
                            :href="route('register')"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                        >
                            <span>ثبت‌نام متقاضی</span>
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <!-- Main Body -->
        <main class="flex-1 relative z-10">
            <slot />
        </main>

        <!-- Footer With Proper High-Contrast Light and Dark Styling -->
        <footer class="relative z-10 border-t border-slate-200/80 dark:border-white/10 py-8 backdrop-blur-xl bg-white/70 dark:bg-slate-950/70 transition-colors duration-300">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-600 dark:text-slate-400">
                <div class="flex items-center gap-2">
                    <span class="status-dot-pulse"></span>
                    <span>سامانه مدیریت خطوط و پیام‌ها • وضعیت سرویس‌ها: فعال و پایدار</span>
                </div>
                <div class="flex items-center gap-4">
                    <Link :href="route('api.docs')" class="hover:text-indigo-600 dark:hover:text-indigo-400 font-bold transition-colors">
                        مستندات وب‌سرویس API
                    </Link>
                    <span>•</span>
                    <span>© {{ new Date().getFullYear() }} سامانه مدیریت خطوط و پیام‌های سازمانی. کلیه حقوق محفوظ است.</span>
                </div>
            </div>
        </footer>
    </div>
</template>
