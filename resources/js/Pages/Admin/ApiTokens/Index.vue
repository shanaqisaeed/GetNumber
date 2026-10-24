<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';

interface UserSummary {
    id: number;
    name: string;
    username: string;
    is_admin?: boolean;
}

interface TokenItem {
    id: number;
    user: UserSummary;
    name: string;
    prefix: string;
    preview: string;
    status: 'active' | 'revoked';
    status_label: string;
    status_badge: string;
    is_active: boolean;
    is_expired: boolean;
    abilities: string[];
    has_full_access: boolean;
    last_used_at: string | null;
    last_used_ip: string | null;
    expires_at: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedData<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    current_page: number;
    last_page: number;
}

const props = defineProps<{
    tokens: PaginatedData<TokenItem>;
    filters: {
        search?: string;
        status?: string;
    };
    stats: {
        total: number;
        active: number;
        revoked: number;
    };
    availableAbilities?: Record<string, string>;
    plainTextToken?: string | null;
}>();

const page = usePage();
const currentAuthUser = computed(() => page.props.auth?.user);

// Filter states
const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');

const handleFilter = () => {
    router.get(
        route('admin.api-tokens.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilter = () => {
    search.value = '';
    statusFilter.value = '';
    handleFilter();
};

// ========================
// ONE-TIME SECRET MODAL
// ========================
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

// ========================
// CREATE TOKEN MODAL
// ========================
const isCreateModalOpen = ref(false);
const tokenOwnerType = ref<'admin' | 'user'>('admin');
const userSearchQuery = ref('');
const userSearchResults = ref<Array<{ id: number; name: string; username: string }>>([]);
const isSearchingUser = ref(false);
const selectedUser = ref<{ id: number; name: string; username: string } | null>(null);

const createForm = useForm({
    name: '',
    user_id: '' as string | number,
    expires_days: '',
    has_all_abilities: true,
    abilities: ['*'] as string[],
});

const openCreateModal = () => {
    createForm.reset();
    createForm.clearErrors();
    createForm.has_all_abilities = true;
    createForm.abilities = ['*'];
    tokenOwnerType.value = 'admin';
    selectedUser.value = null;
    userSearchQuery.value = '';
    userSearchResults.value = [];
    isCreateModalOpen.value = true;
};

const closeCreateModal = () => {
    isCreateModalOpen.value = false;
};

// Search users for assign modal
let searchTimeout: any = null;
const onUserSearchInput = () => {
    clearTimeout(searchTimeout);
    const q = userSearchQuery.value.trim();
    if (q.length < 1) {
        userSearchResults.value = [];
        return;
    }
    searchTimeout = setTimeout(async () => {
        isSearchingUser.value = true;
        try {
            const resp = await fetch(route('admin.users.search') + `?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            if (resp.ok) {
                userSearchResults.value = await resp.json();
            }
        } catch {
            userSearchResults.value = [];
        } finally {
            isSearchingUser.value = false;
        }
    }, 250);
};

const selectUserForToken = (user: { id: number; name: string; username: string }) => {
    selectedUser.value = user;
    createForm.user_id = user.id;
    userSearchResults.value = [];
    userSearchQuery.value = '';
};

const clearSelectedUser = () => {
    selectedUser.value = null;
    createForm.user_id = '';
};

// Toggle all abilities vs granular
const onToggleAllAbilities = () => {
    if (createForm.has_all_abilities) {
        createForm.abilities = ['*'];
    } else {
        createForm.abilities = ['numbers:read', 'messages:read'];
    }
};

const toggleAbility = (ability: string) => {
    if (createForm.has_all_abilities) {
        createForm.has_all_abilities = false;
        createForm.abilities = [];
    }

    const idx = createForm.abilities.indexOf(ability);
    if (idx > -1) {
        createForm.abilities.splice(idx, 1);
    } else {
        createForm.abilities.push(ability);
    }
};

const submitCreate = () => {
    // If admin is selected, clear user_id so it defaults to auth user or set explicitly
    if (tokenOwnerType.value === 'admin') {
        createForm.user_id = '';
    }

    createForm.post(route('admin.api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
        },
    });
};

// ========================
// ROTATE CONFIRMATION MODAL
// ========================
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
        route('admin.api-tokens.rotate', { token: tokenToRotate.value.id }),
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

// ========================
// REVOKE CONFIRMATION MODAL
// ========================
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
        route('admin.api-tokens.revoke', { token: tokenToRevoke.value.id }),
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

// ========================
// DELETE CONFIRMATION MODAL
// ========================
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
        route('admin.api-tokens.destroy', { token: tokenToDelete.value.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                deleteProcessing.value = false;
                closeDeleteModal();
            },
        }
    );
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isSecretModalOpen.value) closeSecretModal();
        if (isCreateModalOpen.value) closeCreateModal();
        if (isRotateModalOpen.value) closeRotateModal();
        if (isRevokeModalOpen.value) closeRevokeModal();
        if (isDeleteModalOpen.value) closeDeleteModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <AdminLayout>
        <Head title="مدیریت کلیدهای ارتباطی و وب‌سرویس (API)" />

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
                        <h1 class="text-xl font-black text-slate-900 dark:text-white">نظارت و مدیریت کلیدهای ارتباطی (API Tokens)</h1>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed pr-10">
                        صدور کلیدهای راهبری با حداکثر دسترسی‌ها، مشاهده، ردگیری آدرس IP، تعویض و ابطال کلیدهای وب‌سرویس در سراسر سامانه.
                    </p>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 shadow-lg shadow-indigo-600/25 dark:shadow-indigo-950/50 transition-all hover:scale-[1.02] active:scale-[0.98]"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>صدور کلید جدید (راهبر / کاربر)</span>
                    </button>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">کل کلیدهای سامانه</p>
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
                        <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">کلیدهای فعال</p>
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
                        <p class="text-xs font-semibold text-rose-600 dark:text-rose-400">کلیدهای باطل‌شده</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.revoked }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center border border-rose-100 dark:border-rose-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
                <form @submit.prevent="handleFilter" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <div class="sm:col-span-6 relative">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="جستجو بر اساس نام کلید، پیشوند توکن، نام یا نام کاربری..."
                            class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                        />
                        <div class="absolute left-3 top-2.5 text-slate-400 pointer-events-none">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <div class="sm:col-span-4">
                        <select
                            v-model="statusFilter"
                            class="form-select w-full py-2 text-xs rounded-xl"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option value="active">فعال</option>
                            <option value="revoked">باطل‌شده</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2 flex items-center gap-2">
                        <button
                            type="submit"
                            class="flex-1 py-2 px-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition shadow-sm"
                        >
                            اعمال فیلتر
                        </button>
                        <button
                            type="button"
                            @click="resetFilter"
                            class="py-2 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold transition"
                            title="بازنشانی"
                        >
                            لغو
                        </button>
                    </div>
                </form>
            </div>

            <!-- Table Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h2 class="font-black text-slate-900 dark:text-white text-base">کلیه کلیدهای وب‌سرویس سامانه</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">کلیدهای رمزنگاری‌شده با الگوریتم SHA-256 ذخیره می‌گردند.</p>
                    </div>
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                        مجموع: {{ tokens.total }} رکورد
                    </span>
                </div>

                <!-- Empty State -->
                <div v-if="tokens.data.length === 0" class="p-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">هیچ کلید ارتباطی یافت نشد</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                        هیچ کلیدی متناسب با فیلترهای انتخابی در سیستم ثبت نشده است.
                    </p>
                    <button
                        type="button"
                        @click="openCreateModal"
                        class="mt-4 px-4 py-2 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition"
                    >
                        صدور کلید جدید
                    </button>
                </div>

                <!-- Table Content -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="py-3.5 px-4">کاربر مالک کلید</th>
                                <th class="py-3.5 px-4">عنوان سامانه متصل</th>
                                <th class="py-3.5 px-4">پیشوند و شناسه</th>
                                <th class="py-3.5 px-4">سطح دسترسی (Abilities)</th>
                                <th class="py-3.5 px-4">وضعیت</th>
                                <th class="py-3.5 px-4">آخرین فراخوانی / IP</th>
                                <th class="py-3.5 px-4">انقضا</th>
                                <th class="py-3.5 px-4">تاریخ صدور</th>
                                <th class="py-3.5 px-4 text-center">عملیات نظارتی</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="token in tokens.data"
                                :key="token.id"
                                class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="py-4 px-4">
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-xs shrink-0 border"
                                            :class="token.user.is_admin ? 'bg-purple-100 text-purple-700 border-purple-200 dark:bg-purple-950/60 dark:text-purple-300 dark:border-purple-800' : 'bg-indigo-50 text-indigo-600 border-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-400 dark:border-indigo-900/60'"
                                        >
                                            {{ token.user.name.charAt(0) }}
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-1.5">
                                                <p class="font-bold text-slate-900 dark:text-white text-xs">{{ token.user.name }}</p>
                                                <span v-if="token.user.is_admin" class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300">
                                                    راهبر
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 font-mono dir-ltr">@{{ token.user.username }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-4 font-bold text-slate-900 dark:text-white text-xs">
                                    {{ token.name }}
                                </td>
                                <td class="py-4 px-4">
                                    <span class="font-mono text-xs text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded dir-ltr inline-block">
                                        {{ token.preview }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <span
                                        v-if="token.has_full_access"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-black bg-gradient-to-r from-purple-500/20 to-indigo-500/20 text-purple-700 dark:text-purple-300 border border-purple-500/30"
                                    >
                                        <span>★ حداکثر دسترسی‌ها (*)</span>
                                    </span>
                                    <div v-else class="flex flex-wrap gap-1 max-w-xs">
                                        <span
                                            v-for="ability in token.abilities"
                                            :key="ability"
                                            class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700"
                                        >
                                            {{ ability }}
                                        </span>
                                    </div>
                                </td>
                                <td class="py-4 px-4">
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold"
                                        :class="token.status_badge"
                                    >
                                        {{ token.status_label }}
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-xs">
                                    <div v-if="token.last_used_at">
                                        <p class="text-slate-700 dark:text-slate-300 font-medium">{{ token.last_used_at }}</p>
                                        <p v-if="token.last_used_ip" class="text-[11px] text-slate-400 font-mono dir-ltr">IP: {{ token.last_used_ip }}</p>
                                    </div>
                                    <span v-else class="text-slate-400 italic">بدون فراخوانی</span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400">
                                    <span v-if="token.expires_at">{{ token.expires_at }}</span>
                                    <span v-else class="text-slate-400">دائمی</span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400 font-mono">
                                    {{ token.created_at }}
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <!-- Rotate (تعویض کلید) -->
                                        <button
                                            v-if="token.is_active"
                                            type="button"
                                            @click="openRotateModal(token)"
                                            class="p-1.5 text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/60 rounded-lg transition"
                                            title="تعویض کلید (Rotate)"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                        </button>

                                        <!-- Revoke -->
                                        <button
                                            v-if="token.is_active"
                                            type="button"
                                            @click="openRevokeModal(token)"
                                            class="p-1.5 text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/60 rounded-lg transition"
                                            title="ابطال دسترسی"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        </button>

                                        <!-- Delete -->
                                        <button
                                            type="button"
                                            @click="openDeleteModal(token)"
                                            class="p-1.5 text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/60 rounded-lg transition"
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

                <!-- Pagination Footer -->
                <div v-if="tokens.links && tokens.links.length > 3" class="p-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        نمایش صفحه {{ tokens.current_page }} از {{ tokens.last_page }}
                    </p>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, index) in tokens.links" :key="index">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg transition"
                                :class="link.active ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-3 py-1.5 text-xs text-slate-400 opacity-50 cursor-not-allowed"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ONE-TIME RAW SECRET MODAL (CRITICAL SECURITY) -->
        <div
            v-if="isSecretModalOpen && revealedSecret"
            @click.self="closeSecretModal"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-xl rounded-3xl border border-indigo-500/30 shadow-2xl overflow-hidden p-6 sm:p-8 space-y-6 max-h-[calc(100dvh-2.5rem)] flex flex-col">
                <div class="flex items-start gap-4 shrink-0">
                    <div class="w-12 h-12 rounded-2xl bg-amber-500/10 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white">کلید دسترسی با موفقیت صادر گردید!</h3>
                        <p class="text-xs text-rose-600 dark:text-rose-400 font-bold mt-1">
                            هشدار امنیتی: این کلید به صورت SHA-256 هش شده و تنها همین یک‌بار نمایش داده می‌شود!
                        </p>
                    </div>
                </div>

                <div class="overflow-y-auto flex-1 space-y-4">
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                        مقدار توکن خام زیر را کپی کرده و در محل امنی ذخیره فرمایید. این کلید دارای حداکثر اختیارات وب‌سرویس بوده و پس از بستن این پنجره امکان مشاهده مجدد آن وجود نخواهد داشت.
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
                                {{ isCopied ? 'کلید با موفقیت در کلیپ‌بورد کپی شد!' : 'روی دکمه مقابل کلیک کنید:' }}
                            </span>
                            <button
                                type="button"
                                @click="copySecretToClipboard"
                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer"
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
                </div>

                <div class="pt-2 shrink-0">
                    <button
                        type="button"
                        @click="closeSecretModal"
                        class="w-full py-3 px-4 rounded-xl font-bold text-sm text-white bg-slate-900 dark:bg-slate-800 hover:bg-slate-800 dark:hover:bg-slate-700 border border-slate-700 transition cursor-pointer"
                    >
                        کلید را در محل امن ذخیره کردم و پنجره را می‌بندم
                    </button>
                </div>
            </div>
        </div>

        <!-- CREATE TOKEN MODAL (ADMIN / USER) -->
        <div
            v-if="isCreateModalOpen"
            @click.self="closeCreateModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in overflow-y-auto"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-xl max-h-[calc(100dvh-2.5rem)] flex flex-col rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 sm:p-7 space-y-5 my-auto">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-4 shrink-0">
                    <div class="flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </span>
                        <h3 class="font-bold text-base text-slate-900 dark:text-white">صدور کلید ارتباطی جدید (راهبر / کاربر)</h3>
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

                <form @submit.prevent="submitCreate" class="space-y-4 flex-1 overflow-y-auto pr-1 pl-1">
                    <!-- Owner Selection: Admin or User -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">
                            تعیین مالک کلید دسترسی:
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                @click="tokenOwnerType = 'admin'"
                                class="p-3 rounded-2xl border text-right transition flex items-start gap-3"
                                :class="tokenOwnerType === 'admin' ? 'border-purple-500 bg-purple-50/50 dark:bg-purple-950/30 text-purple-900 dark:text-purple-200 ring-2 ring-purple-500/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'"
                            >
                                <span class="w-4 h-4 rounded-full border mt-0.5 flex items-center justify-center shrink-0" :class="tokenOwnerType === 'admin' ? 'border-purple-600 bg-purple-600' : 'border-slate-400'">
                                    <span v-if="tokenOwnerType === 'admin'" class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                </span>
                                <div>
                                    <p class="text-xs font-black">حساب راهبر ارشد</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">صدور برای خودتان با حداکثر اختیارات</p>
                                </div>
                            </button>

                            <button
                                type="button"
                                @click="tokenOwnerType = 'user'"
                                class="p-3 rounded-2xl border text-right transition flex items-start gap-3"
                                :class="tokenOwnerType === 'user' ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/30 text-indigo-900 dark:text-indigo-200 ring-2 ring-indigo-500/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'"
                            >
                                <span class="w-4 h-4 rounded-full border mt-0.5 flex items-center justify-center shrink-0" :class="tokenOwnerType === 'user' ? 'border-indigo-600 bg-indigo-600' : 'border-slate-400'">
                                    <span v-if="tokenOwnerType === 'user'" class="w-1.5 h-1.5 rounded-full bg-white"></span>
                                </span>
                                <div>
                                    <p class="text-xs font-black">کاربر مشخص در سیستم</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">صدور به نام یک کاربر معین</p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- User Selector (when tokenOwnerType === 'user') -->
                    <div v-if="tokenOwnerType === 'user'" class="space-y-2 p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                            جستجو و انتخاب کاربر مالک <span class="text-rose-500">*</span>
                        </label>

                        <!-- Selected User Tag -->
                        <div v-if="selectedUser" class="flex items-center justify-between p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/80 border border-indigo-200 dark:border-indigo-800">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-indigo-600 text-white flex items-center justify-center text-xs font-bold">
                                    {{ selectedUser.name.charAt(0) }}
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-indigo-900 dark:text-indigo-200">{{ selectedUser.name }}</p>
                                    <p class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 dir-ltr">@{{ selectedUser.username }} (شناسه: {{ selectedUser.id }})</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="clearSelectedUser"
                                class="p-1 text-slate-400 hover:text-rose-500 rounded-lg transition"
                                title="تغییر کاربر"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- User Search Input -->
                        <div v-else class="relative">
                            <input
                                v-model="userSearchQuery"
                                @input="onUserSearchInput"
                                type="text"
                                placeholder="نام، نام کاربری یا شناسه کاربر را تایپ کنید..."
                                class="form-input w-full"
                            />
                            <div v-if="isSearchingUser" class="absolute left-3 top-2.5 text-xs text-slate-400">
                                در حال جستجو...
                            </div>

                            <!-- Dropdown Results -->
                            <div v-if="userSearchResults.length > 0" class="absolute z-20 top-full mt-1 w-full bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xl max-h-48 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                                <button
                                    v-for="u in userSearchResults"
                                    :key="u.id"
                                    type="button"
                                    @click="selectUserForToken(u)"
                                    class="w-full p-2.5 text-right hover:bg-indigo-50 dark:hover:bg-indigo-950/60 transition flex items-center justify-between"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-md bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-[10px] font-bold text-slate-700 dark:text-slate-300">
                                            {{ u.id }}
                                        </span>
                                        <div>
                                            <p class="text-xs font-bold text-slate-900 dark:text-white">{{ u.name }}</p>
                                            <p class="text-[10px] font-mono text-slate-400 dir-ltr">@{{ u.username }}</p>
                                        </div>
                                    </div>
                                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400 font-bold">انتخاب</span>
                                </button>
                            </div>
                        </div>
                        <p v-if="createForm.errors.user_id" class="text-xs text-rose-500 font-semibold">
                            {{ createForm.errors.user_id }}
                        </p>
                    </div>

                    <!-- Token Name -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            عنوان کلید دسترسی یا نام سامانه متصل <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="createForm.name"
                            type="text"
                            placeholder="مثال: سرور مرکزی ERP، وب‌سرویس مدیریت خطوط، سیستم گزارش‌گیری"
                            class="form-input w-full"
                            required
                        />
                        <p v-if="createForm.errors.name" class="text-xs text-rose-500 mt-1 font-semibold">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <!-- Abilities Selection (CRITICAL) -->
                    <div class="space-y-2.5 p-3.5 rounded-2xl bg-indigo-50/40 dark:bg-slate-800/80 border border-indigo-100 dark:border-slate-700">
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-black text-slate-900 dark:text-white flex items-center gap-1.5">
                                <span class="text-purple-600 dark:text-purple-400">★</span>
                                <span>سطوح دسترسی و اختیارات (Abilities):</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    v-model="createForm.has_all_abilities"
                                    @change="onToggleAllAbilities"
                                    type="checkbox"
                                    class="w-4 h-4 rounded text-purple-600 focus:ring-purple-500 border-slate-300"
                                />
                                <span class="text-xs font-black text-purple-700 dark:text-purple-300">حداکثر دسترسی‌ها (*)</span>
                            </label>
                        </div>

                        <div v-if="createForm.has_all_abilities" class="p-2.5 rounded-xl bg-purple-100/70 dark:bg-purple-950/60 border border-purple-200 dark:border-purple-800/80 text-[11px] text-purple-800 dark:text-purple-200 font-medium leading-relaxed">
                            این کلید دارای اختیارات تام راهبری (`*`) بوده و می‌تواند به تمامی اندپوینت‌ها و متدهای وب‌سرویس (استعلام شماره‌ها، تخصیص خطوط، مدیریت کاربران و خواندن و نوشتن پیام‌ها) دسترسی کامل داشته باشد.
                        </div>

                        <!-- Granular abilities when not '*' -->
                        <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                            <label
                                v-for="(label, key) in availableAbilities"
                                :key="key"
                                v-show="key !== '*'"
                                class="flex items-start gap-2 p-2 rounded-xl border transition cursor-pointer text-xs"
                                :class="createForm.abilities.includes(String(key)) ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/40 font-bold text-indigo-900 dark:text-indigo-200' : 'border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400'"
                            >
                                <input
                                    type="checkbox"
                                    :checked="createForm.abilities.includes(String(key))"
                                    @change="toggleAbility(String(key))"
                                    class="w-4 h-4 mt-0.5 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                                />
                                <div>
                                    <p class="font-mono text-[11px] text-indigo-700 dark:text-indigo-300 dir-ltr">{{ key }}</p>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400">{{ label }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Expiration -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            مدت زمان اعتبار کلید
                        </label>
                        <select
                            v-model="createForm.expires_days"
                            class="form-select w-full"
                        >
                            <option value="">دائمی (بدون انقضا - توصیه برای سرورهای مدیریتی و زیرساختی)</option>
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
                            :disabled="createForm.processing || (tokenOwnerType === 'user' && !createForm.user_id)"
                            class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 disabled:opacity-50 transition shadow-md shadow-indigo-600/20 cursor-pointer"
                        >
                            <svg v-if="createForm.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ createForm.processing ? 'در حال صدور...' : 'تولید و صدور کلید دسترسی' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ROTATE CONFIRMATION MODAL -->
        <div
            v-if="isRotateModalOpen && tokenToRotate"
            @click.self="closeRotateModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md max-h-[calc(100dvh-2.5rem)] overflow-y-auto rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-100 dark:border-indigo-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">تعویض کلید دسترسی (Rotate)</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از تعویض کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToRotate.name }}»</span> متعلق به کاربر <span class="font-bold text-slate-900 dark:text-white">{{ tokenToRotate.user.name }}</span> اطمینان دارید؟
                        با این اقدام، رمز عبور توکن فعلی بلافاصله نامعتبر شده و توکن جدیدی صادر و نمایش داده خواهد شد.
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
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 transition cursor-pointer"
                    >
                        <svg v-if="rotateProcessing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ rotateProcessing ? 'در حال تعویض...' : 'تأیید و صدور کلید جدید' }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- REVOKE CONFIRMATION MODAL -->
        <div
            v-if="isRevokeModalOpen && tokenToRevoke"
            @click.self="closeRevokeModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md max-h-[calc(100dvh-2.5rem)] overflow-y-auto rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center border border-amber-100 dark:border-amber-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">ابطال دسترسی کلید کاربر</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از ابطال کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToRevoke.name }}»</span> متعلق به کاربر <span class="font-bold text-slate-900 dark:text-white">{{ tokenToRevoke.user.name }}</span> اطمینان دارید؟
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
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 disabled:opacity-50 transition cursor-pointer"
                    >
                        <svg v-if="revokeProcessing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ revokeProcessing ? 'در حال ابطال...' : 'تایید ابطال' }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- DELETE CONFIRMATION MODAL -->
        <div
            v-if="isDeleteModalOpen && tokenToDelete"
            @click.self="closeDeleteModal"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md max-h-[calc(100dvh-2.5rem)] overflow-y-auto rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
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
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50 transition cursor-pointer"
                    >
                        <svg v-if="deleteProcessing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ deleteProcessing ? 'در حال حذف...' : 'حذف قطعی' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
