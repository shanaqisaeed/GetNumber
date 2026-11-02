<script setup lang="ts">
import { ref } from 'vue';

const isModalOpen = ref(false);
const password = ref('');
const passwordError = ref<string | null>(null);
const isLoading = ref(false);
const toast = ref<string | null>(null);

const showToast = (msg: string) => {
    toast.value = msg;
    setTimeout(() => { toast.value = null; }, 3000);
};

const logoutOtherSessions = async () => {
    if (!password.value) {
        passwordError.value = 'ورود کلمه عبور الزامی است.';
        return;
    }

    isLoading.value = true;
    passwordError.value = null;

    try {
        const res = await fetch(route('sessions.logout_other'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({ password: password.value }),
        });

        const data = await res.json();
        if (res.ok && data.success) {
            showToast('تمام نشست‌های فعال روی سایر دستگاه‌ها با موفقیت لغو شدند');
            isModalOpen.value = false;
            password.value = '';
        } else {
            passwordError.value = data.errors?.password?.[0] || data.message || 'کلمه عبور وارد شده اشتباه است.';
        }
    } catch {
        passwordError.value = 'خطا در ارتباط با سرور.';
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <div class="space-y-3 pt-4 border-t border-slate-200 dark:border-white/10">
        <div v-if="toast" class="p-3 rounded-2xl bg-indigo-600 text-white text-xs font-bold text-center shadow-md">
            {{ toast }}
        </div>

        <div>
            <h4 class="text-xs font-black text-slate-900 dark:text-white flex items-center gap-1.5">
                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <span>مدیریت نشست‌های فعال روی سایر دستگاه‌ها</span>
            </h4>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                در صورت نیاز می‌توانید تمام نشست‌های فعال حساب خود روی سایر مرورگرها و دستگاه‌ها را لغو کنید.
            </p>
        </div>

        <button
            type="button"
            @click="isModalOpen = true; password = ''; passwordError = null;"
            class="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 border border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300 transition-colors"
        >
            خروج از تمام دستگاه‌های دیگر
        </button>

        <!-- Modal -->
        <div
            v-if="isModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
            @click.self="isModalOpen = false"
        >
            <div class="glass-card !p-5 w-full max-w-sm shadow-2xl relative space-y-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <h4 class="text-sm font-black">تایید خروج از سایر نشست‌ها</h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    جهت تایید، لطفاً کلمه عبور فعلی خود را وارد فرمایید:
                </p>

                <input
                    v-model="password"
                    @keydown.enter.prevent="logoutOtherSessions"
                    type="password"
                    placeholder="کلمه عبور فعلی..."
                    class="w-full glass-input !py-2.5 !px-3 !text-xs !rounded-xl bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white"
                />

                <p v-if="passwordError" class="text-[11px] text-rose-500 font-bold">{{ passwordError }}</p>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        @click="isModalOpen = false"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="logoutOtherSessions"
                        :disabled="isLoading || !password"
                        class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                    >
                        <span v-if="isLoading">در حال پردازش...</span>
                        <span v-else>خروج از سایر دستگاه‌ها</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

