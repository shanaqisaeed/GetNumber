<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
    laravelVersion?: string;
    phpVersion?: string;
}>();

// FAQ accordion state
const openFaq = ref<number | null>(0);

const toggleFaq = (index: number) => {
    openFaq.value = openFaq.value === index ? null : index;
};

// 4 Architectural Core Pillars
const corePillars = [
    {
        title: 'خطوط استاندارد سازمانی',
        badge: 'تخصیص خودکار و اتمیک',
        description: 'تخصیص ساختاریافته شماره‌های ۹ رقمی از استخر خطوط فعال. با حفظ صفرهای اول و تضمین عدم تداخل یا واگذاری تکراری حتی تحت درخواست‌های همزمان.',
        icon: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
        gradient: 'from-blue-600 to-indigo-600',
    },
    {
        title: 'سرشماره‌های اختصاصی سازمانی',
        badge: 'طول انتخابی ۴ تا ۸ رقم',
        description: 'گردش کار رسمی ثبت درخواست خطوط کوتاه و رند (شامل تمام طول‌های ۴، ۵، ۶، ۷ و ۸ رقم)، بررسی توسط مدیریت سامانه و تخصیص اتمیک پس از تأیید.',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        gradient: 'from-purple-600 to-pink-600',
    },
    {
        title: 'گذرگاه وب‌سرویس RESTful API',
        badge: 'ثبت امن و Idempotent',
        description: 'امکان ثبت پیام‌های ورودی از طریق API با کلیدهای امنیتی هش‌شده SHA-256، اعتبارسنجی پیش‌شماره کامل و جلوگیری از پیام تکراری با کلید یکتای مبدأ.',
        icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
        gradient: 'from-emerald-600 to-teal-600',
    },
    {
        title: 'میز خدمت و تیکتینگ یکپارچه',
        badge: 'پشتیبانی چندطرفه و پیوست‌ها',
        description: 'سیستم ثبت تیکت پشتیبانی متصل به خطوط کاربر، با امکان الصاق فایل‌های مستندات تا ۵ مگابایت در فضای خصوصی محافظت‌شده سرور.',
        icon: 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z',
        gradient: 'from-amber-600 to-orange-600',
    },
];

// Workflow Steps
const workflowSteps = [
    {
        step: '۰۱',
        title: 'افتتاح حساب کاربری',
        desc: 'ثبت‌نام سریع با نام کاربری دلخواه و کلمه عبور امن بدون نیاز به پیچیدگی‌های اولیه.',
    },
    {
        step: '۰۲',
        title: 'دریافت یا درخواست خط',
        desc: 'تخصیص آنی شماره استاندارد ۹ رقمی یا ارسال درخواست سرشماره سازمانی ۴ تا ۸ رقم به مدیریت.',
    },
    {
        step: '۰۳',
        title: 'صدور کلید وب‌سرویس (API)',
        desc: 'دریافت توکن اختصاصی امن جهت اتصال سامانه‌های نرم‌افزاری و ثبت پیام‌های دریافتی.',
    },
    {
        step: '۰۴',
        title: 'پایش و مشاهده بلادرنگ',
        desc: 'مشاهده مکالمات، تفکیک بر اساس خطوط و شماره فرستنده در رابط کاربری شبیه گوشی.',
    },
];

// Operational Metrics
const metrics = [
    { value: '< ۵۰ ms', label: 'میانگین پاسخگویی API', desc: 'سرعت بالا در ثبت و استعلام پیام‌ها' },
    { value: '۱۰۰٪ اتمیک', label: 'تضمین یکتایی خطوط', desc: 'قفل‌گذاری تراکنشی در زمان تخصیص' },
    { value: '۴ تا ۸ رقم', label: 'تنوع طول خطوط سازمانی', desc: 'پوشش کلیه سطوح سرشماره‌های شرکتی' },
    { value: 'SHA-256', label: 'رمزنگاری کلیدهای API', desc: 'عدم نگهداری توکن‌های خام در دیتابیس' },
];

// Frequently Asked Questions
const faqs = [
    {
        q: 'ماهیت پیام‌های ثبت‌شده در این سامانه چیست؟',
        a: 'این سامانه به عنوان یک زیرساخت نرم‌افزاری داخلی عمل می‌کند که پیام‌های ورودی را از طریق وب‌سرویس API در پایگاه‌داده ثبت نموده و در پنل کاربران نمایش می‌دهد. سیستم در این مرحله پیامک واقعی از طریق اپراتورهای مخابراتی به تلفن همراه ارسال نمی‌کند.',
    },
    {
        q: 'تفاوت خط استاندارد با سرشماره‌های اختصاصی سازمانی چیست؟',
        a: 'خطوط استاندارد شماره‌های ۹ رقمی هستند که با پیش‌شماره معتبر سیستم به صورت خودکار و اتمیک به کاربر تخصیص می‌یابند. سرشماره‌های سازمانی دارای طول دلخواه ۴، ۵، ۶، ۷ یا ۸ رقم هستند که پس از ثبت درخواست و تأیید مدیریت سامانه فعال می‌شوند.',
    },
    {
        q: 'چگونه از ثبت پیام تکراری توسط سامانه‌های متصل جلوگیری می‌شود؟',
        a: 'سامانه با بهره‌گیری از شناسه یکتای پیام مبدأ (client_message_id) و شناسه کلاینت، درخواست‌های تکراری را شناسایی کرده و بدون ثبت رکورد مضاعف در دیتابیس، پاسخ معتبر Idempotent بازمی‌گرداند.',
    },
    {
        q: 'در زمان آزادسازی خط، اطلاعات و پیام‌های قبلی چه سرنوشتی خواهند داشت؟',
        a: 'با آزادسازی خط توسط کاربر یا مدیر، تمامی پیام‌های مربوط به دوره مالکیت قبلی به صورت قطعی و امن در یک تراکنش دیتابیس پاکسازی می‌شوند تا مالک جدید بعدی خط به هیچ عنوان به پیام‌های مالک پیشین دسترسی نداشته باشد.',
    },
];
</script>

<template>
    <Head title="سامانه مدیریت خطوط، سرشماره‌ها و پیام‌های داخلی" />

    <GuestLayout>
        <!-- 1. Hero Section -->
        <section class="relative pt-12 sm:pt-20 pb-16 px-4 sm:px-6 max-w-6xl mx-auto">
            <div class="text-center max-w-3xl mx-auto">
                <!-- Status Badge -->
                <div class="inline-flex items-center gap-2 mb-6">
                    <span class="glass-pill text-indigo-600 dark:text-indigo-400 font-bold text-xs">
                        <span class="status-dot-pulse"></span>
                        زیرساخت متمرکز مدیریت خطوط سازمانی و پیام‌ها
                    </span>
                </div>

                <!-- Main Hero Headline -->
                <h1 class="text-3xl sm:text-5xl font-black text-slate-900 dark:text-white tracking-tight leading-tight sm:leading-tight">
                    پلتفرم هوشمند مدیریت خطوط،
                    <span class="block mt-2 bg-gradient-to-l from-indigo-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
                        سرشماره‌های سازمانی و جریان پیام‌ها
                    </span>
                </h1>

                <!-- Hero Subtitle -->
                <p class="mt-6 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed max-w-2xl mx-auto font-medium">
                    سامانه تخصصی جهت تخصیص خودکار شماره‌ها، بررسی و تأیید درخواست خطوط ۴ تا ۸ رقمی، دریافت و ثبت امن پیام‌ها از طریق وب‌سرویس و مدیریت یکپارچه گفتگوها.
                </p>

                <!-- Dual CTA Action Buttons -->
                <div class="mt-8 flex flex-wrap justify-center items-center gap-4">
                    <template v-if="$page.props.auth?.user">
                        <Link
                            :href="route('dashboard')"
                            class="glass-btn-primary !w-auto !py-3.5 !px-8 !text-sm !rounded-2xl"
                        >
                            <span>ورود به پیشخوان کاربری</span>
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('register')"
                            class="glass-btn-primary !w-auto !py-3.5 !px-8 !text-sm !rounded-2xl"
                        >
                            <span>ثبت‌نام متقاضی جدید</span>
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                        <Link
                            :href="route('login')"
                            class="glass-pill !py-3.5 !px-7 !text-sm font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400"
                        >
                            <span>ورود به درگاه</span>
                        </Link>
                    </template>
                </div>

                <!-- Technical Scope Clarification Notice (Required Policy) -->
                <div class="mt-10 p-4 rounded-2xl glass-card text-right flex items-start gap-3.5 border-indigo-500/20 max-w-2xl mx-auto">
                    <div class="w-9 h-9 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                    </div>
                    <div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        <b class="text-slate-900 dark:text-white block mb-1">شفاف‌سازی فنی درباره ماهیت پیام‌ها:</b>
                        این سامانه پیام‌های دریافتی از سامانه‌های مجاز را از طریق وب‌سرویس (API) در پایگاه‌داده ثبت نموده و در پنل کاربران نمایش می‌دهد. سیستم در این مرحله پیامک واقعی مخابراتی به شبکه تلفن همراه ارسال نمی‌کند.
                    </div>
                </div>
            </div>
        </section>

        <!-- 3. Core Architectural Pillars Bento Grid -->
        <section class="py-16 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="text-center mb-12">
                <span class="glass-pill text-xs font-bold text-indigo-600 dark:text-indigo-400 mb-2">معماری و قابلیت‌های محوری</span>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-2">
                    زیرساخت قدرتمند، توسعه‌پذیر و امن
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2 max-w-xl mx-auto">
                    ارائه امکانات جامع متناسب با نیازهای مدیریت خطوط، شبکه‌های ایزوله و ارتباطات سازمانی
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div
                    v-for="pillar in corePillars"
                    :key="pillar.title"
                    class="glass-card group flex flex-col justify-between"
                >
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <div
                                class="w-12 h-12 rounded-2xl bg-gradient-to-tr text-white flex items-center justify-center shadow-lg shadow-indigo-500/20 group-hover:scale-105 transition-transform"
                                :class="pillar.gradient"
                            >
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="pillar.icon" />
                                </svg>
                            </div>
                            <span class="glass-pill text-[11px] font-bold text-slate-700 dark:text-slate-300">
                                {{ pillar.badge }}
                            </span>
                        </div>

                        <h3 class="text-base font-bold text-slate-900 dark:text-white mb-2">
                            {{ pillar.title }}
                        </h3>
                        <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 leading-relaxed font-normal">
                            {{ pillar.description }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Step-by-Step Workflow -->
        <section class="py-16 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="text-center mb-12">
                <span class="glass-pill text-xs font-bold text-indigo-600 dark:text-indigo-400 mb-2">فرآیند عملیاتی</span>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-2">
                    چگونگی بهره‌برداری از سامانه در ۴ گام ساده
                </h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="step in workflowSteps"
                    :key="step.step"
                    class="glass-card !p-5 relative"
                >
                    <span class="text-2xl font-black text-indigo-600/30 dark:text-indigo-400/30 block mb-2 font-mono">
                        {{ step.step }}
                    </span>
                    <h4 class="text-sm font-bold text-slate-900 dark:text-white mb-1.5">
                        {{ step.title }}
                    </h4>
                    <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                        {{ step.desc }}
                    </p>
                </div>
            </div>
        </section>

        <!-- 5. Operational Metrics / Stats -->
        <section class="py-12 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="metric in metrics"
                    :key="metric.label"
                    class="glass-card !p-5 text-center"
                >
                    <div class="text-xl sm:text-2xl font-black text-indigo-600 dark:text-indigo-400 font-mono mb-1">
                        {{ metric.value }}
                    </div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white mb-1">
                        {{ metric.label }}
                    </div>
                    <div class="text-[11px] text-slate-500 dark:text-slate-400">
                        {{ metric.desc }}
                    </div>
                </div>
            </div>
        </section>

        <!-- 6. Frequently Asked Questions (Accordion) -->
        <section class="py-16 px-4 sm:px-6 max-w-4xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="text-center mb-10">
                <span class="glass-pill text-xs font-bold text-indigo-600 dark:text-indigo-400 mb-2">راهنمای کاربران</span>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-2">
                    پرسش‌های متداول سازمانی
                </h2>
            </div>

            <div class="space-y-3">
                <div
                    v-for="(faq, idx) in faqs"
                    :key="faq.q"
                    class="glass-card !p-4 cursor-pointer transition-all"
                    @click="toggleFaq(idx)"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-bold text-slate-900 dark:text-white">{{ faq.q }}</span>
                        <svg
                            class="w-4 h-4 text-slate-400 transition-transform duration-200 shrink-0"
                            :class="{ 'rotate-180 text-indigo-600 dark:text-indigo-400': openFaq === idx }"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </div>
                    <div
                        v-show="openFaq === idx"
                        class="mt-3 pt-3 border-t border-slate-200/50 dark:border-white/10 text-xs text-slate-600 dark:text-slate-300 leading-relaxed"
                    >
                        {{ faq.a }}
                    </div>
                </div>
            </div>
        </section>

        <!-- 7. Final Call to Action Box -->
        <section class="py-16 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="glass-card !p-8 sm:!p-12 text-center bg-gradient-to-tr from-indigo-950/20 via-purple-950/20 to-transparent relative overflow-hidden">
                <h3 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mb-3">
                    آماده شروع و مدیریت خطوط سازمانی خود هستید؟
                </h3>
                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 max-w-xl mx-auto mb-6 leading-relaxed">
                    همین حالا حساب کاربری خود را افتتاح نمایید و خطوط و پیام‌های داخلی خود را در محیطی یکپارچه و امن مدیریت کنید.
                </p>

                <div class="flex flex-wrap justify-center gap-4">
                    <Link
                        :href="route('register')"
                        class="glass-btn-primary !w-auto !py-3 !px-8 !text-sm !rounded-2xl"
                    >
                        <span>افتتاح حساب کاربری</span>
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                    </Link>
                    <Link
                        :href="route('login')"
                        class="glass-pill !py-3 !px-7 !text-sm font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400"
                    >
                        <span>ورود به سامانه</span>
                    </Link>
                </div>
            </div>
        </section>
    </GuestLayout>
</template>
