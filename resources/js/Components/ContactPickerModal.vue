<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

export interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes?: string | null;
}

const props = withDefaults(
    defineProps<{
        show: boolean;
        allowMultiple?: boolean;
        initialContacts?: ContactItem[];
    }>(),
    {
        allowMultiple: false,
        initialContacts: () => [],
    }
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select', contact: ContactItem): void;
    (e: 'selectMultiple', contacts: ContactItem[]): void;
}>();

const contacts = ref<ContactItem[]>([...props.initialContacts]);
const isLoading = ref(false);
const searchQuery = ref('');
const selectedIds = ref<number[]>([]);

const fetchContacts = async () => {
    isLoading.value = true;
    try {
        const res = await axios.get(route('contacts.picker'));
        if (res.data?.contacts && Array.isArray(res.data.contacts)) {
            contacts.value = res.data.contacts;
        }
    } catch {
        // Fallback to initial contacts
    } finally {
        isLoading.value = false;
    }
};

watch(
    () => props.show,
    (val) => {
        if (val) {
            searchQuery.value = '';
            selectedIds.value = [];
            if (contacts.value.length === 0) {
                fetchContacts();
            }
        }
    }
);

const filteredContacts = computed(() => {
    if (!searchQuery.value.trim()) return contacts.value;
    const q = searchQuery.value.trim().toLowerCase();
    return contacts.value.filter(
        (c) =>
            c.name.toLowerCase().includes(q) ||
            c.phone_number.toLowerCase().includes(q) ||
            (c.notes && c.notes.toLowerCase().includes(q))
    );
});

const isSelected = (id: number) => selectedIds.value.includes(id);

const toggleSelection = (contact: ContactItem) => {
    if (!props.allowMultiple) {
        emit('select', contact);
        emit('close');
        return;
    }

    const idx = selectedIds.value.indexOf(contact.id);
    if (idx > -1) {
        selectedIds.value.splice(idx, 1);
    } else {
        selectedIds.value.push(contact.id);
    }
};

const confirmMultiple = () => {
    const selected = contacts.value.filter((c) => selectedIds.value.includes(c.id));
    emit('selectMultiple', selected);
    emit('close');
};

const getAvatarBg = (color?: string) => {
    switch (color) {
        case 'rose':
            return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30';
        case 'amber':
            return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'emerald':
            return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
        case 'blue':
            return 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/30';
        case 'purple':
            return 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border-purple-500/30';
        default:
            return 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/30';
    }
};
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">انتخاب از دفترچه مخاطبین</h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                            {{ allowMultiple ? 'یک یا چند مخاطب را جهت افزودن انتخاب نمایید.' : 'مخاطب مورد نظر را برای انتخاب کلیک نمایید.' }}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    @click="emit('close')"
                    class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors"
                >
                    ✕
                </button>
            </div>

            <!-- Search input -->
            <div class="relative">
                <input
                    type="text"
                    v-model="searchQuery"
                    placeholder="جستجو بر اساس نام، شماره تلفن یا آیدی..."
                    class="form-input !py-2.5 !pr-9 !pl-8 !text-xs w-full"
                    autofocus
                />
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-3 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <button
                    v-if="searchQuery"
                    type="button"
                    @click="searchQuery = ''"
                    class="w-5 h-5 rounded-full bg-slate-200 dark:bg-white/10 text-slate-500 hover:text-slate-900 dark:hover:text-white absolute left-3 top-3 flex items-center justify-center text-[10px]"
                >
                    ✕
                </button>
            </div>

            <!-- Contacts list -->
            <div class="max-h-64 overflow-y-auto space-y-2 pr-1">
                <div v-if="isLoading" class="text-center py-8 text-xs text-slate-400 flex items-center justify-center gap-2">
                    <span class="w-4 h-4 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></span>
                    <span>در حال دریافت لیست مخاطبین...</span>
                </div>

                <template v-else-if="filteredContacts.length > 0">
                    <button
                        v-for="contact in filteredContacts"
                        :key="contact.id"
                        type="button"
                        @click="toggleSelection(contact)"
                        class="w-full text-right p-3 rounded-2xl border transition-all flex items-center justify-between gap-3 group"
                        :class="isSelected(contact.id)
                            ? 'bg-indigo-50 dark:bg-indigo-950/40 border-indigo-500 text-slate-900 dark:text-white shadow-xs'
                            : 'bg-slate-50/80 hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800 border-slate-200/80 dark:border-white/5'"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <!-- Avatar -->
                            <div
                                class="w-10 h-10 rounded-2xl flex items-center justify-center text-xs font-black shrink-0 border"
                                :class="getAvatarBg(contact.avatar_color)"
                            >
                                {{ contact.name ? contact.name.charAt(0) : '؟' }}
                            </div>

                            <div class="min-w-0">
                                <div class="font-bold text-xs text-slate-900 dark:text-white truncate">
                                    {{ contact.name }}
                                </div>
                                <div class="font-mono text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate" dir="ltr">
                                    {{ contact.phone_number }}
                                </div>
                                <div v-if="contact.notes" class="text-[10px] text-slate-400 truncate mt-0.5">
                                    {{ contact.notes }}
                                </div>
                            </div>
                        </div>

                        <!-- Checkmark or Action -->
                        <div class="shrink-0 flex items-center">
                            <div
                                v-if="allowMultiple"
                                class="w-5 h-5 rounded-lg border flex items-center justify-center transition-colors"
                                :class="isSelected(contact.id)
                                    ? 'bg-indigo-600 border-indigo-600 text-white'
                                    : 'border-slate-300 dark:border-white/20'"
                            >
                                <svg v-if="isSelected(contact.id)" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                                </svg>
                            </div>

                            <span
                                v-else
                                class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 group-hover:underline px-2 py-1 rounded-lg group-hover:bg-indigo-500/10 transition-colors"
                            >
                                انتخاب
                            </span>
                        </div>
                    </button>
                </template>

                <!-- Empty state -->
                <div v-else class="text-center py-8 space-y-2">
                    <div class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 mx-auto flex items-center justify-center text-base">
                        👥
                    </div>
                    <div class="text-xs font-bold text-slate-700 dark:text-slate-300">مخاطبی یافت نشد</div>
                    <p class="text-[11px] text-slate-400 max-w-xs mx-auto">
                        {{ searchQuery ? 'هیچ مخاطبی با عبارت جستجو مطابقت ندارد.' : 'هنوز مخاطبی در دفترچه تلفن شما ثبت نشده است.' }}
                    </p>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-between pt-3 border-t border-slate-200 dark:border-white/10 text-xs">
                <Link
                    :href="route('contacts.index')"
                    @click="emit('close')"
                    class="text-indigo-600 dark:text-indigo-400 hover:underline font-bold text-[11px] flex items-center gap-1"
                >
                    <span>+ مدیریت مخاطبین</span>
                </Link>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="emit('close')"
                        class="glass-pill !py-2 !px-4 !text-xs font-bold"
                    >
                        انصراف
                    </button>

                    <button
                        v-if="allowMultiple"
                        type="button"
                        @click="confirmMultiple"
                        :disabled="selectedIds.length === 0"
                        class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl disabled:opacity-40"
                    >
                        افزودن ({{ selectedIds.length }})
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

