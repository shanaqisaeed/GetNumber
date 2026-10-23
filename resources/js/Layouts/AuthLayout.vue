<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="relative min-h-screen flex flex-col justify-center items-center py-12 px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-slate-950 transition-colors duration-300">
        <!-- Background Ambient Glow -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute -top-40 right-1/4 w-96 h-96 bg-indigo-500/10 dark:bg-indigo-500/20 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-40 left-1/4 w-96 h-96 bg-purple-500/10 dark:bg-purple-500/20 rounded-full blur-3xl"></div>
        </div>

        <!-- Top Navigation Bar for Auth -->
        <header class="absolute top-0 inset-x-0 p-6 flex justify-between items-center max-w-7xl mx-auto z-10">
            <Link href="/" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/25 group-hover:scale-105 transition-transform">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <span class="text-base font-bold text-slate-900 dark:text-white">سامانه مدیریت خطوط</span>
                    <span class="text-xs block text-slate-500 dark:text-slate-400">پرتال سازمانی</span>
                </div>
            </Link>

            <div class="flex items-center gap-3">
                <ThemeSwitcher />
            </div>
        </header>

        <!-- Auth Card Container -->
        <main class="w-full sm:max-w-md relative z-10">
            <div class="text-center mb-8">
                <h1 v-if="title" class="text-2xl font-black tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                    {{ title }}
                </h1>
                <p v-if="subtitle" class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                    {{ subtitle }}
                </p>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/80 dark:border-slate-800/80 rounded-3xl p-8 shadow-xl shadow-slate-200/50 dark:shadow-none">
                <slot />
            </div>

            <footer class="mt-8 text-center text-xs text-slate-500 dark:text-slate-500">
                © {{ new Date().getFullYear() }} سامانه مدیریت خطوط و پیام‌های سازمانی. کلیه حقوق محفوظ است.
            </footer>
        </main>
    </div>
</template>
