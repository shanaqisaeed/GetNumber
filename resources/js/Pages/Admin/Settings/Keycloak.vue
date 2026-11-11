<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

interface Props {
    settings: {
        base_url: string;
        realm: string;
        client_id: string;
        client_secret?: string;
        auto_create_user: boolean;
        redirect_uri?: string;
    };
    callbackUrl: string;
    isEnabled: boolean;
}

const props = defineProps<Props>();

const form = useForm({
    base_url: props.settings.base_url || '',
    realm: props.settings.realm || 'master',
    client_id: props.settings.client_id || '',
    client_secret: '',
    auto_create_user: props.settings.auto_create_user,
});

const submit = () => {
    form.post(route('admin.settings.keycloak.update'), {
        preserveScroll: true,
    });
};

const isTesting = ref(false);
const testResult = ref<{ success: boolean; message: string; details?: any } | null>(null);
const copied = ref(false);

const runConnectionTest = async () => {
    isTesting.value = true;
    testResult.value = null;

    try {
        const response = await axios.post(route('admin.settings.keycloak.test'));
        testResult.value = response.data;
    } catch (e: any) {
        testResult.value = {
            success: false,
            message: e?.response?.data?.message || __('admin.settings.keycloak_ajax_error'),
        };
    } finally {
        isTesting.value = false;
    }
};

const copyCallback = () => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(props.callbackUrl);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 3000);
    }
};
</script>

<template>
    <Head :title="__('admin.settings.head_title_keycloak')" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-black text-slate-900 dark:text-white">{{ __('admin.settings.keycloak_title') }}</h1>
                        <span v-if="isEnabled" class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">{{ __('admin.settings.gateway_active') }}</span>
                        <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-500/10 text-slate-500 border border-slate-500/20">{{ __('admin.settings.gateway_inactive') }}</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.keycloak_desc') }}</p>
                </div>
            </div>
        </template>

        <div class="max-w-4xl mx-auto space-y-6 pb-12">
            <!-- Connection Test Result Banner -->
            <div v-if="testResult" class="p-4 rounded-2xl text-xs flex items-center gap-3 shadow-sm border"
                :class="testResult.success
                    ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300'
                    : 'bg-rose-50 dark:bg-rose-950/40 border-rose-300 dark:border-rose-800 text-rose-800 dark:text-rose-300'"
            >
                <svg v-if="testResult.success" class="w-5 h-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <svg v-else class="w-5 h-5 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <div>
                    <span class="font-bold block">{{ testResult.success ? __('admin.settings.keycloak_test_success') : __('admin.settings.keycloak_test_failed') }}</span>
                    <span>{{ testResult.message }}</span>
                </div>
            </div>

            <!-- Callback Redirect URI Helper Card -->
            <div class="p-5 rounded-3xl bg-amber-500/5 border border-amber-500/20 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ __('admin.settings.redirect_title') }}
                    </span>
                    <button
                        type="button"
                        @click="copyCallback"
                        class="text-xs font-bold text-amber-600 hover:text-amber-700 dark:text-amber-400 underline cursor-pointer"
                    >
                        {{ copied ? __('admin.users.copied') : __('admin.settings.copy_url') }}
                    </button>
                </div>
                <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-amber-500/30 font-mono text-xs text-slate-800 dark:text-slate-200 select-all" dir="ltr">
                    {{ callbackUrl }}
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                    <span v-html="__('admin.settings.redirect_desc_html')"></span>
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Realm and Base URL Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.realm_specs_title') }}</h2>
                        </div>
                        <button
                            type="button"
                            @click="runConnectionTest"
                            :disabled="isTesting"
                            class="px-3.5 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/50 dark:hover:bg-amber-900/50 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
                        >
                            <span v-if="isTesting" class="w-3.5 h-3.5 border-2 border-amber-600 border-t-transparent rounded-full animate-spin"></span>
                            <svg v-else class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span>{{ __('admin.settings.test_connection') }}</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="base_url" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.keycloak_url_label') }}
                            </label>
                            <input
                                id="base_url"
                                type="url"
                                dir="ltr"
                                v-model="form.base_url"
                                placeholder="https://keycloak.company.com"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.base_url" />
                        </div>

                        <div>
                            <label for="realm" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.realm_name_label') }}
                            </label>
                            <input
                                id="realm"
                                type="text"
                                dir="ltr"
                                v-model="form.realm"
                                placeholder="master"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.realm" />
                        </div>
                    </div>
                </div>

                <!-- OpenID Connect Client Credentials Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.client_credentials_title') }}</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.client_credentials_desc') }}</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="client_id" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.client_id_label') }}
                            </label>
                            <input
                                id="client_id"
                                type="text"
                                dir="ltr"
                                v-model="form.client_id"
                                placeholder="getnumber-client"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.client_id" />
                        </div>

                        <div>
                            <label for="client_secret" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.client_secret_label') }}
                            </label>
                            <input
                                id="client_secret"
                                type="password"
                                dir="ltr"
                                v-model="form.client_secret"
                                placeholder="••••••••••••"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                            />
                            <InputError class="mt-1" :message="form.errors.client_secret" />
                        </div>
                    </div>

                    <!-- Auto Provisioning -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div>
                            <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.keycloak_jit_title') }}</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.keycloak_jit_desc') }}</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" v-model="form.auto_create_user" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-amber-500"></div>
                        </label>
                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-black text-xs shadow-md shadow-amber-500/25 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-50"
                    >
                        <span v-if="form.processing">{{ __('admin.settings.saving') }}</span>
                        <template v-else>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ __('admin.settings.save_keycloak') }}</span>
                        </template>
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>

