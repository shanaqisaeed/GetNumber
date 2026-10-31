<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';

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

// Secret token modal state
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

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;
const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
};

const copySecretToClipboard = async () => {
    if (!revealedSecret.value) return;
    try {
        await navigator.clipboard.writeText(revealedSecret.value);
        isCopied.value = true;
        showToast('کلید امنیتی در کلیپ‌بورد کپی شد');
        setTimeout(() => {
            isCopied.value = false;
        }, 3000);
    } catch {
        showToast('کلید کپی شد');
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
    )
};
const activeTab = ref<'bearer' | 'apikey'>('bearer');

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isSecretModalOpen.value) closeSecretModal();
        if (isCreateModalOpen.value) closeCreateModal();
        if (isRotateModalOpen.value) closeRotateModal();
        if (isRevokeModalOpen.value) closeRevokeModal();
        if (isDeleteModalOpen.value) closeDeleteModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <UserLayout>
        <Head title="کلیدهای وب‌سرویس من (API)" />

        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4 scale-95" enter-to-class="opacity-100 translate-y-0 scale-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 -translate-y-2 scale-95">
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
                >
                    <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-4">
            <!-- 1. Header Banner -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <span class="wallet-title">کلیدهای ارتباطی وب‌سرویس</span>
                    <span class="wallet-chip bg-cyan-500/15 border-cyan-500/35 text-cyan-600 dark:text-cyan-400">
                        REST API v1
                    </span>
                </div>

                <div class="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    تولید توکن‌های امن رمزنگاری‌شده (SHA-256) جهت اتصال نرم‌افزارها و سامانه‌های خارجی به خطوط سازمانی.
                </div>

                <!-- Stats Pill Row -->
                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 text-center">
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-bold">کل توکن‌ها</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.total }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 block font-bold">فعال</span>
                        <span class="text-sm font-black font-mono text-emerald-600 dark:text-emerald-400">{{ stats.active }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-rose-600 dark:text-rose-400 block font-bold">منقضی/باطل</span>
                        <span class="text-sm font-black font-mono text-rose-600 dark:text-rose-400">{{ stats.revoked }}</span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 flex flex-col sm:flex-row gap-2 justify-end">
                    <a
                        :href="route('api.docs')"
                        target="_blank"
                        class="glass-pill !py-2.5 !px-3 !text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 transition-colors flex items-center justify-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                        <span>مشاهده مستندات وب‌سرویس API</span>
                    </a>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="glass-btn-primary !w-full sm:!w-auto !py-2.5 !px-4 !text-xs !rounded-xl flex items-center justify-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>تولید کلید جدید (API Token)</span>
                    </button>
                </div>
            </div>

            <!-- 2. Tokens List Cards -->
            <div v-if="tokens.length > 0" class="space-y-3">
                <div
                    v-for="token in tokens"
                    :key="token.id"
                    class="glass-card !p-4 hover:border-cyan-500/40 transition-all space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                >
                    <!-- Header: Name & Status -->
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <div class="w-8 h-8 rounded-xl bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center shrink-0 border border-cyan-500/25">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                </svg>
                            </div>
                            <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                                {{ token.name }}
                            </span>
                        </div>

                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shadow-xs shrink-0" :class="token.status_badge">
                            {{ token.status_label }}
                        </span>
                    </div>

                    <!-- Token Prefix & Preview -->
                    <div class="p-2.5 rounded-xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10 flex items-center justify-between">
                        <div dir="ltr" class="font-mono text-xs text-cyan-700 dark:text-cyan-300 font-bold select-all truncate">
                            {{ token.preview }}
                        </div>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-mono shrink-0 mr-2">SHA-256</span>
                    </div>

                    <!-- Metadata Row -->
                    <div class="grid grid-cols-2 gap-2 text-[11px] text-slate-500 dark:text-slate-400 pt-1">
                        <div>
                            <span>تاریخ ایجاد:</span>
                            <span class="font-mono text-slate-700 dark:text-slate-300 mr-1">{{ token.created_at }}</span>
                        </div>
                        <div class="text-left" dir="ltr">
                            <span class="font-mono text-slate-700 dark:text-slate-300 ml-1">{{ token.last_used_at || 'Never' }}</span>
                            <span>:Last used</span>
                        </div>
                    </div>

                    <!-- Actions Row -->
                    <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-xs">
                        <button
                            v-if="token.is_active"
                            type="button"
                            @click="openRotateModal(token)"
                            class="font-bold text-amber-600 dark:text-amber-400 hover:text-amber-700 dark:hover:text-amber-300 transition-colors"
                        >
                            تغییر و تمدید کلید
                        </button>
                        <span v-else class="text-[10px] text-rose-500 dark:text-rose-400 font-semibold">غیرفعال‌شده</span>

                        <div class="flex items-center gap-2.5">
                            <button
                                v-if="token.is_active"
                                type="button"
                                @click="openRevokeModal(token)"
                                class="font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300 transition-colors"
                            >
                                باطل‌سازی
                            </button>
                            <button
                                type="button"
                                @click="openDeleteModal(token)"
                                class="font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors"
                            >
                                حذف
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="glass-card !p-8 text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="w-14 h-14 rounded-3xl bg-cyan-500/15 border border-cyan-500/30 text-cyan-600 dark:text-cyan-400 mx-auto flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">هنوز کلیدی تولید نکرده‌اید</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                        برای دسترسی برنامه‌نویسی به خطوط و ارسال خودکار پیامک از طریق API، کلید اختصاصی بسازید.
                    </p>
                </div>
                <button
                    type="button"
                    @click="openCreateModal"
                    class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl inline-flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>تولید اولین کلید API</span>
                </button>
            </div>

            <!-- 3. Quick Integration Guide -->
            <div class="glass-card !p-4 space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="flex items-center justify-between pb-2 border-b border-slate-200/80 dark:border-white/10">
                    <span class="text-xs font-bold text-slate-900 dark:text-white">راهنمای فراخوانی وب‌سرویس</span>
                    <div class="flex gap-1 text-[10px]">
                        <button
                            type="button"
                            @click="activeTab = 'bearer'"
                            class="px-2 py-0.5 rounded-lg font-bold transition-colors"
                            :class="activeTab === 'bearer' ? 'bg-cyan-500/20 text-cyan-600 dark:text-cyan-400 border border-cyan-500/30' : 'text-slate-500 dark:text-slate-400'"
                        >
                            Bearer Header
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'apikey'"
                            class="px-2 py-0.5 rounded-lg font-bold transition-colors"
                            :class="activeTab === 'apikey' ? 'bg-cyan-500/20 text-cyan-600 dark:text-cyan-400 border border-cyan-500/30' : 'text-slate-500 dark:text-slate-400'"
                        >
                            X-API-Key
                        </button>
                    </div>
                </div>

                <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-black/40 border border-slate-200 dark:border-white/5 font-mono text-[11px] text-slate-800 dark:text-cyan-300 select-all overflow-x-auto" dir="ltr">
                    <code v-if="activeTab === 'bearer'">Authorization: Bearer YOUR_TOKEN_SECRET</code>
                    <code v-else>X-API-KEY: YOUR_TOKEN_SECRET</code>
                </div>
            </div>
        </div>

        <!-- 4. One-Time Reveal Secret Modal -->
        <div v-if="isSecretModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeSecretModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 mx-auto flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                    </svg>
                </div>

                <div class="text-center space-y-1">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">کلید API با موفقیت تولید شد</h3>
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        توجه: این کلید به دلایل امنیتی فقط همین یک‌بار به شما نمایش داده می‌شود. لطفاً آن را ذخیره فرمایید.
                    </p>
                </div>

                <!-- Secret Token Box -->
                <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-black/50 border border-emerald-300 dark:border-emerald-500/30 space-y-2">
                    <div dir="ltr" class="font-mono text-xs text-emerald-800 dark:text-emerald-300 font-bold break-all select-all text-center">
                        {{ revealedSecret }}
                    </div>

                    <button
                        type="button"
                        @click="copySecretToClipboard"
                        class="glass-btn-primary !w-full !py-2 !text-xs !rounded-xl flex items-center justify-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <span>{{ isCopied ? 'کپی شد!' : 'کپی کلید در کلیپ‌بورد' }}</span>
                    </button>
                </div>

                <div class="flex justify-center pt-2">
                    <button
                        type="button"
                        @click="closeSecretModal"
                        class="glass-pill !py-2 !px-6 !text-xs font-bold text-slate-700 dark:text-slate-300"
                    >
                        متوجه شدم و ذخیره کردم
                    </button>
                </div>
            </div>
        </div>

        <!-- 5. Create Token Modal -->
        <div v-if="isCreateModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeCreateModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تولید کلید جدید (API Token)</h3>
                    <button type="button" @click="closeCreateModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">✕</button>
                </div>

                <form @submit.prevent="submitCreate" class="space-y-3.5">
                    <div>
                        <label class="form-label">نام یا شناسه کلید:</label>
                        <input
                            type="text"
                            v-model="createForm.name"
                            placeholder="مثال: سرور اصلی یا فروشگاه اینترنتی"
                            required
                            :disabled="createForm.processing"
                            class="form-input !py-2 !text-xs"
                        />
                    </div>

                    <div>
                        <label class="form-label">مدت اعتبار:</label>
                        <select
                            v-model="createForm.expires_days"
                            class="form-select !py-2 !text-xs"
                        >
                            <option value="">نامحدود (پیشنهادی)</option>
                            <option value="30">۳۰ روز</option>
                            <option value="90">۹۰ روز</option>
                            <option value="365">۱ سال</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeCreateModal" :disabled="createForm.processing" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl inline-flex items-center gap-1.5"
                        >
                            <svg v-if="createForm.processing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ createForm.processing ? 'در حال ایجاد...' : 'تولید کلید' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 6. Rotate Modal -->
        <div v-if="isRotateModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeRotateModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 text-center bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <div class="w-12 h-12 rounded-2xl bg-amber-500/15 border border-amber-500/30 text-amber-500 mx-auto flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تمدید و تغییر رمز کلید</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        رمز قبلی فوراً باطل شده و یک رشته کلید جدید صادر می‌گردد.
                    </p>
                </div>
                <div class="flex items-center justify-center gap-2 pt-2">
                    <button type="button" @click="closeRotateModal" :disabled="rotateProcessing" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRotate"
                        :disabled="rotateProcessing"
                        class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-lg shadow-amber-600/30 disabled:opacity-50 inline-flex items-center gap-1.5"
                    >
                        <svg v-if="rotateProcessing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ rotateProcessing ? 'در حال تمدید...' : 'تأیید و صدور رمز جدید' }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 7. Revoke Modal -->
        <div v-if="isRevokeModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeRevokeModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 text-center bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 mx-auto flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">باطل‌سازی کلید API</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        دسترسی این کلید به وب‌سرویس فوراً قطع خواهد شد.
                    </p>
                </div>
                <div class="flex items-center justify-center gap-2 pt-2">
                    <button type="button" @click="closeRevokeModal" :disabled="revokeProcessing" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRevoke"
                        :disabled="revokeProcessing"
                        class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/30 disabled:opacity-50 inline-flex items-center gap-1.5"
                    >
                        <svg v-if="revokeProcessing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ revokeProcessing ? 'در حال باطل‌سازی...' : 'باطل‌سازی قطعی' }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 8. Delete Modal -->
        <div v-if="isDeleteModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeDeleteModal">
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 text-center bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 mx-auto flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">حذف رکورد کلید</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        آیا از حذف این کلید اطمینان دارید؟ این عمل غیرقابل بازگشت است.
                    </p>
                </div>
                <div class="flex items-center justify-center gap-2 pt-2">
                    <button type="button" @click="closeDeleteModal" :disabled="deleteProcessing" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmDelete"
                        :disabled="deleteProcessing"
                        class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/30 disabled:opacity-50 inline-flex items-center gap-1.5"
                    >
                        <svg v-if="deleteProcessing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ deleteProcessing ? 'در حال حذف...' : 'حذف قطعی' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
