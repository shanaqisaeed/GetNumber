<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface UserOption {
    id: number;
    name: string;
    account_id: string;
}

interface PrefixOption {
    id: number;
    prefix: string;
    name: string;
}

interface NumberData {
    id: number;
    number: string;
    formatted_number: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    display_title: string | null;
    accepts_incoming_sms: boolean;
    rond_tier: string;
    rond_pattern: string | null;
    phone_prefix_id: number | null;
    user_id: number | null;
    user: {
        id: number;
        account_id: string;
        name: string;
    } | null;
    allocated_at: string | null;
    created_at: string;
}

interface ConsumptionMetrics {
    sent_sms_count: number;
    received_sms_count: number;
    voice_calls_count: number;
    last_activity: string;
}

const props = defineProps<{
    number: NumberData;
    metrics: ConsumptionMetrics;
    users: UserOption[];
    prefixes: PrefixOption[];
}>();

const form = useForm({
    display_title: props.number.display_title || '',
    type: props.number.type,
    rond_tier: props.number.rond_tier,
    rond_pattern: props.number.rond_pattern || '',
    phone_prefix_id: props.number.phone_prefix_id,
    user_id: props.number.user_id,
    accepts_incoming_sms: props.number.accepts_incoming_sms,
});

const submit = () => {
    form.put(route('admin.numbers.update', props.number.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`ویرایش تخصصی خط ${number.formatted_number}`" />

    <AdminLayout>
        <div class="space-y-6 max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('admin.numbers.index')"
                        class="p-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 transition-colors"
                        title="بازگشت به لیست خطوط"
                    >
                        <svg class="w-5 h-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white font-mono tracking-tight">
                                {{ number.formatted_number }}
                            </h1>
                            <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300">
                                {{ number.status_label }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            تنظیمات مسیریابی، وضعیت دوطرفه/یک‌طرفه، مالکیت خط و مانیتورینگ مصرف
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400">تاریخ ایجاد: {{ number.created_at }}</span>
                </div>
            </div>

            <!-- Consumption Monitoring Dashboard Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs text-gray-400">پیامک‌های ارسالی (خروجی)</div>
                        <div class="text-xl font-black text-gray-900 dark:text-white mt-1 font-mono">
                            {{ metrics.sent_sms_count }}
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 text-blue-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs text-gray-400">پیامک‌های دریافتی (ورودی)</div>
                        <div class="text-xl font-black text-gray-900 dark:text-white mt-1 font-mono">
                            {{ metrics.received_sms_count }}
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs text-gray-400">تماس‌های صوتی ثبت‌شده</div>
                        <div class="text-xl font-black text-gray-900 dark:text-white mt-1 font-mono">
                            {{ metrics.voice_calls_count }}
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-900/30 text-purple-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm flex items-center justify-between">
                    <div>
                        <div class="text-xs text-gray-400">آخرین فعالیت ثبت‌شده</div>
                        <div class="text-sm font-bold text-gray-900 dark:text-white mt-1">
                            {{ metrics.last_activity }}
                        </div>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/30 text-amber-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Settings Form -->
            <form @submit.prevent="submit" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 p-6 space-y-6">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-100 dark:border-gray-700">
                    پیکربندی و تخصیص خط
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <!-- Title -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            عنوان نمایشی خط
                        </label>
                        <input
                            v-model="form.display_title"
                            type="text"
                            placeholder="مثال: خط مرکزی پشتیبانی فروش"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500"
                        />
                        <div v-if="form.errors.display_title" class="text-xs text-rose-500 mt-1">
                            {{ form.errors.display_title }}
                        </div>
                    </div>

                    <!-- Type -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            نوع خط
                        </label>
                        <select
                            v-model="form.type"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        >
                            <option value="user_standard">خط استاندارد کاربر (User Standard)</option>
                            <option value="user_service">سرشماره خدماتی کاربر (User Service)</option>
                            <option value="platform_service">خط سامانه مرکزی پلتفرم (Platform Service)</option>
                        </select>
                        <div v-if="form.errors.type" class="text-xs text-rose-500 mt-1">
                            {{ form.errors.type }}
                        </div>
                    </div>

                    <!-- Rond Tier -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            رده‌بندی رند بودن
                        </label>
                        <select
                            v-model="form.rond_tier"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        >
                            <option value="regular">عادی (Regular)</option>
                            <option value="semi_rond">نیمه‌رند (Semi-Rond)</option>
                            <option value="golden_rond">رند طلایی (Golden VIP)</option>
                        </select>
                    </div>

                    <!-- Rond Pattern Note -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            الگوی رند (توضیح فنی)
                        </label>
                        <input
                            v-model="form.rond_pattern"
                            type="text"
                            placeholder="مثال: تکرار ۴ رقمی / آینه‌ای"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        />
                    </div>

                    <!-- User Allocation strictly by Account ID -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            کاربر مالک خط (ارجاع منحصراً بر اساس Account ID / شناسه کاربر)
                        </label>
                        <select
                            v-model="form.user_id"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        >
                            <option :value="null">-- بدون مالک (آزاد در استخر خطوط) --</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">
                                {{ u.name }} — [{{ u.account_id }}]
                            </option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">
                            تغییر مالک باعث انتقال مالکیت پیام‌ها و قابلیت تماس این خط می‌شود.
                        </p>
                    </div>

                    <!-- Prefix -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">
                            سرشماره والد / اپراتور
                        </label>
                        <select
                            v-model="form.phone_prefix_id"
                            class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500"
                        >
                            <option :value="null">-- بدون پیش‌شماره معین --</option>
                            <option v-for="px in prefixes" :key="px.id" :value="px.id">
                                {{ px.prefix }} ({{ px.name }})
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Two-Way Incoming SMS Toggle -->
                <div class="pt-4 border-t border-gray-100 dark:border-gray-700">
                    <div class="flex items-start gap-3">
                        <input
                            id="accepts_incoming_sms"
                            v-model="form.accepts_incoming_sms"
                            type="checkbox"
                            class="mt-1 w-4 h-4 text-indigo-600 rounded border-gray-300 dark:border-gray-600 focus:ring-indigo-500"
                        />
                        <div>
                            <label for="accepts_incoming_sms" class="text-sm font-bold text-gray-900 dark:text-white cursor-pointer">
                                خط دوطرفه (قابلیت دریافت پیامک ورودی فعال باشد)
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                در صورت غیرفعال بودن، خط به صورت یک‌طرفه (فقط ارسال خروجی) عمل کرده و وب‌هوک‌های ورودی نادیده گرفته می‌شوند.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="pt-4 flex items-center justify-end gap-3">
                    <Link
                        :href="route('admin.numbers.index')"
                        class="px-5 py-2.5 text-sm font-medium rounded-xl text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 transition-colors"
                    >
                        بازگشت
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2.5 text-sm font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50 transition-colors"
                    >
                        {{ form.processing ? 'در حال ذخیره‌سازی...' : 'ذخیره تغییرات تخصصی خط' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>

