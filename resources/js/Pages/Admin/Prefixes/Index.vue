<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

interface PrefixItem {
    id: number;
    prefix: string;
    clean_prefix: string;
    name: string;
    country_code: string;
    is_default: boolean;
    is_active: boolean;
    numbers_count: number;
    created_at: string;
}

const props = defineProps<{
    prefixes: PrefixItem[];
    stats: {
        total: number;
        active: number;
        default: string;
    };
}>();

// Toast
const toastMessage = ref<string | null>(null);
const showToast = (msg: string) => {
    toastMessage.value = msg;
    setTimeout(() => {
        if (toastMessage.value === msg) toastMessage.value = null;
    }, 3000);
};

// Create Modal
const isCreateModalOpen = ref(false);
const createForm = useForm({
    prefix: '',
    name: '',
    country_code: 'INTL',
    is_default: false,
    is_active: true,
});

const openCreateModal = () => {
    createForm.reset();
    createForm.clearErrors();
    isCreateModalOpen.value = true;
};

const submitCreate = () => {
    createForm.post(route('admin.prefixes.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateModalOpen.value = false;
            showToast('پیش‌شماره جدید با موفقیت افزوده شد.');
        },
    });
};

// Edit Modal
const isEditModalOpen = ref(false);
const editingPrefix = ref<PrefixItem | null>(null);
const editForm = useForm({
    name: '',
    country_code: '',
    is_default: false,
    is_active: true,
});

const openEditModal = (prefix: PrefixItem) => {
    editingPrefix.value = prefix;
    editForm.reset();
    editForm.clearErrors();
    editForm.name = prefix.name;
    editForm.country_code = prefix.country_code;
    editForm.is_default = prefix.is_default;
    editForm.is_active = prefix.is_active;
    isEditModalOpen.value = true;
};

const submitEdit = () => {
    if (!editingPrefix.value) return;
    editForm.patch(route('admin.prefixes.update', editingPrefix.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditModalOpen.value = false;
            showToast('تنظیمات پیش‌شماره با موفقیت به‌روزرسانی شد.');
        },
    });
};

// Quick Toggle Status
const toggleStatus = (prefix: PrefixItem) => {
    router.patch(route('admin.prefixes.update', prefix.id), {
        name: prefix.name,
        country_code: prefix.country_code,
        is_default: prefix.is_default,
        is_active: !prefix.is_active,
    }, {
        preserveScroll: true,
        onSuccess: () => showToast(`وضعیت پیش‌شماره ${prefix.prefix} به‌روزرسانی شد.`),
    });
};

// Set As Default
const setAsDefault = (prefix: PrefixItem) => {
    if (prefix.is_default) return;
    router.patch(route('admin.prefixes.update', prefix.id), {
        name: prefix.name,
        country_code: prefix.country_code,
        is_default: true,
        is_active: true,
    }, {
        preserveScroll: true,
        onSuccess: () => showToast(`پیش‌شماره ${prefix.prefix} به عنوان پیش‌فرض سامانه تعیین شد.`),
    });
};

// Delete Prefix
const deletePrefix = (prefix: PrefixItem) => {
    if (prefix.is_default) {
        alert('امکان حذف پیش‌شماره پیش‌فرض سامانه وجود ندارد.');
        return;
    }
    if (prefix.numbers_count > 0) {
        alert(`این پیش‌شماره دارای ${prefix.numbers_count} خط سازمانی فعال بوده و امکان حذف آن وجود ندارد.`);
        return;
    }
    if (confirm(`آیا از حذف پیش‌شماره ${prefix.prefix} (${prefix.name}) اطمینان دارید؟`)) {
        router.delete(route('admin.prefixes.destroy', prefix.id), {
            preserveScroll: true,
            onSuccess: () => showToast(`پیش‌شماره ${prefix.prefix} با موفقیت حذف شد.`),
        });
    }
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        isCreateModalOpen.value = false;
        isEditModalOpen.value = false;
    }
};

onMounted(() => window.addEventListener('keydown', handleKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Head title="مدیریت پیش‌شماره‌های سازمانی" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Toast Notification -->
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="toastMessage" class="fixed top-5 left-1/2 -translate-x-1/2 z-50 bg-emerald-600 text-white px-4 py-2.5 rounded-xl shadow-xl text-xs font-bold flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>

            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 dark:border-slate-800 pb-5">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                        <span>مدیریت پیش‌شماره‌های سامانه</span>
                    </h1>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                        پیکربندی پیش‌شماره‌های مجاز، تعیین پیش‌شماره پیش‌فرض خطوط و نظارت بر اتصالات شماره‌ها.
                    </p>
                </div>

                <button
                    type="button"
                    @click="openCreateModal"
                    class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md shadow-rose-600/20 transition-all flex items-center justify-center gap-2 shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>افزودن پیش‌شماره جدید</span>
                </button>
            </div>

            <!-- Stats Row -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                        #
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-bold">تعداد کل پیش‌شماره‌ها</span>
                        <span class="text-lg font-black font-mono text-slate-900 dark:text-white">{{ stats.total }}</span>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold">
                        ✓
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-bold">پیش‌شماره‌های فعال</span>
                        <span class="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400">{{ stats.active }}</span>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold font-mono">
                        {{ stats.default }}
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-bold">پیش‌شماره پیش‌فرض سیستم</span>
                        <span class="text-lg font-black font-mono text-amber-600 dark:text-amber-400">{{ stats.default }}</span>
                    </div>
                </div>
            </div>

            <!-- Prefixes Table Card -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-600 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-3.5">پیش‌شماره</th>
                                <th class="p-3.5">عنوان / نام</th>
                                <th class="p-3.5">کد کشور</th>
                                <th class="p-3.5">تعداد خطوط متصل</th>
                                <th class="p-3.5">وضعیت فعالیت</th>
                                <th class="p-3.5">پیش‌فرض سامانه</th>
                                <th class="p-3.5 text-center">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800/70">
                            <tr v-for="prefix in prefixes" :key="prefix.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                                <td class="p-3.5 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                    <span class="text-rose-600 dark:text-rose-400 text-sm font-black">{{ prefix.prefix }}</span>
                                    <span class="text-[10px] text-slate-400 mr-1">({{ prefix.clean_prefix }})</span>
                                </td>
                                <td class="p-3.5 font-medium text-slate-800 dark:text-slate-200">
                                    {{ prefix.name }}
                                </td>
                                <td class="p-3.5 font-mono text-slate-600 dark:text-slate-400">
                                    {{ prefix.country_code }}
                                </td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded-full font-mono text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                        {{ prefix.numbers_count }} خط
                                    </span>
                                </td>
                                <td class="p-3.5">
                                    <button
                                        type="button"
                                        @click="toggleStatus(prefix)"
                                        class="px-2.5 py-1 rounded-full text-[10px] font-bold transition-all border"
                                        :class="prefix.is_active
                                            ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800'
                                            : 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700'"
                                    >
                                        {{ prefix.is_active ? 'فعال' : 'غیرفعال' }}
                                    </button>
                                </td>
                                <td class="p-3.5">
                                    <span v-if="prefix.is_default" class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800 inline-flex items-center gap-1">
                                        <span>★</span>
                                        <span>پیش‌فرض</span>
                                    </span>
                                    <button
                                        v-else
                                        type="button"
                                        @click="setAsDefault(prefix)"
                                        class="text-[11px] font-bold text-slate-400 hover:text-amber-600 dark:hover:text-amber-400 transition-colors"
                                    >
                                        تعیین به عنوان پیش‌فرض
                                    </button>
                                </td>
                                <td class="p-3.5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button
                                            type="button"
                                            @click="openEditModal(prefix)"
                                            class="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                            title="ویرایش پیش‌شماره"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            @click="deletePrefix(prefix)"
                                            :disabled="prefix.is_default || prefix.numbers_count > 0"
                                            class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none transition-colors"
                                            title="حذف پیش‌شماره"
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

            <!-- Create Modal -->
            <div v-if="isCreateModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm" @click.self="isCreateModalOpen = false">
                <div class="w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800 shrink-0 mb-4">
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">افزودن پیش‌شماره سازمانی جدید</h3>
                        <button type="button" @click="isCreateModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">✕</button>
                    </div>

                    <form @submit.prevent="submitCreate" class="space-y-3.5 text-xs overflow-y-auto flex-1">
                        <div>
                            <label class="form-label mb-1">پیش‌شماره (با علامت +)</label>
                            <input
                                v-model="createForm.prefix"
                                type="text"
                                dir="ltr"
                                placeholder="+98 یا +85"
                                class="form-input w-full font-mono text-left"
                                required
                            />
                            <p v-if="createForm.errors.prefix" class="text-rose-500 text-[11px] mt-1">{{ createForm.errors.prefix }}</p>
                        </div>

                        <div>
                            <label class="form-label mb-1">عنوان / نام پیش‌شماره</label>
                            <input
                                v-model="createForm.name"
                                type="text"
                                placeholder="مانند: پیش‌شماره ایران یا پیش‌شماره بین‌المللی"
                                class="form-input w-full"
                                required
                            />
                            <p v-if="createForm.errors.name" class="text-rose-500 text-[11px] mt-1">{{ createForm.errors.name }}</p>
                        </div>

                        <div>
                            <label class="form-label mb-1">کد کشور (اختیاری)</label>
                            <input
                                v-model="createForm.country_code"
                                type="text"
                                dir="ltr"
                                placeholder="IR یا INTL"
                                class="form-input w-full font-mono text-left"
                            />
                        </div>

                        <div class="flex items-center gap-4 pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="createForm.is_active" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">فعال بودن پیش‌شماره</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="createForm.is_default" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">تعیین به عنوان پیش‌فرض</span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                            <button
                                type="button"
                                @click="isCreateModalOpen = false"
                                class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700 shadow-sm disabled:opacity-50 cursor-pointer"
                            >
                                <svg v-if="createForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ createForm.processing ? 'در حال ثبت...' : 'ذخیره پیش‌شماره' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Modal -->
            <div v-if="isEditModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm" @click.self="isEditModalOpen = false">
                <div class="w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800 shrink-0 mb-4">
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">ویرایش پیش‌شماره {{ editingPrefix?.prefix }}</h3>
                        <button type="button" @click="isEditModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">✕</button>
                    </div>

                    <form @submit.prevent="submitEdit" class="space-y-3.5 text-xs overflow-y-auto flex-1">
                        <div>
                            <label class="form-label mb-1">عنوان / نام پیش‌شماره</label>
                            <input
                                v-model="editForm.name"
                                type="text"
                                class="form-input w-full"
                                required
                            />
                            <p v-if="editForm.errors.name" class="text-rose-500 text-[11px] mt-1">{{ editForm.errors.name }}</p>
                        </div>

                        <div>
                            <label class="form-label mb-1">کد کشور</label>
                            <input
                                v-model="editForm.country_code"
                                type="text"
                                dir="ltr"
                                class="form-input w-full font-mono text-left"
                            />
                        </div>

                        <div class="flex items-center gap-4 pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="editForm.is_active" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">فعال بودن پیش‌شماره</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="editForm.is_default" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">تعیین به عنوان پیش‌فرض</span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                            <button
                                type="button"
                                @click="isEditModalOpen = false"
                                class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="editForm.processing"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700 shadow-sm disabled:opacity-50 cursor-pointer"
                            >
                                <svg v-if="editForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ editForm.processing ? 'در حال ثبت...' : 'به‌روزرسانی تنظیمات' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
