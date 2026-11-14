<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';

interface EmailAlias {
    id: number;
    alias_address: string;
    forward_to: string;
    is_active: boolean;
    created_at: string;
}

interface Mailbox {
    id: number;
    email_address: string;
    quota_mb: number;
    used_mb: number;
    is_catch_all: boolean;
    aliases: EmailAlias[];
    created_at: string;
}

interface Web3DomainItem {
    id: number;
    full_domain: string;
    domain_name: string;
    tld: string;
    status: string;
    max_sub_emails: number;
    mailboxes: Mailbox[];
}

const props = defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const domains = ref<Web3DomainItem[]>([]);
const selectedDomainId = ref<number | null>(null);
const isLoading = ref(false);
const actionMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null);

// Create Mailbox State
const isNewMailboxOpen = ref(false);
const newMailboxLocalPart = ref('');
const isCatchAll = ref(false);
const isSubmittingMailbox = ref(false);

// Create Alias State
const activeMailboxForAlias = ref<Mailbox | null>(null);
const newAliasAddress = ref('');
const newAliasForwardTo = ref('');
const isSubmittingAlias = ref(false);

const loadDomains = async () => {
    isLoading.value = true;
    try {
        const res = await axios.get(route('web3-domains.index'));
        domains.value = res.data.domains || [];
        if (domains.value.length > 0 && !selectedDomainId.value) {
            selectedDomainId.value = domains.value[0].id;
        }
    } catch (err) {
        console.error('Failed to load Web3 domains:', err);
    } finally {
        isLoading.value = false;
    }
};

const currentDomain = ref<Web3DomainItem | null>(null);
onMounted(() => {
    if (props.isOpen) loadDomains();
});

const selectDomain = (domain: Web3DomainItem) => {
    selectedDomainId.value = domain.id;
    currentDomain.value = domain;
};

const createMailbox = async () => {
    if (!selectedDomainId.value || !newMailboxLocalPart.value.trim()) return;
    isSubmittingMailbox.value = true;
    actionMessage.value = null;

    try {
        const res = await axios.post(route('web3-domains.mailboxes.store', selectedDomainId.value), {
            local_part: newMailboxLocalPart.value.trim(),
            quota_mb: 500,
            is_catch_all: isCatchAll.value,
        });
        if (res.data.success) {
            actionMessage.value = { type: 'success', text: res.data.message };
            newMailboxLocalPart.value = '';
            isNewMailboxOpen.value = false;
            await loadDomains();
        }
    } catch (err: any) {
        actionMessage.value = { type: 'error', text: err.response?.data?.message || 'خطا در ایجاد صندوق ایمیل' };
    } finally {
        isSubmittingMailbox.value = false;
    }
};

const openAddAliasModal = (mailbox: Mailbox) => {
    activeMailboxForAlias.value = mailbox;
    newAliasAddress.value = '';
    newAliasForwardTo.value = '';
};

const createAlias = async () => {
    if (!activeMailboxForAlias.value || !newAliasAddress.value.trim()) return;
    isSubmittingAlias.value = true;
    actionMessage.value = null;

    try {
        const res = await axios.post(route('mailboxes.aliases.store', activeMailboxForAlias.value.id), {
            alias_address: newAliasAddress.value.trim(),
            forward_to: newAliasForwardTo.value.trim() || undefined,
        });
        if (res.data.success) {
            actionMessage.value = { type: 'success', text: res.data.message };
            activeMailboxForAlias.value = null;
            await loadDomains();
        }
    } catch (err: any) {
        actionMessage.value = { type: 'error', text: err.response?.data?.message || 'خطا در ثبت نام مستعار' };
    } finally {
        isSubmittingAlias.value = false;
    }
};

const deleteAlias = async (aliasId: number) => {
    if (!confirm('آیا از حذف این نام مستعار اطمینان دارید؟')) return;
    try {
        const res = await axios.delete(route('aliases.destroy', aliasId));
        if (res.data.success) {
            actionMessage.value = { type: 'success', text: res.data.message };
            await loadDomains();
        }
    } catch (err: any) {
        actionMessage.value = { type: 'error', text: 'خطا در حذف نام مستعار' };
    }
};
</script>

<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4"
    >
        <div class="bg-white dark:bg-gray-800 rounded-3xl max-w-3xl w-full shadow-2xl border border-gray-100 dark:border-gray-700/60 overflow-hidden text-right">
            <!-- Modal Header -->
            <div class="p-6 bg-gradient-to-r from-emerald-900 to-teal-900 text-white flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-white/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold">موتور مدیریت ساب‌ایمیل دامنه‌های Web3</h3>
                        <p class="text-xs text-emerald-200">تعریف صندوق، ساب‌الایس‌ها و نام‌های مستعار نامحدود</p>
                    </div>
                </div>
                <button
                    type="button"
                    @click="emit('close')"
                    class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors"
                >
                    &times;
                </button>
            </div>

            <!-- Body -->
            <div class="p-6 space-y-6">
                <!-- Action Alert -->
                <div
                    v-if="actionMessage"
                    class="p-3 rounded-xl text-xs font-semibold"
                    :class="actionMessage.type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'"
                >
                    {{ actionMessage.text }}
                </div>

                <!-- Domains Tabs / Selector -->
                <div v-if="domains.length === 0" class="py-12 text-center text-sm text-gray-400">
                    <p>هنوز هیچ دامنه Web3 فعالی در حساب شما ثبت نشده است.</p>
                    <p class="text-xs mt-1">از طریق دکمه «سفارش سرویس‌های ویژه» می‌توانید دامنه اختصاصی خود را تهیه نمایید.</p>
                </div>

                <div v-else class="space-y-6">
                    <!-- Domain Switcher -->
                    <div class="flex items-center gap-2 overflow-x-auto pb-2">
                        <button
                            v-for="d in domains"
                            :key="d.id"
                            type="button"
                            @click="selectDomain(d)"
                            class="px-4 py-2 rounded-xl text-xs font-bold font-mono transition-all border shrink-0"
                            :class="selectedDomainId === d.id ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'"
                        >
                            {{ d.full_domain }}
                        </button>
                    </div>

                    <!-- Selected Domain Controls -->
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-white">
                            صندوق‌های ایمیل فعال زیر دامنه
                        </h4>
                        <button
                            type="button"
                            @click="isNewMailboxOpen = true"
                            class="px-3 py-1.5 text-xs font-bold rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 transition-colors shadow-sm"
                        >
                            + ایجاد صندوق ایمیل جدید
                        </button>
                    </div>

                    <!-- Create Mailbox Form (Collapsible) -->
                    <div v-if="isNewMailboxOpen" class="p-4 rounded-2xl bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600 space-y-3">
                        <h5 class="text-xs font-bold text-gray-800 dark:text-gray-200">افزودن صندوق ایمیل جدید</h5>
                        <div class="flex items-center gap-2">
                            <input
                                v-model="newMailboxLocalPart"
                                type="text"
                                placeholder="مثال: support یا ceo"
                                class="flex-1 text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                            <span class="text-xs font-mono font-bold text-gray-500">
                                @{{ domains.find(d => d.id === selectedDomainId)?.full_domain }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between pt-2">
                            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer">
                                <input v-model="isCatchAll" type="checkbox" class="w-4 h-4 text-emerald-600 rounded" />
                                <span>صندوق پیش‌فرض دریافت همه ایمیل‌ها (Catch-All)</span>
                            </label>
                            <div class="flex gap-2">
                                <button type="button" @click="isNewMailboxOpen = false" class="px-3 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-200">انصراف</button>
                                <button type="button" :disabled="isSubmittingMailbox || !newMailboxLocalPart" @click="createMailbox" class="px-4 py-1 text-xs font-bold rounded-lg text-white bg-emerald-600 hover:bg-emerald-700">
                                    {{ isSubmittingMailbox ? 'در حال ایجاد...' : 'ثبت صندوق' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Mailboxes List -->
                    <div class="space-y-4">
                        <template v-for="domain in domains" :key="domain.id">
                            <div v-if="domain.id === selectedDomainId" class="space-y-3">
                                <div v-if="domain.mailboxes.length === 0" class="py-8 text-center text-xs text-gray-400">
                                    هیچ صندوقی برای این دامنه تعریف نشده است.
                                </div>

                                <div
                                    v-for="mbox in domain.mailboxes"
                                    :key="mbox.id"
                                    class="p-4 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm space-y-3"
                                >
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                                            <span class="font-mono font-bold text-sm text-gray-900 dark:text-white">{{ mbox.email_address }}</span>
                                            <span v-if="mbox.is_catch_all" class="text-[10px] px-2 py-0.5 rounded bg-purple-100 text-purple-700 font-semibold">Catch-All</span>
                                        </div>
                                        <button
                                            type="button"
                                            @click="openAddAliasModal(mbox)"
                                            class="px-2.5 py-1 text-[11px] font-bold rounded-lg text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100"
                                        >
                                            + ساب‌الایس (نام مستعار)
                                        </button>
                                    </div>

                                    <!-- Aliases List under this Mailbox -->
                                    <div v-if="mbox.aliases && mbox.aliases.length > 0" class="pt-2 border-t border-gray-100 dark:border-gray-700/60 space-y-1.5">
                                        <div class="text-[11px] font-semibold text-gray-400">ساب‌الایس‌ها و فورواردها:</div>
                                        <div
                                            v-for="al in mbox.aliases"
                                            :key="al.id"
                                            class="flex items-center justify-between py-1 px-2.5 rounded-lg bg-gray-50 dark:bg-gray-700/30 text-xs"
                                        >
                                            <div class="font-mono text-gray-700 dark:text-gray-300">
                                                <span>{{ al.alias_address }}</span>
                                                <span v-if="al.forward_to" class="text-gray-400 text-[11px] mr-2">&rarr; فوروارد به: {{ al.forward_to }}</span>
                                            </div>
                                            <button
                                                type="button"
                                                @click="deleteAlias(al.id)"
                                                class="text-rose-500 hover:text-rose-700 text-xs px-1"
                                                title="حذف نام مستعار"
                                            >
                                                &times;
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Alias Sub-Modal -->
        <div v-if="activeMailboxForAlias" class="fixed inset-0 z-60 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-sm w-full p-5 shadow-2xl border border-gray-100 dark:border-gray-700 text-right">
                <h4 class="text-sm font-bold text-gray-900 dark:text-white">افزودن نام مستعار برای {{ activeMailboxForAlias.email_address }}</h4>
                <div class="mt-3 space-y-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">نام مستعار درخواستی</label>
                        <input
                            v-model="newAliasAddress"
                            type="text"
                            placeholder="مثال: info@mydomain.web3"
                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">فوروارد خودکار به ایمیل خارجی (اختیاری)</label>
                        <input
                            v-model="newAliasForwardTo"
                            type="email"
                            placeholder="myemail@gmail.com"
                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" @click="activeMailboxForAlias = null" class="px-3 py-1.5 text-xs text-gray-600 bg-gray-100 dark:bg-gray-700 rounded-xl">انصراف</button>
                    <button type="button" :disabled="isSubmittingAlias || !newAliasAddress" @click="createAlias" class="px-4 py-1.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">
                        {{ isSubmittingAlias ? 'در حال ثبت...' : 'ثبت ساب‌الایس' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

