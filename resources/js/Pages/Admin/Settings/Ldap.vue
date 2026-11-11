<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref, computed } from 'vue';

interface Props {
    settings: {
        host: string;
        port: number;
        encryption: 'none' | 'tls' | 'ssl';
        base_dn: string;
        bind_dn: string;
        bind_password?: string;
        search_filter: string;
        uid_attribute: string;
        email_attribute: string;
        name_attribute: string;
        auto_create_user: boolean;
    };
    ldapExtensionLoaded: boolean;
    isEnabled: boolean;
}

const { __ } = useTrans();

const props = defineProps<Props>();

const ldapExtWarning = computed(() => __('admin.settings.ldap_ext_warning_desc', { ext: '<code class="bg-amber-100 dark:bg-amber-900 px-1 py-0.5 rounded">extension=ldap</code>' }));
const searchFilterHelp = computed(() => __('admin.settings.search_filter_help', { login: '<code class="text-purple-500">{login}</code>' }));

const form = useForm({
    host: props.settings.host || '',
    port: props.settings.port || 389,
    encryption: props.settings.encryption || 'none',
    base_dn: props.settings.base_dn || '',
    bind_dn: props.settings.bind_dn || '',
    bind_password: '',
    search_filter: props.settings.search_filter || '(&(objectClass=person)(|(uid={login})(mail={login})(sAMAccountName={login})))',
    uid_attribute: props.settings.uid_attribute || 'uid',
    email_attribute: props.settings.email_attribute || 'mail',
    name_attribute: props.settings.name_attribute || 'cn',
    auto_create_user: props.settings.auto_create_user,
});

const submit = () => {
    form.post(route('admin.settings.ldap.update'), {
        preserveScroll: true,
    });
};

const isTesting = ref(false);
const testResult = ref<{ success: boolean; message: string; details?: any } | null>(null);

const runConnectionTest = async () => {
    isTesting.value = true;
    testResult.value = null;

    try {
        const response = await axios.post(route('admin.settings.ldap.test'));
        testResult.value = response.data;
    } catch (e: any) {
        testResult.value = {
            success: false,
            message: e?.response?.data?.message || __('admin.settings.ldap_ajax_error'),
        };
    } finally {
        isTesting.value = false;
    }
};
</script>

<template>
    <Head :title="__('admin.settings.head_title_ldap')" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-black text-slate-900 dark:text-white">{{ __('admin.settings.ldap_title') }}</h1>
                        <span v-if="isEnabled" class="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">{{ __('admin.settings.gateway_active') }}</span>
                        <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-500/10 text-slate-500 border border-slate-500/20">{{ __('admin.settings.gateway_inactive') }}</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.ldap_desc') }}</p>
                </div>
            </div>
        </template>

        <div class="max-w-4xl mx-auto space-y-6 pb-12">
            <!-- Extension Warning if missing -->
            <div v-if="!ldapExtensionLoaded" class="p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300 flex items-center gap-3">
                <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div>
                    <span class="font-bold block">{{ __('admin.settings.ldap_ext_warning_title') }}</span>
                    <span v-html="ldapExtWarning"></span>
                </div>
            </div>

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
                    <span class="font-bold block">{{ testResult.success ? __('admin.settings.ldap_test_success') : __('admin.settings.ldap_test_failed') }}</span>
                    <span>{{ testResult.message }}</span>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Server Connection Specs Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.server_specs_title') }}</h2>
                        </div>
                        <button
                            type="button"
                            @click="runConnectionTest"
                            :disabled="isTesting"
                            class="px-3.5 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 dark:bg-purple-950/50 dark:hover:bg-purple-900/50 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800 text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
                        >
                            <span v-if="isTesting" class="w-3.5 h-3.5 border-2 border-purple-600 border-t-transparent rounded-full animate-spin"></span>
                            <svg v-else class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span>{{ __('admin.settings.test_connection') }}</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <label for="host" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.host_label') }}
                            </label>
                            <input
                                id="host"
                                type="text"
                                dir="ltr"
                                v-model="form.host"
                                placeholder="e.g. ldap.organization.local"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.host" />
                        </div>

                        <div>
                            <label for="port" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.port_label') }}
                            </label>
                            <input
                                id="port"
                                type="number"
                                dir="ltr"
                                v-model="form.port"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.port" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="encryption" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.encryption_label') }}
                            </label>
                            <select
                                id="encryption"
                                v-model="form.encryption"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            >
                                <option value="none">{{ __('admin.settings.enc_none') }}</option>
                                <option value="tls">{{ __('admin.settings.enc_tls') }}</option>
                                <option value="ssl">{{ __('admin.settings.enc_ssl') }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.encryption" />
                        </div>

                        <div>
                            <label for="base_dn" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.base_dn_label') }}
                            </label>
                            <input
                                id="base_dn"
                                type="text"
                                dir="ltr"
                                v-model="form.base_dn"
                                placeholder="dc=organization,dc=local"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.base_dn" />
                        </div>
                    </div>
                </div>

                <!-- Service Account Authentication (Bind) -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.bind_dn_title') }}</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.bind_dn_desc') }}</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="bind_dn" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.bind_dn_label') }}
                            </label>
                            <input
                                id="bind_dn"
                                type="text"
                                dir="ltr"
                                v-model="form.bind_dn"
                                placeholder="cn=admin,dc=organization,dc=local"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            />
                            <InputError class="mt-1" :message="form.errors.bind_dn" />
                        </div>

                        <div>
                            <label for="bind_password" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.bind_password_label') }}
                            </label>
                            <input
                                id="bind_password"
                                type="password"
                                dir="ltr"
                                v-model="form.bind_password"
                                placeholder="••••••••••••"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            />
                            <InputError class="mt-1" :message="form.errors.bind_password" />
                        </div>
                    </div>
                </div>

                <!-- User Search & Attribute Mapping -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <div class="pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.mapping_title') }}</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.mapping_desc') }}</p>
                    </div>

                    <div>
                        <label for="search_filter" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('admin.settings.search_filter_label') }}
                        </label>
                        <input
                            id="search_filter"
                            type="text"
                            dir="ltr"
                            v-model="form.search_filter"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-mono text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            required
                        />
                        <span class="text-[10px] text-slate-400 mt-1 block" v-html="searchFilterHelp"></span>
                        <InputError class="mt-1" :message="form.errors.search_filter" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="uid_attribute" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.uid_attr') }}
                            </label>
                            <input
                                id="uid_attribute"
                                type="text"
                                dir="ltr"
                                v-model="form.uid_attribute"
                                placeholder="uid or sAMAccountName"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.uid_attribute" />
                        </div>

                        <div>
                            <label for="email_attribute" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.email_attr') }}
                            </label>
                            <input
                                id="email_attribute"
                                type="text"
                                dir="ltr"
                                v-model="form.email_attribute"
                                placeholder="mail"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.email_attribute" />
                        </div>

                        <div>
                            <label for="name_attribute" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('admin.settings.name_attr') }}
                            </label>
                            <input
                                id="name_attribute"
                                type="text"
                                dir="ltr"
                                v-model="form.name_attribute"
                                placeholder="cn or displayName"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800 text-sm font-medium focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                required
                            />
                            <InputError class="mt-1" :message="form.errors.name_attribute" />
                        </div>
                    </div>

                    <!-- Auto Provisioning Toggle -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <div>
                            <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.jit_title') }}</h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.jit_desc') }}</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" v-model="form.auto_create_user" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-purple-600"></div>
                        </label>
                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-black text-xs shadow-md shadow-purple-500/25 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-50"
                    >
                        <span v-if="form.processing">{{ __('admin.settings.saving') }}</span>
                        <template v-else>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ __('admin.settings.save_ldap') }}</span>
                        </template>
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>

