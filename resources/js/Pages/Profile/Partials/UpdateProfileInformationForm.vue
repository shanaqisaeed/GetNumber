<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm, usePage } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
});
</script>

<template>
    <section class="w-full space-y-4">
        <header class="flex items-center gap-2.5 pb-3 border-b border-slate-200/80 dark:border-white/10">
            <div class="w-9 h-9 rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                👤
            </div>
            <div>
                <h3 class="font-black text-sm text-slate-900 dark:text-white">
                    مشخصات هویتی و سازمانی
                </h3>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    به‌روزرسانی نام سازمانی و عنوان نمایشی حساب کاربری
                </p>
            </div>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="space-y-4 w-full"
        >
            <!-- Organization / Display Name -->
            <div class="space-y-1.5">
                <label for="name" class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                    عنوان سازمانی / نام و نام خانوادگی
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                    </div>
                    <input
                        id="name"
                        type="text"
                        v-model="form.name"
                        required
                        autofocus
                        autocomplete="name"
                        placeholder="نام و نام خانوادگی یا نام شرکت"
                        class="w-full pr-10 pl-3.5 py-2.5 text-xs font-medium rounded-2xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-800/80 text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition-all"
                    />
                </div>
                <InputError class="mt-1 text-xs" :message="form.errors.name" />
            </div>

            <!-- Username Field (Permanently Read-only / Locked) -->
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span>شناسه کاربری</span>
                    </label>
                    <span class="text-[10px] font-bold text-amber-600 dark:text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-md border border-amber-500/20">غیرقابل تغییر</span>
                </div>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        :value="user.username"
                        disabled
                        readonly
                        dir="ltr"
                        class="w-full pr-10 pl-3.5 py-2.5 text-xs text-right font-mono font-bold rounded-2xl border border-slate-200/80 dark:border-white/5 bg-slate-100/80 dark:bg-slate-900/80 text-slate-500 dark:text-slate-400 cursor-not-allowed select-none outline-none"
                    />
                </div>
                <p class="text-[10px] text-slate-500 dark:text-slate-400 leading-relaxed">
                    شناسه کاربری یکتا بوده و جهت حفظ پایداری ارتباطات سازمانی غیرقابل تغییر است.
                </p>
            </div>

            <!-- Action Button & Feedback -->
            <div class="pt-2 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200/80 dark:border-white/10">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-2 shadow-md shadow-indigo-500/20 disabled:opacity-40"
                >
                    <svg v-if="form.processing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>ذخیره تغییرات</span>
                </button>

                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-1"
                    leave-active-class="transition ease-in duration-150"
                    leave-to-class="opacity-0 translate-y-1"
                >
                    <div
                        v-if="form.recentlySuccessful"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-xs font-bold"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>اطلاعات با موفقیت ذخیره شد.</span>
                    </div>
                </Transition>
            </div>
        </form>
    </section>
</template>
