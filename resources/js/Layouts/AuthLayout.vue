<script setup lang="ts">
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="relative min-h-screen flex flex-col justify-between py-6 px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 transition-colors duration-300 overflow-x-hidden">
        <!-- 2026 Futuristic Background Lighting & Tech Grid Mesh -->
        <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
            <!-- Glow Orbs -->
            <div class="absolute -top-32 right-1/4 w-[500px] h-[500px] bg-gradient-to-tr from-indigo-600/15 via-purple-600/15 to-transparent rounded-full blur-[100px] animate-pulse"></div>
            <div class="absolute top-1/2 -left-32 w-[450px] h-[450px] bg-gradient-to-tr from-purple-600/15 via-pink-600/10 to-transparent rounded-full blur-[110px]"></div>
            <div class="absolute -bottom-32 right-10 w-[400px] h-[400px] bg-gradient-to-tr from-cyan-600/10 via-indigo-600/15 to-transparent rounded-full blur-[100px]"></div>
            <!-- Grid pattern -->
            <div class="absolute inset-0 bg-[radial-gradient(#4f46e512_1px,transparent_1px)] dark:bg-[radial-gradient(#6366f115_1px,transparent_1px)] [background-size:24px_24px]"></div>
        </div>

        <!-- Top Navigation Bar for Auth -->
        <header class="relative z-10 w-full max-w-6xl mx-auto flex justify-between items-center py-2">
            <Link href="/" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/25 group-hover:scale-105 group-hover:shadow-indigo-500/40 transition-all duration-300">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-base font-black tracking-tight text-slate-900 dark:text-white">سامانه مدیریت خطوط</span>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-md bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">۲۰۲۶</span>
                    </div>
                    <span class="text-[11px] block text-slate-500 dark:text-slate-400">پرتال سازمانی خطوط و پیام‌ها</span>
                </div>
            </Link>

            <div class="flex items-center gap-3">
                <ThemeSwitcher />
            </div>
        </header>

        <!-- Main Auth Dual Showcase / Bento Split Layout (2026 Style) -->
        <main class="relative z-10 w-full max-w-6xl mx-auto my-auto py-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-center">
                <!-- Right Side (Lg screen Feature Showcase) -->
                <div class="hidden lg:flex lg:col-span-6 flex-col justify-center space-y-6">
                    <!-- Brand Pill -->
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 text-xs font-bold w-fit">
                        <span class="w-2 h-2 rounded-full bg-indigo-500 animate-ping"></span>
                        <span>نسل جدید درگاه ارتباطی هوشمند</span>
                    </div>

                    <div>
                        <h2 class="text-3xl xl:text-4xl font-black text-slate-900 dark:text-white tracking-tight leading-tight">
                            مدیریت یکپارچه خطوط سازمانی و پیام‌های شبکه
                        </h2>
                        <p class="mt-3 text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                            پلتفرم جامع تخصیص سرشماره‌های اختصاصی با پیشوند ۸۵+ و مانیتورینگ متمرکز پیام‌های دریافتی با امنیت Zero-Trust.
                        </p>
                    </div>

                    <!-- 3 High-Tech Value Chips -->
                    <div class="space-y-3 pt-2">
                        <div class="p-3.5 rounded-2xl bg-white/70 dark:bg-slate-900/60 backdrop-blur-md border border-slate-200/80 dark:border-slate-800/80 flex items-center gap-3 shadow-sm hover:border-indigo-500/40 transition-colors">
                            <div class="w-9 h-9 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">شماره‌های ۹ رقمی سازمانی</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">تخصیص ساختاریافته با ضوابط خودکار سیستمی</p>
                            </div>
                        </div>

                        <div class="p-3.5 rounded-2xl bg-white/70 dark:bg-slate-900/60 backdrop-blur-md border border-slate-200/80 dark:border-slate-800/80 flex items-center gap-3 shadow-sm hover:border-purple-500/40 transition-colors">
                            <div class="w-9 h-9 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">پایش بلادرنگ و امنیت Zero-Trust</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">ثبت وقایع، احراز هویت قوی و رمزنگاری داده‌ها</p>
                            </div>
                        </div>
                    </div>

                    <!-- Live System Status Widget -->
                    <div class="px-4 py-3 rounded-2xl bg-slate-900/80 dark:bg-slate-950/80 border border-slate-800 text-slate-300 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span class="font-medium">پایداری شبکه: ۹۹.۹۹٪ (کلیه درگاه‌ها آنلاین)</span>
                        </div>
                        <span class="font-mono text-[10px] text-indigo-400">TLS 1.3 Active</span>
                    </div>
                </div>

                <!-- Left Side: Interactive Glass Card Container -->
                <div class="w-full lg:col-span-6 flex justify-center">
                    <div class="w-full sm:max-w-md bg-white/85 dark:bg-slate-900/85 backdrop-blur-2xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 sm:p-9 shadow-2xl shadow-indigo-950/10 dark:shadow-none transition-all">
                        <!-- Card Header -->
                        <div class="text-center mb-6">
                            <h1 v-if="title" class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900 dark:text-white">
                                {{ title }}
                            </h1>
                            <p v-if="subtitle" class="mt-2 text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                                {{ subtitle }}
                            </p>
                        </div>

                        <!-- Card Body (Login / Register Form) -->
                        <slot />
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="relative z-10 w-full max-w-6xl mx-auto py-3 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 dark:text-slate-500 gap-2">
            <span>© ۲۰۲۶ سامانه مدیریت خطوط و پیام‌های سازمانی. کلیه حقوق محفوظ است.</span>
            <span class="font-mono text-[11px] text-slate-400">نسخه ۲.۴ سازمانی</span>
        </footer>
    </div>
</template>
