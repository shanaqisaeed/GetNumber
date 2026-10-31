<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{
    status: number;
    message?: string;
}>();

const title = computed(() => {
    return {
        400: 'درخواست نامعتبر (۴۰۰)',
        401: 'نیاز به احراز هویت (۴۰۱)',
        403: 'دسترسی غیرمجاز (۴۰۳)',
        404: 'صفحه مورد نظر یافت نشد (۴۰۴)',
        405: 'متد درخواست غیرمجاز است (۴۰۵)',
        408: 'پایان مهلت درخواست (۴۰۸)',
        419: 'نشست کاربری منقضی شد (۴۱۹)',
        422: 'داده‌های ارسالی نامعتبر هستند (۴۲۲)',
        429: 'تعداد درخواست‌ها بیش از حد مجاز (۴۲۹)',
        500: 'خطای داخلی سرور (۵۰۰)',
        502: 'خطای دروازه ارتباطی (۵۰۲)',
        503: 'سرویس موقتاً در دسترس نیست (۵۰۳)',
        504: 'مهلت پاسخ دروازه به پایان رسید (۵۰۴)',
        505: 'نسخه پروتکل پشتیبانی نمی‌شود (۵۰۵)',
    }[props.status] || `خطای سیستمی (${props.status})`;
});

const description = computed(() => {
    if (props.message) return props.message;

    return {
        400: 'ساختار یا پارامترهای درخواست ارسالی به سرور نامعتبر بوده و قابل پردازش نمی‌باشد.',
        401: 'برای دسترسی به این بخش یا منبع اطلاعاتی، لطفاً ابتدا وارد حساب کاربری خود شوید.',
        403: 'شما مجوزها یا سطح دسترسی لازم برای مشاهده این بخش یا انجام این اقدام را دارا نمی‌باشید.',
        404: 'آدرس واردشده در سامانه موجود نبوده، حذف شده و یا ممکن است به نشانی دیگری منتقل شده باشد.',
        405: 'روش فراخوانی این آدرس در پروتکل HTTP توسط سرور برای این مسیر پشتیبانی نمی‌شود.',
        408: 'زمان برقراری ارتباط و تبادل داده با سرور به پایان رسید. لطفاً مجدداً تلاش نمایید.',
        419: 'به دلیل عدم فعالیت طولانی‌مدت، توکن امنیتی فرم منقضی گردیده است. لطفاً صفحه را تازه‌سازی فرمایید.',
        422: 'اطلاعات ارسال‌شده با استانداردهای اعتبارسنجی سرور مطابقت نداشته و قابل پردازش نمی‌باشد.',
        429: 'تعداد درخواست‌های ارسالی شما از سقف نرخ مجاز فراتر رفته است. لطفاً کمی صبر کرده و سپس اقدام فرمایید.',
        500: 'خطای غیرمنتظره‌ای در پردازش درخواست در سرور رخ داده است. این رویداد در لاگ ممیزی ثبت گردید.',
        502: 'سرور در حین ارتباط با سرویس‌های بالادستی پاسخ نامعتبری دریافت کرده است.',
        503: 'سامانه در حال به‌روزرسانی زیرساختی یا عملیات نگهداری دوره‌ای است. لطفاً دقایقی دیگر مراجعه فرمایید.',
        504: 'سرور در زمان مقرر پاسخی از دروازه‌های ارتباطی یا زیرساخت بالادستی دریافت ننمود.',
        505: 'نسخه پروتکل HTTP درخواست ارسالی توسط وب‌سرور سامانه پشتیبانی نمی‌گردد.',
    }[props.status] || 'خطای غیرمنتظره‌ای رخ داده است.';
});
</script>

<template>
    <Head :title="title">
        <meta name="robots" content="noindex, nofollow, noarchive" />
    </Head>

    <div class="min-h-screen flex items-center justify-center p-6 bg-slate-50 dark:bg-[#090d16] text-slate-900 dark:text-slate-100 font-sans selection:bg-indigo-600 selection:text-white relative overflow-hidden" dir="rtl">
        <!-- Ambient background glow -->
        <div class="absolute w-[450px] h-[450px] rounded-full bg-indigo-500/10 blur-3xl pointer-events-none top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"></div>

        <div class="relative z-10 max-w-lg w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/80 dark:border-white/10 rounded-3xl p-8 text-center shadow-2xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 mb-5">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                <span>خطای سیستمی {{ status }}</span>
            </div>

            <h1 class="text-6xl font-black tracking-tight bg-gradient-to-r from-indigo-500 to-purple-500 bg-clip-text text-transparent mb-3">
                {{ status }}
            </h1>

            <h2 class="text-xl font-extrabold text-slate-900 dark:text-white mb-3">
                {{ title }}
            </h2>

            <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-400 mb-8">
                {{ description }}
            </p>

            <div class="flex flex-wrap items-center justify-center gap-3">
                <Link
                    href="/"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-600/30 transition-all hover:-translate-y-0.5"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg>
                    بازگشت به صفحه اصلی
                </Link>

                <Link
                    v-if="status === 401 || status === 419"
                    :href="route('login')"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-slate-900 dark:text-slate-200 border border-slate-200 dark:border-white/10 transition-all hover:-translate-y-0.5"
                >
                    ورود به حساب کاربری
                </Link>
            </div>

            <div class="mt-8 pt-5 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
                <span>سامانه امن ارتباطات سازمانی GetNumber &bull; ارتباط محافظت‌شده</span>
            </div>
        </div>
    </div>
</template>

