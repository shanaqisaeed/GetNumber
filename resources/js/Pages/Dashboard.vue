<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const stats = [
    { title: 'شماره‌های سازمانی فعال', count: '۰', icon: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z', color: 'text-indigo-600 dark:text-indigo-400', bg: 'bg-indigo-50 dark:bg-indigo-950/50' },
    { title: 'سرشماره‌های اختصاصی', count: '۰', icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10', color: 'text-purple-600 dark:text-purple-400', bg: 'bg-purple-50 dark:bg-purple-950/50' },
    { title: 'پیام‌های دریافتی', count: '۰', icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z', color: 'text-emerald-600 dark:text-emerald-400', bg: 'bg-emerald-50 dark:bg-emerald-950/50' },
    { title: 'درخواست‌های در حال بررسی', count: '۰', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', color: 'text-amber-600 dark:text-amber-400', bg: 'bg-amber-50 dark:bg-amber-950/50' },
];
</script>

<template>
    <Head title="پیشخوان کاربری" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white">پیشخوان کاربری</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">خلاصه وضعیت خطوط و پیام‌های سازمانی</p>
                </div>
            </div>
        </template>

        <!-- Stats Overview Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div
                v-for="stat in stats"
                :key="stat.title"
                class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between"
            >
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ stat.title }}</p>
                    <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stat.count }}</p>
                </div>
                <div :class="['w-12 h-12 rounded-2xl flex items-center justify-center', stat.bg, stat.color]">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="stat.icon" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Main Workspace Area -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left 2 Cols: Recent Messages Preview Area -->
            <div class="lg:col-span-2 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">پیام‌های دریافتی اخیر</h2>
                    <span class="text-xs text-slate-400">پایش لحظه‌ای فعال</span>
                </div>

                <div class="py-12 text-center text-slate-400 dark:text-slate-500">
                    <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>
                    <p class="text-sm font-medium">پیامی جهت نمایش ثبت نشده است</p>
                    <p class="text-xs mt-1">به محض دریافت پیام برای خطوط اختصاص‌یافته، جزئیات در این بخش درج خواهد شد.</p>
                </div>
            </div>

            <!-- Right 1 Col: Quick Actions -->
            <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm space-y-4">
                <h2 class="text-base font-bold text-slate-900 dark:text-white pb-3 border-b border-slate-100 dark:border-slate-800">
                    دسترسی سریع
                </h2>

                <div class="p-4 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40">
                    <h3 class="text-xs font-bold text-indigo-900 dark:text-indigo-300">درخواست خط سازمانی جدید</h3>
                    <p class="text-[11px] text-indigo-700/80 dark:text-indigo-400 mt-1 leading-relaxed">
                        تخصیص ساختاریافته شماره‌های ۹ رقمی سازمانی با پیشوند ۸۵+ بر اساس ضوابط سیستمی.
                    </p>
                </div>

                <div class="p-4 rounded-2xl bg-purple-50/50 dark:bg-purple-950/30 border border-purple-100 dark:border-purple-900/40">
                    <h3 class="text-xs font-bold text-purple-900 dark:text-purple-300">درخواست سرشماره اختصاصی</h3>
                    <p class="text-[11px] text-purple-700/80 dark:text-purple-400 mt-1 leading-relaxed">
                        ثبت درخواست بررسی و واگذاری سرشماره‌های اختصاصی ۴ تا ۸ رقمی سازمانی.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
