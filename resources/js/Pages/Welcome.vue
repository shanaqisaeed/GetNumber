<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
    laravelVersion?: string;
    phpVersion?: string;
}>();

const features = [
    {
        title: 'خطوط استاندارد سازمانی',
        description: 'تخصیص ساختاریافته و اتمیک شماره‌های ۹ رقمی با پیشوند ۸۵+ با رعایت یکتایی و بدون تداخل در واگذاری.',
        icon: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
        color: 'from-blue-500 to-indigo-600',
    },
    {
        title: 'سرشماره‌های اختصاصی سازمانی',
        description: 'ثبت درخواست سرشماره با طول ۴ تا ۸ رقم و گردش کار رسمی بررسی و تأیید توسط مدیریت سامانه.',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        color: 'from-purple-500 to-pink-600',
    },
    {
        title: 'گذرگاه امن پیام‌ها (API)',
        description: 'دریافت و پردازش پیام‌های سازمانی از طریق وب‌سرویس با تضمین یکتایی و جلوگیری از ثبت پیام تکراری.',
        icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
        color: 'from-emerald-500 to-teal-600',
    },
    {
        title: 'امنیت و کنترل سطوح دسترسی',
        description: 'مدیریت کلیدهای امنیتی معتبرسازی‌شده، تفکیک دسترسی‌ها و امکان ابطال آنی نشست‌ها و دسترسی‌ها.',
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        color: 'from-amber-500 to-orange-600',
    },
];
</script>

<template>
    <Head title="سامانه مدیریت خطوط و پیام‌های سازمانی" />

    <GuestLayout>
        <!-- Hero Section -->
        <section class="relative overflow-hidden py-16 sm:py-24">
            <!-- Background glow -->
            <div class="absolute inset-0 pointer-events-none overflow-hidden">
                <div class="absolute -top-32 right-1/3 w-[500px] h-[500px] bg-indigo-500/10 dark:bg-indigo-500/20 rounded-full blur-3xl"></div>
                <div class="absolute -bottom-32 left-1/3 w-[500px] h-[500px] bg-purple-500/10 dark:bg-purple-500/20 rounded-full blur-3xl"></div>
            </div>

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
                <!-- Badge -->
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60 mb-8 shadow-sm">
                    <span class="flex h-2 w-2 rounded-full bg-indigo-600 dark:bg-indigo-400"></span>
                    <span>زیرساخت اختصاصی و ایزوله سازمانی</span>
                </div>

                <!-- Main Title -->
                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight text-slate-900 dark:text-white max-w-4xl mx-auto leading-tight sm:leading-tight">
                    سامانه مدیریت شماره‌ها و پیام‌های سازمانی
                </h1>

                <!-- Subtitle -->
                <p class="mt-6 text-sm sm:text-base text-slate-600 dark:text-slate-400 max-w-2xl mx-auto leading-relaxed">
                    زیرساخت یکپارچه جهت تخصیص خطوط، مدیریت سرشماره‌های سازمانی، تبادل داده‌های درون‌شبکه‌ای و پایش دسترسی‌ها.
                </p>

                <!-- CTA Buttons -->
                <div class="mt-10 flex flex-wrap justify-center items-center gap-4">
                    <template v-if="$page.props.auth.user">
                        <Link
                            :href="route('dashboard')"
                            class="inline-flex items-center gap-2 px-6 py-3.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-600/50 transition-all hover:-translate-y-0.5"
                        >
                            <span>ورود به پیشخوان کاربری</span>
                            <svg class="w-5 h-5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('register')"
                            class="inline-flex items-center gap-2 px-6 py-3.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-600/50 transition-all hover:-translate-y-0.5"
                        >
                            <span>ثبت‌نام متقاضی</span>
                            <svg class="w-5 h-5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </Link>
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center px-6 py-3.5 rounded-2xl bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 font-bold border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 shadow-sm transition-all"
                        >
                            <span>ورود به سامانه</span>
                        </Link>
                    </template>
                </div>
            </div>
        </section>

        <!-- Features Grid -->
        <section class="py-16 sm:py-20 bg-slate-100/50 dark:bg-slate-900/30 border-y border-slate-200/60 dark:border-slate-800/60">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white">
                        قابلیت‌های کلیدی سامانه
                    </h2>
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">
                        طراحی امن و ساختاریافته متناسب با نیازمندی‌های شبکه‌های مستقل و ایزوله
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm hover:shadow-md transition-all duration-200 group"
                    >
                        <div class="flex items-start gap-4">
                            <div
                                class="w-12 h-12 rounded-2xl bg-gradient-to-tr text-white flex items-center justify-center shrink-0 shadow-md group-hover:scale-105 transition-transform"
                                :class="feature.color"
                            >
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="feature.icon" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2">
                                    {{ feature.title }}
                                </h3>
                                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                                    {{ feature.description }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </GuestLayout>
</template>
