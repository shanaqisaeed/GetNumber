<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    roles: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const showPassword = ref(false);

const form = useForm({
    name: '',
    username: '',
    password: '',
    role: 'user',
    status: 'active',
});

function submit() {
    form.post(route('admin.users.store'));
}
</script>

<template>
    <Head title="تعریف حساب کاربری جدید" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">تعریف حساب کاربری جدید</h1>
        </template>

        <div class="w-full space-y-6">
            <!-- Action bar with back button in body -->
            <div class="flex items-center justify-between pb-2">
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                        تعریف حساب کاربری جدید
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        ثبت اطلاعات و صدور دسترسی حساب کاربری سازمانی
                    </p>
                </div>

                <Link
                    :href="route('admin.users.index')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-colors w-fit"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>بازگشت به فهرست کاربران</span>
                </Link>
            </div>
            <!-- 2026 Admin Info Banner (Full Width) -->
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white p-6 sm:p-7 border border-indigo-500/20 shadow-lg">
                <div class="absolute -top-16 -right-16 w-56 h-56 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-16 -left-16 w-56 h-56 bg-purple-500/15 rounded-full blur-3xl pointer-events-none"></div>

                <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 flex items-center justify-center shrink-0">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base sm:text-lg font-black text-white">صدور آنی شناسه و پروانه کاربری</h2>
                            <p class="text-xs text-indigo-200/80 mt-0.5 leading-relaxed">
                                حساب‌های ایجادشده بلافاصله آماده تخصیص خطوط سازمانی ۹ رقمی و بهره‌برداری از خدمات شبکه خواهند بود.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/10 text-white border border-white/10 text-xs font-bold">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            کنسول مرکزی راهبری ۲۰۲۶
                        </span>
                    </div>
                </div>
            </div>

            <!-- Full-Width Form Card -->
            <div class="w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-6 sm:p-8 lg:p-10 shadow-sm">
                <form @submit.prevent="submit" class="space-y-8">
                    <!-- Form Grid 2-Columns on Large screens -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Column 1: Identity & Credentials -->
                        <div class="space-y-6">
                            <div class="pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs">
                                    ۱
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">مشخصات هویتی و شناسه کاربری</h3>
                                    <p class="text-[11px] text-slate-400">اطلاعات پایه حساب جهت احراز هویت</p>
                                </div>
                            </div>

                            <!-- Name -->
                            <div>
                                <label for="name" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    عنوان سازمانی / نام و نام خانوادگی
                                </label>
                                <div class="relative rounded-2xl group">
                                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <input
                                        id="name"
                                        type="text"
                                        v-model="form.name"
                                        required
                                        autofocus
                                        placeholder="مثال: شرکت ارتباطات پیشرو یا احمد رضایی"
                                        class="block w-full pr-11 pl-4 py-3.5 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none"
                                    />
                                </div>
                                <InputError class="mt-2 text-xs" :message="form.errors.name" />
                            </div>

                            <!-- Username -->
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label for="username" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                        شناسه کاربری یکتا (نام کاربری ورود)
                                    </label>
                                    <span class="text-[11px] text-slate-400 font-mono">حروف انگلیسی و ارقام</span>
                                </div>
                                <div class="relative rounded-2xl group">
                                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                    <input
                                        id="username"
                                        type="text"
                                        v-model="form.username"
                                        required
                                        dir="ltr"
                                        placeholder="مثال: tehran_agency یا ahmad100"
                                        class="block w-full pr-11 pl-4 py-3.5 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none text-right"
                                    />
                                </div>
                                <InputError class="mt-2 text-xs" :message="form.errors.username" />
                            </div>

                            <!-- Password -->
                            <div>
                                <label for="password" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    کلمه عبور اولیه
                                </label>
                                <div class="relative rounded-2xl group">
                                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                    </div>
                                    <input
                                        id="password"
                                        :type="showPassword ? 'text' : 'password'"
                                        v-model="form.password"
                                        required
                                        dir="ltr"
                                        placeholder="حداقل ۸ نویسه امن"
                                        class="block w-full pr-11 pl-11 py-3.5 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none"
                                    />
                                    <button
                                        type="button"
                                        @click="showPassword = !showPassword"
                                        class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                                        title="نمایش یا پنهان گذرواژه"
                                    >
                                        <svg v-if="!showPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                                        </svg>
                                    </button>
                                </div>
                                <InputError class="mt-2 text-xs" :message="form.errors.password" />
                            </div>
                        </div>

                        <!-- Column 2: Role, Status & Policy -->
                        <div class="space-y-6">
                            <div class="pb-3 border-b border-slate-100 dark:border-slate-800 flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold text-xs">
                                    ۲
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">سطوح دسترسی و وضعیت حساب</h3>
                                    <p class="text-[11px] text-slate-400">تعیین مجوزها و اختیارات کاربری</p>
                                </div>
                            </div>

                            <!-- Role -->
                            <div>
                                <label for="role" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    نقش کاربری در سامانه
                                </label>
                                <div class="relative">
                                    <select
                                        id="role"
                                        v-model="form.role"
                                        required
                                        class="block w-full px-4 py-3.5 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 outline-none transition-all cursor-pointer"
                                    >
                                        <option v-for="r in roles" :key="r.value" :value="r.value">
                                            {{ r.label }}
                                        </option>
                                    </select>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1.5">
                                    راهبر ارشد دسترسی کامل مدیریتی دارد؛ کاربر سازمانی فقط به پیشخوان کاربری دسترسی دارد.
                                </p>
                                <InputError class="mt-2 text-xs" :message="form.errors.role" />
                            </div>

                            <!-- Status -->
                            <div>
                                <label for="status" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    وضعیت اولیه حساب
                                </label>
                                <div class="relative">
                                    <select
                                        id="status"
                                        v-model="form.status"
                                        required
                                        class="block w-full px-4 py-3.5 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 outline-none transition-all cursor-pointer"
                                    >
                                        <option v-for="s in statuses" :key="s.value" :value="s.value">
                                            {{ s.label }}
                                        </option>
                                    </select>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1.5">
                                    در وضعیت «فعال»، کاربر بلافاصله پس از تعریف قادر به ورود خواهد بود.
                                </p>
                                <InputError class="mt-2 text-xs" :message="form.errors.status" />
                            </div>

                            <!-- Modern Security Checklist Card -->
                            <div class="p-4 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 space-y-2">
                                <div class="flex items-center gap-2 text-xs font-bold text-indigo-900 dark:text-indigo-300">
                                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                    <span>استاندارد امنیت سازمانی ۲۰۲۶</span>
                                </div>
                                <p class="text-[11px] text-indigo-700/80 dark:text-indigo-400 leading-relaxed">
                                    گذرواژه به صورت هش‌شده با الگوریتم Bcrypt ذخیره شده و تمامی نشست‌های ورودی در گزارش‌های نظارتی ادمین لاگ می‌گردند.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Bar -->
                    <div class="pt-6 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                        <Link
                            :href="route('admin.users.index')"
                            class="px-6 py-3 rounded-2xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            انصراف و بازگشت
                        </Link>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-8 py-3.5 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs sm:text-sm font-bold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-600/50 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2 group"
                        >
                            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <svg v-else class="w-4 h-4 text-white/90 group-hover:scale-110 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                            <span>ثبت و صدور دسترسی حساب کاربری</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
