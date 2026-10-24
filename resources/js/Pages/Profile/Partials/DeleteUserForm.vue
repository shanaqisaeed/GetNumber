<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);
const showPassword = ref(false);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;
    nextTick(() => passwordInput.value?.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => {
            form.reset();
        },
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;
    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="w-full space-y-4">
        <header class="flex items-center gap-2.5 pb-3 border-b border-slate-200/80 dark:border-white/10">
            <div class="w-9 h-9 rounded-2xl bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                ⚠️
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="font-black text-sm text-rose-600 dark:text-rose-400">
                        ناحیه امنیتی و حذف حساب
                    </h3>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">غیرقابل بازگشت</span>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    ابطال کلیه دسترسی‌ها و آزادسازی شماره‌های سازمانی
                </p>
            </div>
        </header>

        <div class="p-3.5 rounded-2xl bg-rose-50/80 dark:bg-rose-950/20 border border-rose-200/80 dark:border-rose-900/40 text-xs space-y-3">
            <p class="text-[11px] text-rose-800 dark:text-rose-200/90 leading-relaxed">
                با حذف حساب کاربری، کلیه دسترسی‌ها، کلیدهای ارتباطی و پیام‌های ثبت‌شده به‌صورت دائمی منقضی شده و شماره‌های اختصاص‌یافته فوراً آزاد می‌گردند.
            </p>

            <button
                type="button"
                @click="confirmUserDeletion"
                class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md shadow-rose-600/25 hover:shadow-rose-600/40 transition-all inline-flex items-center gap-1.5"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                <span>شروع فرآیند حذف حساب</span>
            </button>
        </div>

        <!-- Teleported Unified Confirmation Modal -->
        <teleport to="body">
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="confirmingUserDeletion"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                    @click.self="closeModal"
                >
                    <div
                        class="glass-card !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                    >
                        <!-- Modal Header -->
                        <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 dark:border-white/10">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-2xl bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                                    ⚠️
                                </div>
                                <div>
                                    <h3 class="font-black text-sm text-slate-900 dark:text-white">
                                        تأیید نهایی حذف حساب
                                    </h3>
                                    <p class="text-[11px] text-rose-500 font-semibold mt-0.5">عملیات غیرقابل بازگشت</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="closeModal"
                                class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors"
                            >
                                ✕
                            </button>
                        </div>

                        <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                            جهت احراز هویت امنیتی و تایید قطعی حذف دائمی این حساب، لطفاً کلمه عبور جاری خود را وارد فرمایید:
                        </p>

                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                                کلمه عبور جاری
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                </div>
                                <input
                                    id="password"
                                    ref="passwordInput"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    dir="ltr"
                                    placeholder="کلمه عبور جهت تایید"
                                    @keyup.enter="deleteUser"
                                    class="w-full pr-10 pl-10 py-2.5 text-xs font-medium rounded-2xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-800/80 text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-rose-500/50 transition-all"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
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
                            <InputError :message="form.errors.password" class="mt-1 text-xs" />
                        </div>

                        <!-- Modal Actions -->
                        <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="closeModal"
                                class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                            >
                                انصراف
                            </button>

                            <button
                                type="button"
                                class="px-5 py-2.5 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md shadow-rose-600/30 transition-all inline-flex items-center gap-1.5 disabled:opacity-50"
                                :disabled="form.processing || !form.password"
                                @click="deleteUser"
                            >
                                <svg v-if="form.processing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>تأیید و حذف قطعی حساب</span>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </teleport>
    </section>
</template>

