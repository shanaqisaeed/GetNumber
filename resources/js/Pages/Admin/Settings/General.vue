<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface Props {
    settings: {
        app_name: string;
        app_description: string;
        event_notify_login_failed: boolean;
        event_notify_user_registered: boolean;
        event_notify_security_alert: boolean;
    };
}

const props = defineProps<Props>();

const form = useForm({
    app_name: props.settings.app_name,
    app_description: props.settings.app_description,
    event_notify_login_failed: props.settings.event_notify_login_failed,
    event_notify_user_registered: props.settings.event_notify_user_registered,
    event_notify_security_alert: props.settings.event_notify_security_alert,
});

const submit = () => {
    form.post(route('admin.settings.general.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="تنظیمات عمومی سامانه" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-500 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-black text-slate-900 dark:text-white">تنظیمات عمومی سامانه</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">هسته مرکزی تنظیمات، هویت سازمانی و رویدادهای پلتفرم</p>
                </div>
            </div>
        </template>

        <div class="max-w-4xl mx-auto space-y-6 pb-12">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Platform Identity Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                    <div class="flex items-center gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                        <h2 class="text-sm font-black text-slate-900 dark:text-white">مشخصات و عنوان پلتفرم</h2>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label for="app_name" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                نام رسمی و برند سامانه:
                            </label>
                            <input
                                id="app_name"
                                type="text"
                                v-model="form.app_name"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.app_name" />
                        </div>

                        <div>
                            <label for="app_description" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                شرح مختصر سامانه:
                            </label>
                            <textarea
                                id="app_description"
                                rows="3"
                                v-model="form.app_description"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                            ></textarea>
                            <InputError class="mt-1" :message="form.errors.app_description" />
                        </div>
                    </div>
                </div>

                <!-- Central Events & Notifications Hub Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                    <div class="flex items-center gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                        <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                        <div>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">رویدادها و مانیتورینگ امنیتی (Events Hub)</h2>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">تعیین رخدادهایی که باید به عنوان رویدادهای سیستمی ثبت، مانیتور و حسابرسی گردند</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <!-- Event 1: Login Failures -->
                        <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700">
                            <div>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">ثبت رویداد تلاش‌های ناموفق ورود (Failed Logins)</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">ثبت آدرس IP، شیوه ورود و شناسه کاربری در صورت ورود رمز نادرست در گزارش وقایع</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.event_notify_login_failed" class="sr-only peer">
                                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-amber-500"></div>
                            </label>
                        </div>

                        <!-- Event 2: User Registration -->
                        <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700">
                            <div>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">ثبت رویداد ایجاد حساب و عضویت جدید (User Registration)</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">ثبت زمان، مشخصات و روش احراز هویت اولیه کاربران جدید در لاگ audit</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.event_notify_user_registered" class="sr-only peer">
                                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-amber-500"></div>
                            </label>
                        </div>

                        <!-- Event 3: Security Incidents -->
                        <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700">
                            <div>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">رویداد هشدار حوادث امنیتی (Security Incident Alert)</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">ارسال رویداد و اعلان به مانیتورینگ ادمین در صورت شناسایی الگوهای فیشینگ یا مسدودی خطوط</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.event_notify_security_alert" class="sr-only peer">
                                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-amber-500"></div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Submit Button Bar -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white font-black text-xs shadow-md shadow-indigo-500/25 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-50"
                    >
                        <span v-if="form.processing">در حال ذخیره‌سازی...</span>
                        <template v-else>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>ذخیره تنظیمات عمومی</span>
                        </template>
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>

