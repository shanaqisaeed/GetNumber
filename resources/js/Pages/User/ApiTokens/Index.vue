<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface TokenItem {
    id: number;
    name: string;
    prefix: string;
    preview: string;
    status: 'active' | 'revoked';
    status_label: string;
    status_badge: string;
    is_active: boolean;
    is_expired: boolean;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
}

const props = defineProps<{
    tokens: TokenItem[];
    stats: {
        total: number;
        active: number;
        revoked: number;
    };
    plainTextToken?: string | null;
}>();

// Secret token modal state (when a new token was just created or rotated)
const revealedSecret = ref<string | null>(props.plainTextToken || null);
const isSecretModalOpen = ref<boolean>(!!props.plainTextToken);
const isCopied = ref<boolean>(false);

watch(() => props.plainTextToken, (val) => {
    if (val) {
        revealedSecret.value = val;
        isSecretModalOpen.value = true;
        isCopied.value = false;
    }
});

const copySecretToClipboard = async () => {
    if (!revealedSecret.value) return;
    try {
        await navigator.clipboard.writeText(revealedSecret.value);
        isCopied.value = true;
        setTimeout(() => {
            isCopied.value = false;
        }, 3000);
    } catch {
        const textArea = document.createElement('textarea');
        textArea.value = revealedSecret.value;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        isCopied.value = true;
        setTimeout(() => {
            isCopied.value = false;
        }, 3000);
    }
};

const closeSecretModal = () => {
    isSecretModalOpen.value = false;
    revealedSecret.value = null;
};

// Create Token Modal State
const isCreateModalOpen = ref(false);
const createForm = useForm({
    name: '',
    expires_days: '',
});

const openCreateModal = () => {
    createForm.reset();
    createForm.clearErrors();
    isCreateModalOpen.value = true;
};

const closeCreateModal = () => {
    isCreateModalOpen.value = false;
};

const submitCreate = () => {
    createForm.post(route('api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
        },
    });
};

// Rotate Confirmation Modal
const tokenToRotate = ref<TokenItem | null>(null);
const isRotateModalOpen = ref(false);
const rotateProcessing = ref(false);

const openRotateModal = (token: TokenItem) => {
    tokenToRotate.value = token;
    isRotateModalOpen.value = true;
};

const closeRotateModal = () => {
    isRotateModalOpen.value = false;
    tokenToRotate.value = null;
};

const confirmRotate = () => {
    if (!tokenToRotate.value) return;
    rotateProcessing.value = true;
    router.post(
        route('api-tokens.rotate', { token: tokenToRotate.value.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rotateProcessing.value = false;
                closeRotateModal();
            },
        }
    );
};

// Revoke Confirmation Modal
const tokenToRevoke = ref<TokenItem | null>(null);
const isRevokeModalOpen = ref(false);
const revokeProcessing = ref(false);

const openRevokeModal = (token: TokenItem) => {
    tokenToRevoke.value = token;
    isRevokeModalOpen.value = true;
};

const closeRevokeModal = () => {
    isRevokeModalOpen.value = false;
    tokenToRevoke.value = null;
};

const confirmRevoke = () => {
    if (!tokenToRevoke.value) return;
    revokeProcessing.value = true;
    router.post(
        route('api-tokens.revoke', { token: tokenToRevoke.value.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                revokeProcessing.value = false;
                closeRevokeModal();
            },
        }
    );
};

// Delete Confirmation Modal
const tokenToDelete = ref<TokenItem | null>(null);
const isDeleteModalOpen = ref(false);
const deleteProcessing = ref(false);

const openDeleteModal = (token: TokenItem) => {
    tokenToDelete.value = token;
    isDeleteModalOpen.value = true;
};

const closeDeleteModal = () => {
    isDeleteModalOpen.value = false;
    tokenToDelete.value = null;
};

const confirmDelete = () => {
    if (!tokenToDelete.value) return;
    deleteProcessing.value = true;
    router.delete(
        route('api-tokens.destroy', { token: tokenToDelete.value.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                deleteProcessing.value = false;
                closeDeleteModal();
            },
        }
    );
};

const activeTab = ref<'bearer' | 'apikey'>('bearer');
</script>

<template>
    <UserLayout>
        <Head title="مدیریت کلیدهای ارتباطی (API)" />

        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm transition-colors">
                <div class="space-y-1">
                    <div class="flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800/60">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                        </span>
                        <h1 class="text-xl font-black text-slate-900 dark:text-white">کلیدهای ارتباطی و وب‌سرویس (API)</h1>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed pr-10">
                        تولید و مدیریت توکن‌های امن رمزنگاری‌شده (SHA-256) جهت اتصال سامانه‌ها و نرم‌افزارهای خارجی به وب‌سرویس خطوط مجازی.
                    </p>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-500 hover:to-indigo-600 shadow-lg shadow-indigo-600/25 dark:shadow-indigo-950/50 transition-all hover:scale-[1.02] active:scale-[0.98]"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>صدور کلید جدید</span>
                    </button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">کل کلیدهای صادر شده</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.total }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-100 dark:border-indigo-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">کلیدهای فعال و معتبر</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.active }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center border border-emerald-100 dark:border-emerald-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-rose-600 dark:text-rose-400">باطل‌شده یا منقضی</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.revoked }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center border border-rose-100 dark:border-rose-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Guide drawer -->
            <div class="bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950 text-white rounded-2xl p-6 border border-slate-800 shadow-xl overflow-hidden relative">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/40 font-mono">
                                API v1.0
                            </span>
                            <h2 class="text-base font-bold text-white">راهنمای اتصال به وب‌سرویس</h2>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">
                            تمامی درخواست‌ها باید با پروتکل HTTPS و همراه با هدر Authorization یا X-API-Key ارسال گردند (سقف ۶۰ درخواست در دقیقه).
                        </p>
                    </div>

                    <div class="flex items-center gap-2 bg-slate-800/80 p-1 rounded-xl border border-slate-700/60 shrink-0">
                        <button
                            type="button"
                            @click="activeTab = 'bearer'"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                            :class="activeTab === 'bearer' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'"
                        >
                            Authorization: Bearer
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'apikey'"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                            :class="activeTab === 'apikey' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'"
                        >
                            X-API-Key
                        </button>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="bg-slate-950/80 rounded-xl p-4 border border-slate-800 font-mono text-xs text-slate-200">
                        <div class="flex items-center justify-between mb-2 text-slate-400 text-[11px]">
                            <span>تست اتصال و احراز هویت (Ping)</span>
                            <span class="text-emerald-400 font-bold">GET</span>
                        </div>
                        <div class="overflow-x-auto text-left dir-ltr py-1 text-indigo-300 select-all">
                            <template v-if="activeTab === 'bearer'">
                                curl -X GET "https://your-domain.com/api/v1/ping" \<br />
                                &nbsp;&nbsp;-H "Authorization: Bearer gn_live_YOUR_TOKEN" \<br />
                                &nbsp;&nbsp;-H "Accept: application/json"
                            </template>
                            <template v-else>
                                curl -X GET "https://your-domain.com/api/v1/ping" \<br />
                                &nbsp;&nbsp;-H "X-API-Key: gn_live_YOUR_TOKEN" \<br />
                                &nbsp;&nbsp;-H "Accept: application/json"
                            </template>
                        </div>
                    </div>

                    <div class="bg-slate-950/80 rounded-xl p-4 border border-slate-800 space-y-2.5">
                        <div class="text-slate-400 text-[11px] font-semibold">متدهای وب‌سرویس در دسترس:</div>
                        <div class="flex items-center justify-between text-xs py-1 border-b border-slate-800/80">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-400 font-mono">GET</span>
                                <span class="font-mono text-slate-300 dir-ltr">/api/v1/ping</span>
                            </div>
                            <span class="text-[11px] text-slate-400">آزمون اتصال و وضعیت حساب</span>
                        </div>
                        <div class="flex items-center justify-between text-xs py-1">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-indigo-500/20 text-indigo-400 font-mono">GET</span>
                                <span class="font-mono text-slate-300 dir-ltr">/api/v1/numbers</span>
                            </div>
                            <span class="text-[11px] text-slate-400">دریافت خطوط فعال کاربر</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tokens List Table -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h2 class="font-black text-slate-900 dark:text-white text-base">فهرست کلیدهای ارتباطی شما</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">کلیدهای فعال جهت فراخوانی متدهای وب‌سرویس استفاده می‌شوند.</p>
                    </div>
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                        {{ tokens.length }} کلید
                    </span>
                </div>

                <!-- Empty State -->
                <div v-if="tokens.length === 0" class="p-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">هنوز کلید دسترسی صادر نکرده‌اید</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                        برای اتصال سامانه‌ها و نرم‌افزارهای خود، اولین کلید ارتباطی را صادر کنید.
                    </p>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="mt-5 inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-md shadow-indigo-600/20"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>صدور اولین کلید دسترسی</span>
                    </button>
                </div>

                <!-- Table Content -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="py-3.5 px-4">عنوان کلید / سامانه متصل</th>
                                <th class="py-3.5 px-4">پیشوند و شناسه</th>
                                <th class="py-3.5 px-4">وضعیت</th>
                                <th class="py-3.5 px-4">تاریخ انقضا</th>
                                <th class="py-3.5 px-4">آخرین فراخوانی</th>
                                <th class="py-3.5 px-4">تاریخ صدور</th>
                                <th class="py-3.5 px-4 text-center">عملیات مدیریت</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="token in tokens"
                                :key="token.id"
                                class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="py-4 px-4 font-bold text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full" :class="token.is_active && !token.is_expired ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                                        <span>{{ token.name }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="font-mono text-xs text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded-md dir-ltr inline-block">
                                        {{ token.preview }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold"
                                        :class="token.status_badge"
                                    >
                                        {{ token.status_label }}
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400">
                                    <span v-if="token.expires_at">{{ token.expires_at }}</span>
                                    <span v-else class="text-slate-400 dark:text-slate-500">دائمی (بدون انقضا)</span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400">
                                    <span v-if="token.last_used_at">{{ token.last_used_at }}</span>
                                    <span v-else class="text-slate-400 italic">هنوز استفاده نشده</span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400 font-mono">
                                    {{ token.created_at }}
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button
                                            v-if="token.is_active"
                                            type="button"
                                            @click="openRotateModal(token)"
                                            class="p-2 text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/60 rounded-xl transition"
                                            title="تعویض کلید (Rotate)"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                        </button>

                                        <button
                                            v-if="token.is_active"
                                            type="button"
                                            @click="openRevokeModal(token)"
                                            class="p-2 text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/60 rounded-xl transition"
                                            title="ابطال دسترسی"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            @click="openDeleteModal(token)"
                                            class="p-2 text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/60 rounded-xl transition"
                                            title="حذف کلید"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ONE-TIME RAW SECRET MODAL -->
        <div
            v-if="isSecretModalOpen && revealedSecret"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-xl rounded-3xl border border-indigo-500/30 shadow-2xl overflow-hidden p-6 sm:p-8 space-y-6">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-amber-500/10 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white">کلید دسترسی جدید با موفقیت صادر شد!</h3>
                        <p class="text-xs text-rose-600 dark:text-rose-400 font-bold mt-1">
                            هشدار امنیتی مهم: این کلید به صورت امن هش شده و تنها همین یک‌بار نمایش داده می‌شود!
                        </p>
                    </div>
                </div>

                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    لطفاً فوراً مقدار توکن زیر را کپی کرده و در محل امنی ذخیره فرمایید. پس از بستن این پنجره، به هیچ وجه امکان مشاهده مجدد این کلید وجود نخواهد داشت.
                </p>

                <div class="bg-slate-950 rounded-2xl p-4 border border-indigo-500/40 relative group">
                    <div class="flex items-center justify-between text-xs text-slate-400 mb-2">
                        <span class="font-bold text-indigo-400">کلید دسترسی خام (Secret Token):</span>
                        <span class="font-mono text-[11px] text-slate-500">SHA-256 Hashed on Server</span>
                    </div>

                    <div class="font-mono text-xs sm:text-sm text-emerald-400 break-all dir-ltr select-all p-3 bg-slate-900/90 rounded-xl border border-slate-800">
                        {{ revealedSecret }}
                    </div>

                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-[11px] text-slate-400">
                            {{ isCopied ? 'کلید با موفقیت کپی شد!' : 'روی دکمه مقابل کلیک کنید:' }}
                        </span>
                        <button
                            type="button"
                            @click="copySecretToClipboard"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all"
                            :class="isCopied ? 'bg-emerald-600 text-white' : 'bg-indigo-600 text-white hover:bg-indigo-500'"
                        >
                            <svg v-if="!isCopied" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ isCopied ? 'کپی شد ✓' : 'کپی کلید' }}</span>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button
                        type="button"
                        @click="closeSecretModal"
                        class="w-full py-3 px-4 rounded-xl font-bold text-sm text-white bg-slate-900 dark:bg-slate-800 hover:bg-slate-800 dark:hover:bg-slate-700 border border-slate-700 transition"
                    >
                        کلید را ذخیره کردم و پنجره را می‌بندم
                    </button>
                </div>
            </div>
        </div>

        <!-- CREATE TOKEN MODAL -->
        <div
            v-if="isCreateModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-lg rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 sm:p-7 space-y-6">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-4">
                    <div class="flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </span>
                        <h3 class="font-bold text-base text-slate-900 dark:text-white">صدور کلید ارتباطی جدید (API Token)</h3>
                    </div>
                    <button
                        type="button"
                        @click="closeCreateModal"
                        class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitCreate" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            عنوان کلید دسترسی یا نام سامانه متصل <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="createForm.name"
                            type="text"
                            placeholder="مثال: سرور ارسال وب‌هوک، سیستم CRM شرکت، ربات تلگرام"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                            required
                        />
                        <p v-if="createForm.errors.name" class="text-xs text-rose-500 mt-1 font-semibold">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            مدت زمان اعتبار کلید
                        </label>
                        <select
                            v-model="createForm.expires_days"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                        >
                            <option value="">دائمی (بدون انقضا - توصیه برای سرورهای پس‌زمینه)</option>
                            <option value="30">۳۰ روز (یک ماه)</option>
                            <option value="60">۶۰ روز (دو ماه)</option>
                            <option value="90">۹۰ روز (سه ماه)</option>
                            <option value="180">۱۸۰ روز (شش ماه)</option>
                            <option value="365">۳۶۵ روز (یک سال)</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-200 dark:border-slate-800">
                        <button
                            type="button"
                            @click="closeCreateModal"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 transition shadow-md shadow-indigo-600/20"
                        >
                            {{ createForm.processing ? 'در حال صدور...' : 'تولید و صدور کلید' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ROTATE CONFIRMATION MODAL -->
        <div
            v-if="isRotateModalOpen && tokenToRotate"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-100 dark:border-indigo-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">تعویض کلید دسترسی (Rotate)</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از تعویض کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToRotate.name }}»</span> اطمینان دارید؟
                        رمز عبور توکن فعلی بلافاصله نامعتبر شده و کلید جدیدی صادر خواهد شد.
                    </p>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="closeRotateModal"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRotate"
                        :disabled="rotateProcessing"
                        class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 transition"
                    >
                        {{ rotateProcessing ? 'در حال تعویض...' : 'تایید و صدور کلید جدید' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- REVOKE CONFIRMATION MODAL -->
        <div
            v-if="isRevokeModalOpen && tokenToRevoke"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center border border-amber-100 dark:border-amber-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">ابطال دسترسی کلید</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از ابطال کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToRevoke.name }}»</span> اطمینان دارید؟
                        تمام سیستم‌های متصل با این توکن مسدود خواهند شد.
                    </p>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="closeRevokeModal"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRevoke"
                        :disabled="revokeProcessing"
                        class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 disabled:opacity-50 transition"
                    >
                        {{ revokeProcessing ? 'در حال ابطال...' : 'تایید ابطال کلید' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- DELETE CONFIRMATION MODAL -->
        <div
            v-if="isDeleteModalOpen && tokenToDelete"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center border border-rose-100 dark:border-rose-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">حذف کامل کلید</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از حذف دائم رکورد کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToDelete.name }}»</span> اطمینان دارید؟
                    </p>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="closeDeleteModal"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmDelete"
                        :disabled="deleteProcessing"
                        class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50 transition"
                    >
                        {{ deleteProcessing ? 'در حال حذف...' : 'حذف قطعی' }}
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
