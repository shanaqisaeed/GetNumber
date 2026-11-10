<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface UserIdentityData {
    id: number;
    provider: string;
    provider_label: string;
    provider_id: string;
    provider_email: string | null;
    linked_at: string | null;
}

interface Props {
    identities?: UserIdentityData[];
    authSettings?: {
        username_enabled: boolean;
        email_enabled: boolean;
        ldap_enabled: boolean;
        keycloak_enabled: boolean;
    };
    hasPassword?: boolean;
    keycloakLinkUrl?: string;
}

const props = withDefaults(defineProps<Props>(), {
    identities: () => [],
    authSettings: () => ({
        username_enabled: true,
        email_enabled: true,
        ldap_enabled: false,
        keycloak_enabled: false,
    }),
    hasPassword: true,
    keycloakLinkUrl: '',
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

const emailIdentity = computed(() => props.identities.find(i => i.provider === 'email'));
const ldapIdentity = computed(() => props.identities.find(i => i.provider === 'ldap'));
const keycloakIdentity = computed(() => props.identities.find(i => i.provider === 'keycloak'));

// Forms for linking
const emailForm = useForm({
    email: '',
    current_password: '',
});

const isEmailFormOpen = ref(false);

const submitLinkEmail = () => {
    emailForm.post(route('profile.identities.email'), {
        preserveScroll: true,
        onSuccess: () => {
            emailForm.reset();
            isEmailFormOpen.value = false;
        },
    });
};

const ldapForm = useForm({
    ldap_username: '',
    ldap_password: '',
});

const isLdapFormOpen = ref(false);

const submitLinkLdap = () => {
    ldapForm.post(route('profile.identities.ldap'), {
        preserveScroll: true,
        onSuccess: () => {
            ldapForm.reset();
            isLdapFormOpen.value = false;
        },
    });
};

// Unlink action
const unlinkingProvider = ref<string | null>(null);

const unlink = (provider: string) => {
    if (!confirm(`آیا از قطع اتصال این شیوه ورود (${provider}) اطمینان دارید؟`)) {
        return;
    }

    unlinkingProvider.value = provider;
    const form = useForm({});
    form.delete(route('profile.identities.unlink', provider), {
        preserveScroll: true,
        onFinish: () => {
            unlinkingProvider.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-6">
        <div>
            <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 shadow-sm shadow-indigo-500/50"></span>
                اتصال حساب‌ها و هویت‌های ورود چندگانه
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                شما می‌توانید چندین شیوه مختلف (نام کاربری، ایمیل، اکتیو دایرکتوری LDAP و Keycloak) را به این حساب کاربری متصل فرمایید تا با هرکدام امکان ورود داشته باشید.
            </p>
        </div>

        <div class="space-y-4">
            <!-- 1. Username Identity Card -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-xs font-bold text-slate-900 dark:text-white">شناسه کاربری سامانه</h4>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">متصل (اصلی)</span>
                        </div>
                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">@{{ user?.username }}</p>
                    </div>
                </div>
            </div>

            <!-- 2. Email Identity Card -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-xs font-bold text-slate-900 dark:text-white">نشانی پست الکترونیک (ایمیل)</h4>
                                <span v-if="emailIdentity || user?.email" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">متصل</span>
                                <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">متصل نیست</span>
                            </div>
                            <p v-if="user?.email" class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">{{ user?.email }}</p>
                            <p v-else class="text-[11px] text-slate-400 mt-0.5">اتصال ایمیل جهت ورود و دریافت اطلاعیه‌ها</p>
                        </div>
                    </div>

                    <div>
                        <button
                            v-if="emailIdentity || user?.email"
                            type="button"
                            @click="unlink('email')"
                            :disabled="unlinkingProvider === 'email'"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-rose-200 dark:border-rose-900/50 transition-all cursor-pointer"
                        >
                            قطع اتصال
                        </button>
                        <button
                            v-else
                            type="button"
                            @click="isEmailFormOpen = !isEmailFormOpen"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-blue-600 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/50 dark:hover:bg-blue-900/50 border border-blue-200 dark:border-blue-800 transition-all cursor-pointer"
                        >
                            {{ isEmailFormOpen ? 'انصراف' : 'اتصال ایمیل' }}
                        </button>
                    </div>
                </div>

                <!-- Inline Connect Email Form -->
                <form v-if="isEmailFormOpen && !user?.email" @submit.prevent="submitLinkEmail" class="p-3.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 space-y-3">
                    <div class="space-y-1">
                        <label for="link_email" class="block text-xs font-bold text-slate-700 dark:text-slate-300">نشانی ایمیل:</label>
                        <input
                            id="link_email"
                            type="email"
                            v-model="emailForm.email"
                            placeholder="name@company.com"
                            dir="ltr"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="emailForm.errors.email" />
                    </div>

                    <div v-if="hasPassword" class="space-y-1">
                        <label for="email_current_password" class="block text-xs font-bold text-slate-700 dark:text-slate-300">رمز عبور فعلی جهت تایید:</label>
                        <input
                            id="email_current_password"
                            type="password"
                            v-model="emailForm.current_password"
                            dir="ltr"
                            placeholder="••••••••••••"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="emailForm.errors.current_password" />
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            @click="isEmailFormOpen = false"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="emailForm.processing"
                            class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-sm transition-all"
                        >
                            تایید و اتصال ایمیل
                        </button>
                    </div>
                </form>
            </div>

            <!-- 3. LDAP Directory Identity Card -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-xs font-bold text-slate-900 dark:text-white">سامانه سازمانی LDAP</h4>
                                <span v-if="ldapIdentity" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">متصل</span>
                                <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">متصل نیست</span>
                            </div>
                            <p v-if="ldapIdentity" class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">UID: {{ ldapIdentity.provider_id }}</p>
                            <p v-else class="text-[11px] text-slate-400 mt-0.5">اتصال حساب کاربری دایرکتوری سازمانی</p>
                        </div>
                    </div>

                    <div>
                        <button
                            v-if="ldapIdentity"
                            type="button"
                            @click="unlink('ldap')"
                            :disabled="unlinkingProvider === 'ldap'"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-rose-200 dark:border-rose-900/50 transition-all cursor-pointer"
                        >
                            قطع اتصال
                        </button>
                        <button
                            v-else
                            type="button"
                            @click="isLdapFormOpen = !isLdapFormOpen"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-purple-600 hover:text-purple-700 bg-purple-50 hover:bg-purple-100 dark:bg-purple-950/50 dark:hover:bg-purple-900/50 border border-purple-200 dark:border-purple-800 transition-all cursor-pointer"
                        >
                            {{ isLdapFormOpen ? 'انصراف' : 'اتصال اکانت LDAP' }}
                        </button>
                    </div>
                </div>

                <!-- Inline Connect LDAP Form -->
                <form v-if="isLdapFormOpen && !ldapIdentity" @submit.prevent="submitLinkLdap" class="p-3.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 space-y-3">
                    <div class="space-y-1">
                        <label for="link_ldap_user" class="block text-xs font-bold text-slate-700 dark:text-slate-300">نام کاربری سازمانی (LDAP):</label>
                        <input
                            id="link_ldap_user"
                            type="text"
                            v-model="ldapForm.ldap_username"
                            placeholder="مثال: j.doe"
                            dir="ltr"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="ldapForm.errors.ldap_username" />
                    </div>

                    <div class="space-y-1">
                        <label for="link_ldap_pass" class="block text-xs font-bold text-slate-700 dark:text-slate-300">گذرواژه سازمانی:</label>
                        <input
                            id="link_ldap_pass"
                            type="password"
                            v-model="ldapForm.ldap_password"
                            dir="ltr"
                            placeholder="••••••••••••"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="ldapForm.errors.ldap_password" />
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            @click="isLdapFormOpen = false"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="ldapForm.processing"
                            class="px-4 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-sm transition-all"
                        >
                            احراز و اتصال حساب LDAP
                        </button>
                    </div>
                </form>
            </div>

            <!-- 4. Keycloak SSO Identity Card -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-xs font-bold text-slate-900 dark:text-white">ورود یکپارچه Keycloak SSO</h4>
                            <span v-if="keycloakIdentity" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">متصل</span>
                            <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">متصل نیست</span>
                        </div>
                        <p v-if="keycloakIdentity" class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">Sub: {{ keycloakIdentity.provider_id.slice(0, 14) }}...</p>
                        <p v-else class="text-[11px] text-slate-400 mt-0.5">اتصال حساب متمرکز سازمانی Keycloak</p>
                    </div>
                </div>

                <div>
                    <button
                        v-if="keycloakIdentity"
                        type="button"
                        @click="unlink('keycloak')"
                        :disabled="unlinkingProvider === 'keycloak'"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-rose-200 dark:border-rose-900/50 transition-all cursor-pointer"
                    >
                        قطع اتصال
                    </button>
                    <a
                        v-else-if="keycloakLinkUrl"
                        :href="keycloakLinkUrl"
                        class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-300 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/50 dark:hover:bg-amber-900/50 border border-amber-200 dark:border-amber-800 transition-all inline-flex items-center gap-1 cursor-pointer"
                    >
                        <span>اتصال به Keycloak</span>
                        <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

