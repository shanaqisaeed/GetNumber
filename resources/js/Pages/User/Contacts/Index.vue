<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import UserLayout from '@/Layouts/UserLayout.vue';

interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes: string | null;
    is_custom_id: boolean;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    contacts: {
        data: ContactItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
    };
    stats: {
        total: number;
        phone_lines: number;
        custom_ids: number;
    };
}>();

const search = ref(props.filters.search || '');

const handleSearch = () => {
    router.get(
        route('contacts.index'),
        { search: search.value || undefined },
        { preserveState: true, replace: true }
    );
};

const clearSearch = () => {
    search.value = '';
    router.get(route('contacts.index'));
};

// Modal State: Create / Edit
const isModalOpen = ref(false);
const editingContact = ref<ContactItem | null>(null);

const form = useForm({
    name: '',
    phone_number: '',
    avatar_color: 'indigo',
    notes: '',
});

const openCreateModal = () => {
    editingContact.value = null;
    form.reset();
    form.avatar_color = 'indigo';
    isModalOpen.value = true;
};

const openEditModal = (contact: ContactItem) => {
    editingContact.value = contact;
    form.name = contact.name;
    form.phone_number = contact.phone_number;
    form.avatar_color = contact.avatar_color || 'indigo';
    form.notes = contact.notes || '';
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingContact.value = null;
    form.reset();
};

const submitForm = () => {
    if (editingContact.value) {
        form.patch(route('contacts.update', editingContact.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('contacts.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
};

// Delete Confirmation
const isDeleteModalOpen = ref(false);
const contactToDelete = ref<ContactItem | null>(null);

const openDeleteModal = (contact: ContactItem) => {
    contactToDelete.value = contact;
    isDeleteModalOpen.value = true;
};

const closeDeleteModal = () => {
    isDeleteModalOpen.value = false;
    contactToDelete.value = null;
};

const confirmDelete = () => {
    if (!contactToDelete.value) return;
    router.delete(route('contacts.destroy', contactToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeDeleteModal(),
    });
};

const colorClasses: Record<string, { bg: string; text: string; border: string }> = {
    indigo: { bg: 'bg-indigo-500/15', text: 'text-indigo-600 dark:text-indigo-400', border: 'border-indigo-500/30' },
    emerald: { bg: 'bg-emerald-500/15', text: 'text-emerald-600 dark:text-emerald-400', border: 'border-emerald-500/30' },
    blue: { bg: 'bg-blue-500/15', text: 'text-blue-600 dark:text-blue-400', border: 'border-blue-500/30' },
    amber: { bg: 'bg-amber-500/15', text: 'text-amber-600 dark:text-amber-400', border: 'border-amber-500/30' },
    rose: { bg: 'bg-rose-500/15', text: 'text-rose-600 dark:text-rose-400', border: 'border-rose-500/30' },
    purple: { bg: 'bg-purple-500/15', text: 'text-purple-600 dark:text-purple-400', border: 'border-purple-500/30' },
};
</script>

<template>
    <Head title="دفترچه مخاطبین" />

    <UserLayout>
        <div class="space-y-4">
            <!-- Header Command Card -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500 animate-pulse"></span>
                        <h2 class="wallet-title">دفترچه تلفن و مخاطبین اختصاصی</h2>
                    </div>
                    <span class="wallet-chip bg-blue-500/15 border-blue-500/30 text-blue-600 dark:text-blue-400">
                        {{ stats.total }} مخاطب ذخیره‌شده
                    </span>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    مدیریت شماره‌ها و شناسه‌های ارتباطی، تماس سریع صوتی و ارسال آسان پیامک بدون نیاز به تایپ مجدد.
                </p>

                <!-- KPI Quick Summary -->
                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 text-center">
                    <div class="p-2.5 rounded-2xl bg-slate-100/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-white/10">
                        <div class="text-[10px] text-slate-400 font-bold">کل مخاطبین</div>
                        <div class="text-base font-black font-mono text-slate-900 dark:text-white mt-0.5">{{ stats.total }}</div>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-slate-100/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-white/10">
                        <div class="text-[10px] text-slate-400 font-bold">خطوط تلفن</div>
                        <div class="text-base font-black font-mono text-indigo-600 dark:text-indigo-400 mt-0.5">{{ stats.phone_lines }}</div>
                    </div>
                    <div class="p-2.5 rounded-2xl bg-slate-100/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-white/10">
                        <div class="text-[10px] text-slate-400 font-bold">آیدی‌های اختصاصی (@)</div>
                        <div class="text-base font-black font-mono text-emerald-600 dark:text-emerald-400 mt-0.5">{{ stats.custom_ids }}</div>
                    </div>
                </div>

                <!-- Action & Search Bar -->
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2.5 pt-3 border-t border-slate-200/80 dark:border-white/10">
                    <div class="relative flex-1 min-w-[200px]">
                        <input
                            type="text"
                            v-model="search"
                            @keyup.enter="handleSearch"
                            placeholder="جستجو بر اساس نام، شماره یا آیدی..."
                            class="glass-input !py-2 !pr-8 !pl-3 !text-xs !rounded-xl w-full"
                        />
                        <svg class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            v-if="search"
                            type="button"
                            @click="clearSearch"
                            class="px-2.5 py-2 rounded-xl text-xs font-bold text-rose-500 glass-pill"
                        >
                            ✕
                        </button>

                        <button
                            type="button"
                            @click="openCreateModal"
                            class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl flex items-center gap-1.5 shrink-0"
                        >
                            <span>+ افزودن مخاطب</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Contacts List -->
            <div v-if="contacts.data.length > 0" class="space-y-3">
                <div
                    v-for="contact in contacts.data"
                    :key="contact.id"
                    class="glass-card !p-4 hover:border-indigo-500/40 transition-all space-y-3 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 shadow-xs"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <!-- Avatar -->
                            <div
                                class="w-10 h-10 rounded-2xl flex items-center justify-center font-bold text-sm shrink-0 border"
                                :class="[
                                    colorClasses[contact.avatar_color]?.bg || colorClasses.indigo.bg,
                                    colorClasses[contact.avatar_color]?.text || colorClasses.indigo.text,
                                    colorClasses[contact.avatar_color]?.border || colorClasses.indigo.border
                                ]"
                            >
                                {{ contact.name.charAt(0) }}
                            </div>

                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white truncate">
                                        {{ contact.name }}
                                    </h3>
                                    <span
                                        class="text-[9px] font-bold px-2 py-0.5 rounded-full"
                                        :class="contact.is_custom_id ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'"
                                    >
                                        {{ contact.is_custom_id ? 'شناسه @' : 'خط تلفن' }}
                                    </span>
                                </div>
                                <div dir="ltr" class="text-left font-mono font-black text-xs text-indigo-600 dark:text-indigo-400 mt-0.5 select-all">
                                    {{ contact.phone_number }}
                                </div>
                            </div>
                        </div>

                        <!-- Actions for Contact -->
                        <div class="flex items-center gap-1.5 shrink-0">
                            <!-- SMS Action Link -->
                            <Link
                                :href="route('messages.index', { to: contact.phone_number })"
                                class="p-2 rounded-xl glass-pill hover:text-indigo-600 hover:border-indigo-500/40 transition-colors"
                                title="ارسال پیامک"
                            >
                                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                </svg>
                            </Link>

                            <!-- Voice Call Action Link -->
                            <Link
                                :href="route('calls.index', { destination: contact.phone_number })"
                                class="p-2 rounded-xl glass-pill hover:text-emerald-600 hover:border-emerald-500/40 transition-colors"
                                title="تماس صوتی"
                            >
                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </Link>

                            <!-- Edit button -->
                            <button
                                type="button"
                                @click="openEditModal(contact)"
                                class="p-2 rounded-xl glass-pill text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors"
                                title="ویرایش مخاطب"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>

                            <!-- Delete button -->
                            <button
                                type="button"
                                @click="openDeleteModal(contact)"
                                class="p-2 rounded-xl glass-pill text-slate-400 hover:text-rose-500 transition-colors"
                                title="حذف مخاطب"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <p v-if="contact.notes" class="text-xs text-slate-500 dark:text-slate-400 pt-2 border-t border-slate-100 dark:border-white/5">
                        {{ contact.notes }}
                    </p>
                </div>

                <!-- Pagination -->
                <div v-if="contacts.links && contacts.links.length > 3" class="flex justify-center gap-1 pt-2">
                    <Link
                        v-for="(link, i) in contacts.links"
                        :key="i"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                        :class="[
                            link.active ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-400',
                            !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="glass-card !p-8 flex flex-col items-center justify-center text-center space-y-3 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10">
                <div class="w-14 h-14 rounded-3xl bg-blue-500/10 text-blue-500 flex items-center justify-center text-2xl">
                    👥
                </div>
                <h3 class="text-sm font-bold text-slate-900 dark:text-white">هنوز مخاطبی ثبت نکرده‌اید</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                    می‌توانید شماره‌های همراه، سرشماره‌ها و آیدی‌های اختصاصی (@handle) دوستان و همکاران خود را ذخیره کنید تا در تماس و پیامک به سرعت در دسترس باشند.
                </p>
                <button
                    type="button"
                    @click="openCreateModal"
                    class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl"
                >
                    + افزودن اولین مخاطب
                </button>
            </div>
        </div>

        <!-- Add / Edit Contact Modal -->
        <div
            v-if="isModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/75 backdrop-blur-md"
            @click.self="closeModal"
        >
            <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-[420px] shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white flex items-center gap-2">
                        <span>👥</span>
                        <span>{{ editingContact ? 'ویرایش اطلاعات مخاطب' : 'افزودن مخاطب جدید' }}</span>
                    </h3>
                    <button type="button" @click="closeModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-white">✕</button>
                </div>

                <form @submit.prevent="submitForm" class="space-y-4">
                    <div>
                        <label class="form-label font-bold text-xs">نام و نام‌خانوادگی یا عنوان مخاطب</label>
                        <input
                            type="text"
                            v-model="form.name"
                            placeholder="مثلاً: علی رضایی یا پشتیبانی راینو"
                            required
                            maxlength="120"
                            class="form-input !py-2.5 !text-xs mt-1"
                        />
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">شماره تلفن یا شناسه کاربری (@handle)</label>
                        <input
                            type="text"
                            v-model="form.phone_number"
                            placeholder="مثلاً: +851234567 یا @mybrand"
                            required
                            maxlength="64"
                            dir="ltr"
                            class="form-input !py-2.5 !text-xs mt-1 font-mono text-left"
                        />
                        <p class="text-[11px] text-slate-400 mt-1">می‌توانید هم شماره تلفن و هم آیدی کاربری با علامت @ وارد نمایید.</p>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">رنگ نماد آواتار</label>
                        <div class="flex items-center gap-2 mt-2">
                            <button
                                v-for="(cls, col) in colorClasses"
                                :key="col"
                                type="button"
                                @click="form.avatar_color = String(col)"
                                class="w-8 h-8 rounded-xl border flex items-center justify-center transition-transform active:scale-90"
                                :class="[cls.bg, cls.border, form.avatar_color === col ? 'ring-2 ring-indigo-500 scale-110' : '']"
                            >
                                <span class="w-2.5 h-2.5 rounded-full" :class="cls.text">●</span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">یادداشت اختصاصی (اختیاری)</label>
                        <textarea
                            v-model="form.notes"
                            placeholder="توضیحاتی در مورد این مخاطب..."
                            maxlength="255"
                            rows="2"
                            class="form-input !py-2 !text-xs mt-1"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeModal" class="glass-pill !py-2 !px-4 !text-xs">
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl"
                        >
                            {{ form.processing ? 'در حال ذخیره...' : (editingContact ? 'به‌روزرسانی مخاطب' : 'افزودن به دفترچه تلفن') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div
            v-if="isDeleteModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md"
            @click.self="closeDeleteModal"
        >
            <div class="glass-card responsive-modal-scroll-card my-auto !p-6 w-full max-w-[400px] shadow-2xl relative space-y-4 text-right bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 rounded-3xl">
                <div class="flex items-center gap-3 pb-3 border-b border-slate-200 dark:border-white/10">
                    <div class="w-10 h-10 rounded-2xl bg-rose-500/15 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                        🗑️
                    </div>
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">حذف مخاطب</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">آیا از حذف «{{ contactToDelete?.name }}» اطمینان دارید؟</p>
                    </div>
                </div>

                <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    این مخاطب از دفترچه تلفن شما حذف خواهد شد. سوابق پیام‌ها و تماس‌های قبلی با این شماره پاک نمی‌شوند.
                </p>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-200 dark:border-white/10">
                    <button type="button" @click="closeDeleteModal" class="glass-pill !py-2 !px-4 !text-xs font-bold">
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmDelete"
                        class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition-all shadow-md"
                    >
                        حذف مخاطب
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>

