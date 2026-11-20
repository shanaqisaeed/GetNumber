<script setup lang="ts">
import OrgLayout from '@/Layouts/OrgLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { __ } from '@/Composables/useTrans';

interface MemberItem {
    id: number;
    user_id: number;
    name: string;
    email?: string;
    username?: string;
    account_id?: string;
    role: string;
    status: string;
    assigned_line: any;
    joined_at: string;
}

function formatAssignedLine(line: any): string {
    if (!line) return '';
    let parsed = line;
    if (typeof line === 'string') {
        const trimmed = line.trim();
        if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
            try {
                parsed = JSON.parse(trimmed);
            } catch {
                return trimmed;
            }
        } else {
            return trimmed;
        }
    }
    if (parsed && typeof parsed === 'object') {
        const num = parsed.formatted_number || parsed.number || '';
        const title = parsed.display_title || '';
        if (num && title && num !== title) {
            return `${num} (${title})`;
        }
        return num || title || '';
    }
    return String(parsed);
}

interface AvailableLineItem {
    id: number;
    key: string;
    number: string;
    formatted_number: string;
    display_title: string;
    type: string;
    type_label: string;
}

const props = defineProps<{
    members: MemberItem[];
    available_lines: AvailableLineItem[];
}>();

// Add Member Modal State
const isAddModalOpen = ref(false);
const addForm = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    role: 'member',
});

function submitAddMember() {
    addForm.post(route('org.team.store'), {
        onSuccess: () => {
            isAddModalOpen.value = false;
            addForm.reset();
        },
    });
}

// Edit Member Modal State
const isEditModalOpen = ref(false);
const editingMember = ref<MemberItem | null>(null);
const editForm = useForm({
    role: 'member',
    status: 'active',
});

function openEditModal(member: MemberItem) {
    editingMember.value = member;
    editForm.role = member.role;
    editForm.status = member.status;
    isEditModalOpen.value = true;
}

function submitEditMember() {
    if (!editingMember.value) return;
    editForm.patch(route('org.team.update', { member: editingMember.value.id }), {
        onSuccess: () => {
            isEditModalOpen.value = false;
            editingMember.value = null;
        },
    });
}

// Assign Line Modal State
const isAssignModalOpen = ref(false);
const activeMemberForLine = ref<MemberItem | null>(null);
const assignForm = useForm({
    line_key: '',
});

function openAssignModal(member: MemberItem) {
    activeMemberForLine.value = member;
    assignForm.line_key = props.available_lines[0]?.key || '';
    isAssignModalOpen.value = true;
}

function submitAssignLine() {
    if (!activeMemberForLine.value) return;
    assignForm.post(route('org.team.assign_line', { member: activeMemberForLine.value.id }), {
        onSuccess: () => {
            isAssignModalOpen.value = false;
            activeMemberForLine.value = null;
        },
    });
}

function unassignLine(member: MemberItem) {
    if (!confirm(__('organization.team.unassign_confirm'))) return;
    router.post(route('org.team.unassign_line', { member: member.id }));
}

function removeMember(member: MemberItem) {
    if (!confirm(__('organization.team.remove_confirm'))) return;
    router.delete(route('org.team.destroy', { member: member.id }));
}
</script>

<template>
    <OrgLayout>
        <Head :title="__('organization.team.title')" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                        {{ __('organization.team.title') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('organization.team.assign_rule_notice') }}
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
                    <span>{{ __('organization.team.add_member') }}</span>
                </button>
            </div>

            <!-- Members Table Card -->
            <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-4 font-bold">{{ __('organization.team.name') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.team.username') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.team.role') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.team.assigned_line') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.team.status') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.team.joined_at') }}</th>
                                <th class="p-4 font-bold text-left">{{ __('organization.lines.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <tr v-for="member in members" :key="member.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="p-4 font-bold text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold text-xs">
                                            {{ member.name ? member.name.charAt(0) : 'U' }}
                                        </div>
                                        <span>{{ member.name }}</span>
                                    </div>
                                </td>
                                <td class="p-4 text-slate-500 font-mono text-xs" dir="ltr">
                                    {{ member.username ? (member.username.startsWith('@') ? member.username : '@' + member.username) : (member.email || '—') }}
                                </td>
                                <td class="p-4">
                                    <span
                                        class="px-2 py-0.5 rounded-lg text-[11px] font-bold"
                                        :class="member.role === 'admin' ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                                    >
                                        {{ member.role === 'admin' ? __('organization.team.role_admin') : __('organization.team.role_member') }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div v-if="member.assigned_line" class="flex items-center gap-2">
                                        <span class="px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-500 font-mono font-bold text-xs border border-emerald-500/20" dir="ltr">
                                            {{ formatAssignedLine(member.assigned_line) }}
                                        </span>
                                        <button
                                            type="button"
                                            @click="unassignLine(member)"
                                            class="p-1 rounded-lg text-slate-400 hover:text-rose-500 hover:bg-rose-500/10 transition-colors"
                                            :title="__('organization.team.unassign_line')"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    <button
                                        v-else
                                        type="button"
                                        @click="openAssignModal(member)"
                                        class="px-2.5 py-1 rounded-xl bg-purple-600/10 hover:bg-purple-600/20 text-purple-600 dark:text-purple-400 border border-purple-500/20 font-bold text-[11px] transition-all"
                                    >
                                        + {{ __('organization.team.assign_line') }}
                                    </button>
                                </td>
                                <td class="p-4">
                                    <span
                                        class="inline-flex items-center gap-1.5 text-[11px] font-bold"
                                        :class="member.status === 'active' ? 'text-emerald-500' : 'text-slate-400'"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="member.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                        <span>{{ member.status === 'active' ? __('organization.team.status_active') : __('organization.team.status_inactive') }}</span>
                                    </span>
                                </td>
                                <td class="p-4 text-slate-400 font-mono">{{ member.joined_at }}</td>
                                <td class="p-4 text-left">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            type="button"
                                            @click="openEditModal(member)"
                                            class="p-1.5 rounded-xl text-slate-400 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-purple-500/10 transition-colors"
                                            :title="__('organization.team.edit_member')"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            @click="removeMember(member)"
                                            class="p-1.5 rounded-xl text-slate-400 hover:text-rose-500 hover:bg-rose-500/10 transition-colors"
                                            :title="__('organization.team.remove_member')"
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

        <!-- Add Member Modal -->
        <Modal :show="isAddModalOpen" max-width="md" @close="isAddModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.team.add_member') }}
                    </h3>
                    <button type="button" @click="isAddModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <form @submit.prevent="submitAddMember" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.name') }}
                        </label>
                        <input
                            v-model="addForm.name"
                            type="text"
                            required
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.username') }}
                        </label>
                        <input
                            v-model="addForm.username"
                            type="text"
                            required
                            dir="ltr"
                            :placeholder="__('organization.team.username_placeholder')"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none"
                        />
                        <p class="text-[10px] text-slate-400 mt-1">
                            {{ __('organization.team.username_help') }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.initial_password') }}
                        </label>
                        <input
                            v-model="addForm.password"
                            type="password"
                            dir="ltr"
                            :placeholder="__('organization.team.initial_password_placeholder')"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.role') }}
                        </label>
                        <select
                            v-model="addForm.role"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none"
                        >
                            <option value="member">{{ __('organization.team.role_member') }}</option>
                            <option value="admin">{{ __('organization.team.role_admin') }}</option>
                        </select>
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
                            :disabled="addForm.processing"
                            class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold shadow-md shadow-purple-600/30 disabled:opacity-50"
                        >
                            {{ __('organization.team.add_member') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Edit Member Modal -->
        <Modal :show="isEditModalOpen" max-width="md" @close="isEditModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.team.edit_modal_title') }}
                    </h3>
                    <button type="button" @click="isEditModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <form @submit.prevent="submitEditMember" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.name') }}
                        </label>
                        <input
                            :value="editingMember?.name"
                            disabled
                            type="text"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 text-xs text-slate-500 outline-none cursor-not-allowed"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.role') }}
                        </label>
                        <select
                            v-model="editForm.role"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none"
                        >
                            <option value="member">{{ __('organization.team.role_member') }}</option>
                            <option value="admin">{{ __('organization.team.role_admin') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.status') }}
                        </label>
                        <select
                            v-model="editForm.status"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none"
                        >
                            <option value="active">{{ __('organization.team.status_active') }}</option>
                            <option value="inactive">{{ __('organization.team.status_inactive') }}</option>
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="isEditModalOpen = false"
                            class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            {{ __('ui.buttons.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold shadow-md shadow-purple-600/30 disabled:opacity-50"
                        >
                            {{ __('organization.team.save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Assign Line Modal -->
        <Modal :show="isAssignModalOpen" max-width="md" @close="isAssignModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.team.assign_line') }}
                    </h3>
                    <button type="button" @click="isAssignModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <div v-if="available_lines.length === 0" class="p-4 rounded-2xl bg-amber-500/10 text-amber-500 text-xs font-bold text-center">
                    {{ __('organization.team.no_available_lines') }}
                </div>

                <form v-else @submit.prevent="submitAssignLine" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.team.select_line_to_assign') }}
                        </label>
                        <select
                            v-model="assignForm.line_key"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none"
                            dir="ltr"
                        >
                            <option
                                v-for="line in available_lines"
                                :key="line.key"
                                :value="line.key"
                            >
                                {{ line.formatted_number }} ({{ line.display_title }})
                            </option>
                        </select>
                    </div>

                    <p class="text-[11px] text-slate-400">
                        {{ __('organization.team.assign_rule_notice') }}
                    </p>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="isAssignModalOpen = false"
                            class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            {{ __('ui.buttons.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="assignForm.processing"
                            class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold shadow-md shadow-purple-600/30 disabled:opacity-50"
                        >
                            {{ __('organization.team.assign_line') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </OrgLayout>
</template>
