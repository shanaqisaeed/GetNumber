<script setup lang="ts">
import OrgLayout from '@/Layouts/OrgLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { __ } from '@/Composables/useTrans';

interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes: string | null;
    created_at: string;
}

const props = defineProps<{
    contacts: ContactItem[];
}>();

const searchQuery = ref('');
const isAddModalOpen = ref(false);

const filteredContacts = computed(() => {
    if (!searchQuery.value.trim()) return props.contacts;
    const q = searchQuery.value.toLowerCase();
    return props.contacts.filter(c =>
        c.name.toLowerCase().includes(q) ||
        c.phone_number.includes(q) ||
        (c.notes && c.notes.toLowerCase().includes(q))
    );
});

const form = useForm({
    name: '',
    type: 'phone' as 'phone' | 'custom_id',
    phone_number: '',
    notes: '',
});

function submitAddContact() {
    const raw = form.phone_number.trim();
    if (form.type === 'custom_id' && !raw.startsWith('@')) {
        form.phone_number = '@' + raw;
    }
    form.post(route('org.contacts.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            form.reset();
            form.type = 'phone';
        },
    });
}

function deleteContact(contact: ContactItem) {
    if (!confirm(__('organization.contacts.delete_confirm'))) return;
    router.delete(route('org.contacts.destroy', { contact: contact.id }));
}
</script>

<template>
    <OrgLayout>
        <Head :title="__('organization.contacts.title')" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                        {{ __('organization.contacts.title') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('organization.contacts.desc') }}
                    </p>
                </div>

                <button
                    type="button"
                    @click="isAddModalOpen = true"
                    class="px-4 py-2.5 rounded-2xl bg-purple-600 hover:bg-purple-500 text-white font-extrabold text-xs shadow-lg shadow-purple-600/30 transition-all flex items-center gap-2 self-start sm:self-auto active:scale-95"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ __('organization.contacts.add_contact') }}</span>
                </button>
            </div>

            <!-- Search Bar -->
            <div class="max-w-md">
                <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="__('organization.contacts.search_placeholder')"
                    class="w-full px-4 py-2.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-xs focus:ring-2 focus:ring-purple-500 outline-none shadow-sm"
                />
            </div>

            <!-- Empty State -->
            <div v-if="filteredContacts.length === 0" class="p-12 text-center rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <div class="text-4xl">👥</div>
                <p class="text-xs text-slate-500 font-bold max-w-sm mx-auto">
                    {{ __('organization.contacts.no_contacts') }}
                </p>
            </div>

            <!-- Contacts Grid -->
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="contact in filteredContacts"
                    :key="contact.id"
                    class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-3 relative group hover:border-purple-500/40 transition-all flex flex-col justify-between"
                >
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-10 h-10 rounded-2xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold text-sm shrink-0">
                                    {{ contact.name ? contact.name.charAt(0) : 'C' }}
                                </div>
                                <div class="truncate">
                                    <h3 class="text-xs font-black text-slate-900 dark:text-white truncate">
                                        {{ contact.name }}
                                    </h3>
                                    <p class="text-xs font-mono font-bold text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">
                                        {{ contact.phone_number }}
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="deleteContact(contact)"
                                class="p-1.5 rounded-xl text-slate-400 hover:text-rose-500 hover:bg-rose-500/10 opacity-0 group-hover:opacity-100 transition-opacity"
                                :title="__('ui.buttons.delete')"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>

                        <div v-if="contact.notes" class="text-[11px] text-slate-400 border-t border-slate-100 dark:border-slate-800 pt-2 line-clamp-2">
                            {{ contact.notes }}
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 space-y-2">
                        <!-- Direct Softphone Actions -->
                        <div class="flex items-center gap-2">
                            <Link
                                :href="route('messages.index', { peer: contact.phone_number, to: contact.phone_number })"
                                class="flex-1 py-1.5 px-2 rounded-xl bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-500 text-center text-[11px] font-bold transition-all flex items-center justify-center gap-1"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                                <span>{{ __('organization.contacts.open_in_softphone_chat') }}</span>
                            </Link>
                            <Link
                                :href="route('calls.index', { destination: contact.phone_number, peer: contact.phone_number, to: contact.phone_number })"
                                class="py-1.5 px-2.5 rounded-xl bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-500 text-[11px] font-bold transition-all flex items-center justify-center"
                                :title="__('organization.contacts.call_in_softphone')"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </Link>
                        </div>

                        <div class="flex items-center justify-between text-[10px] text-slate-400">
                            <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-purple-500 font-bold">
                                {{ __('organization.contacts.shared_badge') }}
                            </span>
                            <span>{{ contact.created_at }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Contact Modal -->
        <Modal :show="isAddModalOpen" max-width="md" @close="isAddModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.contacts.add_contact') }}
                    </h3>
                    <button type="button" @click="isAddModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <form @submit.prevent="submitAddContact" class="space-y-4">
                    <!-- Contact Type Selection (Phone vs Custom ID) -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.contacts.contact_type') }}
                        </label>
                        <div class="grid grid-cols-2 gap-2 p-1 rounded-2xl bg-slate-100 dark:bg-slate-800">
                            <button
                                type="button"
                                @click="form.type = 'phone'"
                                class="py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5"
                                :class="form.type === 'phone' ? 'bg-white dark:bg-slate-900 text-purple-600 dark:text-purple-400 shadow-sm' : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                            >
                                <span>📞</span>
                                <span>{{ __('organization.contacts.type_phone') }}</span>
                            </button>
                            <button
                                type="button"
                                @click="form.type = 'custom_id'"
                                class="py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5"
                                :class="form.type === 'custom_id' ? 'bg-white dark:bg-slate-900 text-purple-600 dark:text-purple-400 shadow-sm' : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                            >
                                <span>🆔</span>
                                <span>{{ __('organization.contacts.type_custom_id') }}</span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.contacts.name') }}
                        </label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ form.type === 'custom_id' ? __('organization.contacts.type_custom_id') : __('organization.contacts.type_phone') }}
                        </label>
                        <input
                            v-model="form.phone_number"
                            type="text"
                            required
                            dir="ltr"
                            :placeholder="form.type === 'custom_id' ? __('organization.contacts.identifier_placeholder_id') : __('organization.contacts.identifier_placeholder_phone')"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.contacts.notes') }}
                        </label>
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none resize-none"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="isAddModalOpen = false"
                            class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            {{ __('ui.buttons.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold shadow-md shadow-purple-600/30 disabled:opacity-50"
                        >
                            {{ __('organization.contacts.add_contact') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </OrgLayout>
</template>
