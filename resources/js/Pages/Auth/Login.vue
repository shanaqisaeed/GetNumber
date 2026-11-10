<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Props {
    status?: string;
    enabledProviders?: string[];
    authMethods?: {
        username: boolean;
        email: boolean;
        ldap: boolean;
        keycloak: boolean;
    };
    keycloakLoginUrl?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    enabledProviders: () => ['username'],
    authMethods: () => ({
        username: true,
        email: true,
        ldap: false,
        keycloak: false,
    }),
    keycloakLoginUrl: null,
});

const defaultMethod = computed(() => {
    if (props.authMethods.username) return 'username';
    if (props.authMethods.email) return 'email';
    if (props.authMethods.ldap) return 'ldap';
    if (props.authMethods.keycloak) return 'keycloak';
    return 'username';
});

type AuthType = 'username' | 'email' | 'ldap' | 'keycloak';
const selectedMethod = ref<AuthType>(defaultMethod.value as AuthType);
const showPassword = ref(false);

const form = useForm({
    auth_type: defaultMethod.value,
    username: '',
    email: '',
    ldap_username: '',
    ldap_password: '',
    password: '',
    remember: false,
});

const selectMethod = (method: AuthType) => {
    selectedMethod.value = method;
    form.auth_type = method;
    form.clearErrors();
};

const submit = () => {
    form.auth_type = selectedMethod.value;
    if (selectedMethod.value === 'ldap') {
        form.ldap_password = form.password;
    }
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password', 'ldap_password');
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head title="ورود به سامانه" />

        <!-- Header Brand & Title -->
        <div class="text-center mb-6">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-black text-slate-900 dark:text-white tracking-tight">ورود به درگاه هوشمند</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">سامانه متمرکز مدیریت شماره‌های مجازی و ارتباطات سازمانی</p>
        </div>

        <!-- Status or Flash Alerts -->
        <div v-if="status || $page.props.flash?.error || $page.props.flash?.success" class="mb-5 space-y-2">
            <div v-if="status || $page.props.flash?.success" class="text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/50 p-3.5 rounded-2xl border border-emerald-200 dark:border-emerald-800 flex items-center gap-2 shadow-xs">
                <svg class="w-4 h-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ status || $page.props.flash?.success }}</span>
            </div>

            <div v-if="$page.props.flash?.error" class="text-xs font-semibold text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/50 p-3.5 rounded-2xl border border-rose-200 dark:border-rose-800 flex items-center gap-2 shadow-xs">
                <svg class="w-4 h-4 shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ $page.props.flash?.error }}</span>
            </div>
        </div>

        <!-- 1. Authentication Method Selector Tabs -->
        <div class="mb-6">
            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">
                شیوه احراز هویت و ورود را انتخاب فرمایید:
            </label>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 p-1.5 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 text-xs font-bold">
                <!-- Method: Username -->
                <button
                    v-if="authMethods.username"
                    type="button"
                    @click="selectMethod('username')"
                    class="py-2.5 px-2 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="selectedMethod === 'username'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm border border-slate-200/60 dark:border-white/10'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>نام کاربری</span>
                </button>

                <!-- Method: Email -->
                <button
                    v-if="authMethods.email"
                    type="button"
                    @click="selectMethod('email')"
                    class="py-2.5 px-2 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="selectedMethod === 'email'
                        ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm border border-slate-200/60 dark:border-white/10'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span>ایمیل</span>
                </button>

                <!-- Method: LDAP -->
                <button
                    v-if="authMethods.ldap"
                    type="button"
                    @click="selectMethod('ldap')"
                    class="py-2.5 px-2 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="selectedMethod === 'ldap'
                        ? 'bg-white dark:bg-slate-900 text-purple-600 dark:text-purple-400 shadow-sm border border-slate-200/60 dark:border-white/10'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                    </svg>
                    <span>سامانه LDAP</span>
                </button>

                <!-- Method: Keycloak -->
                <button
                    v-if="authMethods.keycloak"
                    type="button"
                    @click="selectMethod('keycloak')"
                    class="py-2.5 px-2 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="selectedMethod === 'keycloak'
                        ? 'bg-white dark:bg-slate-900 text-amber-600 dark:text-amber-400 shadow-sm border border-slate-200/60 dark:border-white/10'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span>Keycloak SSO</span>
                </button>
            </div>
        </div>

        <!-- 2. Dynamic Input Fields Based on Selected Method -->
        <!-- Form for Username, Email, and LDAP Credentials -->
        <form v-if="selectedMethod !== 'keycloak'" @submit.prevent="submit" class="space-y-4">
            <!-- A. Username Provider Field -->
            <div v-if="selectedMethod === 'username'" class="space-y-1.5">
                <label for="username" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    شناسه کاربری:
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <input
                        id="username"
                        type="text"
                        v-model="form.username"
                        class="w-full pr-10 pl-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                        placeholder="شناسه کاربری خود را وارد فرمایید..."
                        required
                        autofocus
                        dir="ltr"
                        autocomplete="username"
                    />
                </div>
                <InputError class="mt-1" :message="form.errors.username" />
            </div>

            <!-- B. Email Provider Field -->
            <div v-if="selectedMethod === 'email'" class="space-y-1.5">
                <label for="email" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    نشانی پست الکترونیک (ایمیل):
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <input
                        id="email"
                        type="email"
                        v-model="form.email"
                        class="w-full pr-10 pl-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                        placeholder="name@example.com"
                        required
                        autofocus
                        dir="ltr"
                        autocomplete="email"
                    />
                </div>
                <InputError class="mt-1" :message="form.errors.email" />
            </div>

            <!-- C. LDAP Directory Field -->
            <div v-if="selectedMethod === 'ldap'" class="space-y-3">
                <div class="p-3 rounded-xl bg-purple-500/10 border border-purple-500/20 text-xs text-purple-700 dark:text-purple-300 flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>احراز هویت سازمانی از طریق اکتیو دایرکتوری و سرویس LDAP سازمان</span>
                </div>

                <div class="space-y-1.5">
                    <label for="ldap_username" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                        شناسه کاربری سازمانی (LDAP / Active Directory):
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <input
                            id="ldap_username"
                            type="text"
                            v-model="form.ldap_username"
                            class="w-full pr-10 pl-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            placeholder="نام کاربری سازمانی (مثال: j.doe)"
                            required
                            autofocus
                            dir="ltr"
                            autocomplete="username"
                        />
                    </div>
                    <InputError class="mt-1" :message="form.errors.ldap_username" />
                </div>
            </div>

            <!-- Password Field (Shared for Username, Email, and LDAP) -->
            <div class="space-y-1.5">
                <label for="password" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    {{ selectedMethod === 'ldap' ? 'کلمه عبور حساب سازمانی LDAP:' : 'کلمه عبور:' }}
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        v-model="form.password"
                        class="w-full pr-10 pl-10 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                        placeholder="••••••••••••"
                        required
                        dir="ltr"
                        autocomplete="current-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
                        @click="showPassword = !showPassword"
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
                <InputError class="mt-1" :message="selectedMethod === 'ldap' ? form.errors.ldap_password : form.errors.password" />
            </div>

            <!-- Remember Me and Forgot Password -->
            <div class="flex items-center justify-between pt-1">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        v-model="form.remember"
                        class="rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500 dark:bg-slate-800"
                    />
                    <span class="text-xs text-slate-600 dark:text-slate-400 font-medium">مرا به خاطر بسپار</span>
                </label>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-3 rounded-2xl bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white font-black text-sm shadow-md shadow-indigo-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
                >
                    <span v-if="form.processing">در حال اعتبارسنجی و ورود...</span>
                    <template v-else>
                        <span>
                            {{ selectedMethod === 'ldap' ? 'ورود با حساب سازمانی LDAP' : (selectedMethod === 'email' ? 'ورود با نشانی ایمیل' : 'ورود به سامانه') }}
                        </span>
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </template>
                </button>
            </div>
        </form>

        <!-- D. Keycloak SSO Dedicated Flow -->
        <div v-else class="space-y-4 py-2">
            <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-800 dark:text-amber-300 space-y-2">
                <div class="flex items-center gap-2 font-bold text-sm">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span>احراز هویت متمرکز سازمانی (Keycloak SSO)</span>
                </div>
                <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed">
                    با کلیک بر روی دکمه زیر، به صورت امن به سامانه ورود یکپارچه سازمانی منتقل خواهید شد و پس از احراز هویت، مجدداً به این درگاه هدایت می‌شوید.
                </p>
            </div>

            <a
                v-if="keycloakLoginUrl"
                :href="keycloakLoginUrl"
                class="w-full py-3.5 rounded-2xl bg-gradient-to-r from-amber-500 via-orange-500 to-amber-600 hover:from-amber-600 hover:to-orange-600 text-white font-black text-sm shadow-md shadow-amber-500/25 transition-all flex items-center justify-center gap-2.5 cursor-pointer"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                </svg>
                <span>ورود مستقیم از طریق Keycloak SSO</span>
            </a>
            <div v-else class="p-3 text-center text-xs text-rose-500 font-bold bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-200">
                درگاه ورود Keycloak هنوز توسط مدیر سامانه پیکربندی نشده است.
            </div>
        </div>

        <!-- Footer Links: Registration and Help -->
        <div class="mt-6 pt-5 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-between text-xs">
            <span class="text-slate-500 dark:text-slate-400">حساب کاربری ندارید؟</span>
            <Link :href="route('register')" class="font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                ایجاد حساب کاربری جدید
            </Link>
        </div>
    </AuthLayout>
</template>
