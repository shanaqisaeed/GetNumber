<script setup lang="ts">
import { ref, onMounted } from 'vue';
import QrCode from '@/Components/QrCode.vue';

interface TwoFactorStatus {
    enabled: boolean;
    confirmed_at: string | null;
    has_recovery_codes: boolean;
    recovery_codes_count: number;
    trust_tier: string;
    risk_score: number;
}

const status = ref<TwoFactorStatus>({
    enabled: false,
    confirmed_at: null,
    has_recovery_codes: false,
    recovery_codes_count: 0,
    trust_tier: 'new',
    risk_score: 0,
});

const isLoading = ref(true);
const isEnabling = ref(false);
const isConfirming = ref(false);
const isDisabling = ref(false);
const isRegenerating = ref(false);

// Setup state
const qrCodeUrl = ref<string | null>(null);
const manualSecret = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);
const confirmationCode = ref('');
const confirmError = ref<string | null>(null);

// Password modal state for disable / regenerate
const isPasswordModalOpen = ref(false);
const passwordAction = ref<'disable' | 'regenerate'>('disable');
const currentPassword = ref('');
const passwordError = ref<string | null>(null);

// Toast notification
const toast = ref<string | null>(null);
const showToast = (msg: string) => {
    toast.value = msg;
    setTimeout(() => { toast.value = null; }, 3000);
};

const fetchStatus = async () => {
    try {
        const res = await fetch(route('two-factor.status'), {
            headers: { 'Accept': 'application/json' },
        });
        if (res.ok) {
            status.value = await res.json();
        }
    } catch {
        // Silently tolerate
    } finally {
        isLoading.value = false;
    }
};

const startEnable = async () => {
    isEnabling.value = true;
    confirmError.value = null;
    try {
        const res = await fetch(route('two-factor.enable'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });
        const data = await res.json();
        if (res.ok && data.success) {
            qrCodeUrl.value = data.qr_code_url;
            manualSecret.value = data.secret;
            recoveryCodes.value = data.recovery_codes || [];
        } else {
            showToast(data.message || 'خطا در ایجاد کلید امنیتی');
        }
    } catch {
        showToast('خطای شبکه در ارتباط با سرور');
    } finally {
        isEnabling.value = false;
    }
};

const confirmEnable = async () => {
    if (!confirmationCode.value || confirmationCode.value.length !== 6) {
        confirmError.value = 'لطفاً کد ۶ رقمی را به صورت کامل وارد نمایید.';
        return;
    }

    isConfirming.value = true;
    confirmError.value = null;

    try {
        const res = await fetch(route('two-factor.confirm'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({ code: confirmationCode.value }),
        });

        const data = await res.json();
        if (res.ok && data.success) {
            showToast('احراز هویت دومرحله‌ای با موفقیت فعال شد');
            status.value.enabled = true;
            status.value.confirmed_at = new Date().toISOString();
            if (data.recovery_codes) {
                recoveryCodes.value = data.recovery_codes;
            }
            qrCodeUrl.value = null;
            manualSecret.value = null;
            confirmationCode.value = '';
            fetchStatus();
        } else {
            confirmError.value = data.errors?.code?.[0] || data.message || 'کد وارد شده نامعتبر است.';
        }
    } catch {
        confirmError.value = 'خطا در برقراری ارتباط با سرور.';
    } finally {
        isConfirming.value = false;
    }
};

const openPasswordModal = (action: 'disable' | 'regenerate') => {
    passwordAction.value = action;
    currentPassword.value = '';
    passwordError.value = null;
    isPasswordModalOpen.value = true;
};

const submitPasswordAction = async () => {
    if (!currentPassword.value) {
        passwordError.value = 'ورود گذرواژه الزامی است.';
        return;
    }

    passwordError.value = null;

    if (passwordAction.value === 'disable') {
        isDisabling.value = true;
        try {
            const res = await fetch(route('two-factor.disable'), {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ password: currentPassword.value }),
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast('احراز هویت دومرحله‌ای غیرفعال شد');
                status.value.enabled = false;
                status.value.confirmed_at = null;
                recoveryCodes.value = [];
                isPasswordModalOpen.value = false;
                fetchStatus();
            } else {
                passwordError.value = data.errors?.password?.[0] || data.message || 'گذرواژه وارد شده اشتباه است.';
            }
        } catch {
            passwordError.value = 'خطای ارتباط با سرور.';
        } finally {
            isDisabling.value = false;
        }
    } else {
        isRegenerating.value = true;
        try {
            const res = await fetch(route('two-factor.recovery-codes'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                },
                body: JSON.stringify({ password: currentPassword.value }),
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast('کدهای بازیابی جدید صادر شدند');
                recoveryCodes.value = data.recovery_codes || [];
                isPasswordModalOpen.value = false;
            } else {
                passwordError.value = data.errors?.password?.[0] || data.message || 'گذرواژه وارد شده اشتباه است.';
            }
        } catch {
            passwordError.value = 'خطای ارتباط با سرور.';
        } finally {
            isRegenerating.value = false;
        }
    }
};

const copyRecoveryCodes = () => {
    if (recoveryCodes.value.length === 0) return;
    const text = recoveryCodes.value.join('\n');
    navigator.clipboard.writeText(text);
    showToast('کدهای بازیابی در کلیپ‌بورد کپی شدند');
};

const copyText = (text: string) => {
    navigator.clipboard.writeText(text);
    showToast('در کلیپ‌بورد کپی شد');
};

onMounted(() => {
    fetchStatus();
});
</script>

<template>
    <div class="space-y-5">
        <!-- Toast message -->
        <div
            v-if="toast"
            class="p-3 rounded-2xl bg-indigo-600 text-white text-xs font-bold text-center shadow-lg transition-all"
        >
            {{ toast }}
        </div>

        <!-- Section Header -->
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>احراز هویت دومرحله‌ای (TOTP)</span>
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    افزایش امنیت ورود به حساب کاربری با استفاده از اپلیکیشن‌های Authenticator (مانند Google Authenticator یا 1Password)
                </p>
            </div>

            <!-- Current Status Badge -->
            <div>
                <span
                    v-if="status.enabled"
                    class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5"
                >
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>فعال</span>
                </span>
                <span
                    v-else
                    class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-400"
                >
                    غیرفعال
                </span>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading" class="py-6 text-center text-xs text-slate-400">
            در حال دریافت وضعیت امنیتی حساب...
        </div>

        <!-- 1. When 2FA is NOT yet enabled and setup has NOT started -->
        <div v-else-if="!status.enabled && !qrCodeUrl" class="space-y-3">
            <div class="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/60 dark:border-indigo-500/20 text-xs text-indigo-950 dark:text-indigo-200 leading-relaxed">
                با فعال‌سازی احراز هویت دومرحله‌ای، هنگام ورود علاوه بر گذرواژه، یک کد ۶ رقمی یکبار مصرف که توسط برنامه گوشی شما تولید می‌شود لازم خواهد بود. این ویژگی مانع نفوذ هکرها حتی در صورت لو رفتن گذرواژه می‌شود.
            </div>

            <button
                type="button"
                @click="startEnable"
                :disabled="isEnabling"
                class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-xl inline-flex items-center gap-2"
            >
                <svg v-if="isEnabling" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                <span>فعال‌سازی احراز هویت دومرحله‌ای</span>
            </button>
        </div>

        <!-- 2. Setup Step: QR Code & Confirmation Input -->
        <div v-else-if="!status.enabled && qrCodeUrl" class="space-y-4 p-4 rounded-2xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10">
            <h4 class="text-xs font-black text-slate-900 dark:text-white">مراحل راه‌اندازی با اپلیکیشن احراز هویت:</h4>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
                <!-- QR Code Box -->
                <div class="flex flex-col items-center justify-center p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-center space-y-2">
                    <QrCode :value="qrCodeUrl" :size="160" />
                    <span class="text-[10px] text-slate-500 dark:text-slate-400">کد QR بالا را با برنامه Authenticator اسکن کنید</span>
                </div>

                <!-- Manual Secret Key -->
                <div class="space-y-2 text-xs">
                    <p class="text-slate-600 dark:text-slate-300">
                        یا کلید زیر را به صورت دستی در برنامه احراز هویت خود وارد نمایید:
                    </p>
                    <div class="p-2.5 rounded-xl bg-slate-200/80 dark:bg-slate-800 font-mono text-xs font-black text-indigo-700 dark:text-indigo-300 flex items-center justify-between select-all">
                        <span>{{ manualSecret }}</span>
                        <button type="button" @click="copyText(manualSecret || '')" class="text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 text-[11px] font-sans">
                            کپی
                        </button>
                    </div>

                    <!-- 6-digit confirmation input -->
                    <div class="pt-2 space-y-1.5">
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300">
                            کد ۶ رقمی نمایش‌داده‌شده در برنامه را وارد کنید:
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                v-model="confirmationCode"
                                @keydown.enter.prevent="confirmEnable"
                                type="text"
                                maxlength="6"
                                placeholder="123456"
                                dir="ltr"
                                class="w-36 h-10 px-3 text-center rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 font-mono text-base font-black text-slate-900 dark:text-white tracking-widest focus:border-indigo-500 outline-none"
                            />
                            <button
                                type="button"
                                @click="confirmEnable"
                                :disabled="isConfirming || confirmationCode.length !== 6"
                                class="glass-btn-primary !w-auto !py-2.5 !px-4 !text-xs !rounded-xl"
                            >
                                <span v-if="isConfirming">در حال بررسی...</span>
                                <span v-else>تایید و اتمام</span>
                            </button>
                        </div>
                        <p v-if="confirmError" class="text-[11px] text-rose-500 font-bold">{{ confirmError }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. When 2FA is CONFIRMED and ACTIVE -->
        <div v-else-if="status.enabled" class="space-y-4">
            <div class="p-3.5 rounded-2xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/70 dark:border-emerald-500/20 flex items-center justify-between text-xs">
                <div class="flex items-center gap-2 text-emerald-900 dark:text-emerald-200 font-medium">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>حساب کاربری شما با لایه احراز هویت دومرحله‌ای محافظت می‌شود.</span>
                </div>

                <button
                    type="button"
                    @click="openPasswordModal('disable')"
                    class="text-rose-600 dark:text-rose-400 hover:underline font-bold text-xs"
                >
                    غیرفعال‌سازی
                </button>
            </div>

            <!-- Recovery Codes Box -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300">کدهای بازیابی اضطراری (Backup Codes):</span>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="recoveryCodes.length > 0"
                            type="button"
                            @click="copyRecoveryCodes"
                            class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            کپی همه کدها
                        </button>
                        <button
                            type="button"
                            @click="openPasswordModal('regenerate')"
                            class="text-xs font-bold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
                        >
                            صدور کدهای جدید
                        </button>
                    </div>
                </div>

                <div v-if="recoveryCodes.length > 0" class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center font-mono font-bold text-xs text-slate-800 dark:text-slate-200">
                        <span v-for="c in recoveryCodes" :key="c" class="p-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 select-all">
                            {{ c }}
                        </span>
                    </div>
                    <p class="text-[10px] text-amber-600 dark:text-amber-400 mt-2 text-center">
                        این کدها را در مکانی امن ذخیره کنید. در صورت گم کردن گوشی، تنها راه ورود شما خواهند بود. هر کد فقط یکبار مصرف است.
                    </p>
                </div>
            </div>
        </div>

        <!-- Password Confirmation Modal (for disable or regenerate) -->
        <div
            v-if="isPasswordModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
            @click.self="isPasswordModalOpen = false"
        >
            <div class="glass-card !p-5 w-full max-w-sm shadow-2xl relative space-y-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <h4 class="text-sm font-black">
                    {{ passwordAction === 'disable' ? 'تایید غیرفعال‌سازی ورود دومرحله‌ای' : 'تایید صدور کدهای بازیابی جدید' }}
                </h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    جهت تایید هویت، لطفاً گذرواژه فعلی حساب کاربری خود را وارد فرمایید:
                </p>

                <input
                    v-model="currentPassword"
                    @keydown.enter.prevent="submitPasswordAction"
                    type="password"
                    placeholder="گذرواژه فعلی..."
                    class="w-full glass-input !py-2.5 !px-3 !text-xs !rounded-xl bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white"
                />

                <p v-if="passwordError" class="text-[11px] text-rose-500 font-bold">{{ passwordError }}</p>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        @click="isPasswordModalOpen = false"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="submitPasswordAction"
                        :disabled="isDisabling || isRegenerating || !currentPassword"
                        class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                    >
                        <span>تایید</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

