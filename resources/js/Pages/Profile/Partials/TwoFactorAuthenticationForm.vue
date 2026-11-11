<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { __ } from '@/Composables/useTrans';
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
            showToast(data.message || __('ui.profile.two_factor.key_error'));
        }
    } catch {
        showToast(__('ui.profile.two_factor.network_error'));
    } finally {
        isEnabling.value = false;
    }
};

const confirmEnable = async () => {
    if (!confirmationCode.value || confirmationCode.value.length !== 6) {
        confirmError.value = __('ui.profile.two_factor.code_required');
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
            showToast(__('ui.profile.two_factor.enabled_success'));
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
            confirmError.value = data.errors?.code?.[0] || data.message || __('ui.profile.two_factor.invalid_code');
        }
    } catch {
        confirmError.value = __('ui.profile.two_factor.server_error');
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
        passwordError.value = __('ui.profile.two_factor.pass_required');
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
                showToast(__('ui.profile.two_factor.disabled_success'));
                status.value.enabled = false;
                status.value.confirmed_at = null;
                recoveryCodes.value = [];
                isPasswordModalOpen.value = false;
                fetchStatus();
            } else {
                passwordError.value = data.errors?.password?.[0] || data.message || __('ui.profile.two_factor.wrong_pass');
            }
        } catch {
            passwordError.value = __('ui.profile.two_factor.server_error');
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
                showToast(__('ui.profile.two_factor.recovery_new_success'));
                recoveryCodes.value = data.recovery_codes || [];
                isPasswordModalOpen.value = false;
            } else {
                passwordError.value = data.errors?.password?.[0] || data.message || __('ui.profile.two_factor.wrong_pass');
            }
        } catch {
            passwordError.value = __('ui.profile.two_factor.server_error');
        } finally {
            isRegenerating.value = false;
        }
    }
};

const copyRecoveryCodes = () => {
    if (recoveryCodes.value.length === 0) return;
    const text = recoveryCodes.value.join('\n');
    navigator.clipboard.writeText(text);
    showToast(__('ui.profile.two_factor.copied_codes'));
};

const copyText = (text: string) => {
    navigator.clipboard.writeText(text);
    showToast(__('ui.profile.two_factor.copied'));
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
                    <span>{{ __('ui.profile.two_factor.title') }}</span>
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    {{ __('ui.profile.two_factor.subtitle') }}
                </p>
            </div>

            <!-- Current Status Badge -->
            <div>
                <span
                    v-if="status.enabled"
                    class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5"
                >
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>{{ __('ui.profile.two_factor.active') }}</span>
                </span>
                <span
                    v-else
                    class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-400"
                >{{ __('ui.profile.two_factor.inactive') }}</span>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="isLoading" class="py-6 text-center text-xs text-slate-400">
            {{ __('ui.profile.two_factor.loading') }}
        </div>

        <!-- 1. When 2FA is NOT yet enabled and setup has NOT started -->
        <div v-else-if="!status.enabled && !qrCodeUrl" class="space-y-3">
            <div class="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/60 dark:border-indigo-500/20 text-xs text-indigo-950 dark:text-indigo-200 leading-relaxed">
                {{ __('ui.profile.two_factor.explanation') }}
            </div>

            <div class="flex justify-center pt-2">
                <button
                    type="button"
                    @click="startEnable"
                    :disabled="isEnabling"
                    class="glass-btn-primary !w-auto !py-3 !px-6 !text-xs !rounded-2xl inline-flex items-center justify-center gap-2 cursor-pointer shadow-md"
                >
                    <svg v-if="isEnabling" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>{{ __('ui.profile.two_factor.start_btn') }}</span>
                </button>
            </div>
        </div>

        <!-- 2. Setup Step: Vertical Sequence (QR -> Token -> Copy Button -> 6-Digit Code -> Activation Button) -->
        <div v-else-if="!status.enabled && qrCodeUrl" class="space-y-5 p-5 rounded-3xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10">
            <div class="text-center space-y-1">
                <h4 class="text-sm font-black text-slate-900 dark:text-white">{{ __('ui.profile.two_factor.steps_title') }}</h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('ui.profile.two_factor.steps_subtitle') }}</p>
            </div>

            <!-- Level 1: QR Code on its own clean level -->
            <div class="flex flex-col items-center justify-center p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-center shadow-xs">
                <div class="p-3 bg-white rounded-2xl shadow-inner border border-slate-100 flex items-center justify-center">
                    <QrCode :value="qrCodeUrl" :size="180" />
                </div>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300 mt-3">
                    {{ __('ui.profile.two_factor.step_1') }}
                </p>
            </div>

            <!-- Level 2: Token (Secret Key) below it -->
            <div class="space-y-1.5 text-center">
                <span class="text-xs font-bold text-slate-600 dark:text-slate-400 block">
                    {{ __('ui.profile.two_factor.step_2') }}
                </span>
                <div class="p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 font-mono text-sm sm:text-base font-black text-indigo-700 dark:text-indigo-300 text-center select-all tracking-wider dir-ltr shadow-xs">
                    {{ manualSecret }}
                </div>
            </div>

            <!-- Level 3: Copy button directly under the token -->
            <div class="flex justify-center">
                <button
                    type="button"
                    @click="copyText(manualSecret || '')"
                    class="px-4 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800/60 text-xs font-bold inline-flex items-center gap-1.5 transition-all cursor-pointer shadow-xs"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <span>{{ __('ui.profile.two_factor.step_3') }}</span>
                </button>
            </div>

            <!-- Level 4: 6-digit confirmation code input -->
            <div class="space-y-2 pt-2 text-center">
                <label class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                    {{ __('ui.profile.two_factor.step_4') }}
                </label>
                <div class="flex justify-center">
                    <input
                        v-model="confirmationCode"
                        @keydown.enter.prevent="confirmEnable"
                        type="text"
                        maxlength="6"
                        placeholder="123456"
                        dir="ltr"
                        class="w-48 h-12 text-center rounded-2xl bg-white dark:bg-slate-900 border-2 border-indigo-400 dark:border-indigo-500/50 font-mono text-xl font-black text-slate-900 dark:text-white tracking-[0.3em] focus:border-indigo-600 focus:ring-4 focus:ring-indigo-500/20 outline-none shadow-sm transition-all"
                    />
                </div>
                <p v-if="confirmError" class="text-xs text-rose-500 font-bold">{{ confirmError }}</p>
            </div>

            <!-- Level 5: Activation button -->
            <div class="pt-2">
                <button
                    type="button"
                    @click="confirmEnable"
                    :disabled="isConfirming || confirmationCode.length !== 6"
                    class="glass-btn-primary !w-full !py-3 !text-sm !font-black !rounded-2xl flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
                >
                    <span v-if="isConfirming">{{ __('ui.profile.two_factor.activating') }}</span>
                    <template v-else>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span>{{ __('ui.profile.two_factor.activate_btn') }}</span>
                    </template>
                </button>
            </div>
        </div>

        <!-- 3. When 2FA is CONFIRMED and ACTIVE -->
        <div v-else-if="status.enabled" class="space-y-4">
            <div class="p-3.5 rounded-2xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/70 dark:border-emerald-500/20 flex items-center justify-between text-xs">
                <div class="flex items-center gap-2 text-emerald-900 dark:text-emerald-200 font-medium">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>{{ __('ui.profile.two_factor.protected_badge') }}</span>
                </div>

                <button
                    type="button"
                    @click="openPasswordModal('disable')"
                    class="text-rose-600 dark:text-rose-400 hover:underline font-bold text-xs"
                >{{ __('ui.profile.two_factor.disable_btn') }}</button>
            </div>

            <!-- Recovery Codes Box -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.two_factor.backup_codes_title') }}</span>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="recoveryCodes.length > 0"
                            type="button"
                            @click="copyRecoveryCodes"
                            class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            {{ __('ui.profile.two_factor.copy_all') }}
                        </button>
                        <button
                            type="button"
                            @click="openPasswordModal('regenerate')"
                            class="text-xs font-bold text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
                        >
                            {{ __('ui.profile.two_factor.regenerate') }}
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
                        {{ __('ui.profile.two_factor.backup_notice') }}
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
                    {{ passwordAction === 'disable' ? __('ui.profile.two_factor.modal_disable_title') : __('ui.profile.two_factor.modal_regenerate_title') }}
                </h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ __('ui.profile.two_factor.modal_prompt') }}
                </p>

                <input
                    v-model="currentPassword"
                    @keydown.enter.prevent="submitPasswordAction"
                    type="password"
                    :placeholder="__('ui.profile.two_factor.modal_pass_placeholder')"
                    class="w-full glass-input !py-2.5 !px-3 !text-xs !rounded-xl bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white"
                />

                <p v-if="passwordError" class="text-[11px] text-rose-500 font-bold">{{ passwordError }}</p>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        @click="isPasswordModalOpen = false"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400"
                    >{{ __('ui.profile.two_factor.cancel') }}</button>
                    <button
                        type="button"
                        @click="submitPasswordAction"
                        :disabled="isDisabling || isRegenerating || !currentPassword"
                        class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl"
                    >
                        <span>{{ __('ui.profile.two_factor.confirm') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

