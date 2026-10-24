<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const showPassword = ref(false);
const showConfirmPassword = ref(false);

const form = useForm({
    name: '',
    username: '',
    password: '',
    password_confirmation: '',
    terms: true,
});

// Password strength calculation
const passwordScore = computed(() => {
    const val = form.password;
    if (!val) return 0;
    let score = 0;
    if (val.length >= 8) score += 1;
    if (val.length >= 12) score += 1;
    if (/[0-9]/.test(val)) score += 1;
    if (/[^A-Za-z0-9]/.test(val) || /[A-Z]/.test(val)) score += 1;
    return score;
});

const passwordStrengthLabel = computed(() => {
    const score = passwordScore.value;
    if (score === 0) return { text: '', color: '' };
    if (score === 1) return { text: 'ضعیف', color: 'text-rose-500' };
    if (score === 2) return { text: 'متوسط', color: 'text-amber-500' };
    if (score === 3) return { text: 'خوب', color: 'text-indigo-500' };
    return { text: 'بسیار قوی', color: 'text-emerald-500' };
});

const passwordsMatch = computed(() => {
    if (!form.password_confirmation) return true;
    return form.password === form.password_confirmation;
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head title="ایجاد حساب کاربری" />

        <!-- Header Titles matching demo.html -->
        <div class="text-center mb-5">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-purple-600 via-indigo-500 to-pink-500 flex items-center justify-center text-white shadow-lg shadow-purple-500/30">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="8.5" cy="7" r="4"></circle>
                    <line x1="20" y1="8" x2="20" y2="14"></line>
                    <line x1="23" y1="11" x2="17" y2="11"></line>
                </svg>
            </div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">ایجاد حساب کاربری</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">ثبت‌نام متقاضی خطوط در راوی نو</p>
        </div>

        <!-- Glass Card Form -->
        <div class="glass-card">
            <form @submit.prevent="submit">
                <!-- Organization / Full Name -->
                <div class="glass-input-wrapper">
                    <label for="name" class="glass-label">نام و نام خانوادگی</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <input
                            id="name"
                            type="text"
                            v-model="form.name"
                            class="glass-input"
                            placeholder="نام متقاضی یا شرکت..."
                            required
                            autofocus
                            autocomplete="name"
                        />
                    </div>
                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.name" />
                </div>

                <!-- Username -->
                <div class="glass-input-wrapper">
                    <label for="username" class="glass-label">شناسه کاربری (انگلیسی)</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="4"></circle>
                                <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"></path>
                            </svg>
                        </div>
                        <input
                            id="username"
                            type="text"
                            v-model="form.username"
                            class="glass-input"
                            placeholder="username_id"
                            required
                            dir="ltr"
                            autocomplete="username"
                        />
                    </div>
                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.username" />
                </div>

                <!-- Password -->
                <div class="glass-input-wrapper mb-2">
                    <label for="password" class="glass-label">کلمه عبور امن</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </div>
                        <input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password"
                            class="glass-input"
                            placeholder="حداقل ۸ نویسه..."
                            required
                            dir="ltr"
                            autocomplete="new-password"
                        />
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="pl-3 pr-1 text-slate-400 hover:text-indigo-400 transition-colors focus:outline-none"
                            title="تغییر وضعیت نمایش رمز"
                        >
                            <svg v-if="!showPassword" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>

                    <!-- Password Strength Meter -->
                    <div v-if="form.password" class="strength-bar-wrapper">
                        <div class="strength-track">
                            <div
                                class="strength-segment"
                                :class="{
                                    'active-weak': passwordScore === 1,
                                    'active-med': passwordScore === 2,
                                    'active-good': passwordScore === 3,
                                    'active-strong': passwordScore >= 4,
                                }"
                            ></div>
                            <div
                                class="strength-segment"
                                :class="{
                                    'active-med': passwordScore === 2,
                                    'active-good': passwordScore === 3,
                                    'active-strong': passwordScore >= 4,
                                }"
                            ></div>
                            <div
                                class="strength-segment"
                                :class="{
                                    'active-good': passwordScore === 3,
                                    'active-strong': passwordScore >= 4,
                                }"
                            ></div>
                            <div
                                class="strength-segment"
                                :class="{
                                    'active-strong': passwordScore >= 4,
                                }"
                            ></div>
                        </div>
                        <div class="strength-label">
                            <span>سطح امنیت رمز: <b :class="passwordStrengthLabel.color">{{ passwordStrengthLabel.text }}</b></span>
                            <span>شامل حروف، ارقام و علائم</span>
                        </div>
                    </div>

                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.password" />
                </div>

                <!-- Password Confirmation -->
                <div class="glass-input-wrapper">
                    <label for="password_confirmation" class="glass-label">تکرار کلمه عبور</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <input
                            id="password_confirmation"
                            :type="showConfirmPassword ? 'text' : 'password'"
                            v-model="form.password_confirmation"
                            class="glass-input"
                            placeholder="تکرار کلمه عبور..."
                            required
                            dir="ltr"
                            autocomplete="new-password"
                        />
                        <button
                            type="button"
                            @click="showConfirmPassword = !showConfirmPassword"
                            class="pl-3 pr-1 text-slate-400 hover:text-indigo-400 transition-colors focus:outline-none"
                        >
                            <svg v-if="!showConfirmPassword" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>
                    <p v-if="!passwordsMatch" class="mt-1 text-[11px] text-rose-500 font-semibold">
                        کلمه عبور و تکرار آن مطابقت ندارند.
                    </p>
                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.password_confirmation" />
                </div>

                <!-- Terms Notice -->
                <div class="mb-3 px-1">
                    <label class="flex items-start gap-2 cursor-pointer select-none text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                        <input
                            type="checkbox"
                            v-model="form.terms"
                            class="w-3.5 h-3.5 mt-0.5 rounded text-indigo-600 focus:ring-indigo-500"
                            required
                        />
                        <span>قوانین، ضوابط واگذاری خطوط و حریم خصوصی سامانه را می‌پذیرم.</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    :disabled="form.processing || !passwordsMatch"
                    class="glass-btn-primary mt-2"
                >
                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>ثبت‌نام و ایجاد حساب</span>
                    <svg v-if="!form.processing" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </button>
            </form>

            <!-- Login Footer Link -->
            <div class="text-center mt-5 pt-3.5 border-t border-slate-200/50 dark:border-white/10 text-xs text-slate-500 dark:text-slate-400">
                <span>پیش‌تر حساب کاربری دارید؟</span>
                <Link
                    :href="route('login')"
                    class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline mr-1.5"
                >
                    ورود به حساب کاربری
                </Link>
            </div>
        </div>
    </AuthLayout>
</template>
