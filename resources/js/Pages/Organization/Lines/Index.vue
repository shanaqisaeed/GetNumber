<script setup lang="ts">
import OrgLayout from '@/Layouts/OrgLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { __ } from '@/Composables/useTrans';

interface LineItem {
    id: number;
    key: string;
    number: string;
    formatted_number: string;
    display_title: string;
    type: 'number' | 'custom_id';
    type_label: string;
    status: string;
    assigned_to: {
        id: number;
        member_id?: number;
        name: string;
        email: string;
    } | null;
    allocated_at: string;
}

interface EligibleMember {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{
    lines: LineItem[];
    eligible_members?: EligibleMember[];
}>();

const currentFilter = ref<'all' | 'assigned' | 'unassigned'>('all');

const filteredLines = computed(() => {
    if (currentFilter.value === 'assigned') {
        return props.lines.filter(l => l.assigned_to !== null);
    }
    if (currentFilter.value === 'unassigned') {
        return props.lines.filter(l => l.assigned_to === null);
    }
    return props.lines;
});

// Direct Line Assignment Modal State
const isAssignModalOpen = ref(false);
const lineToAssign = ref<LineItem | null>(null);
const assignForm = useForm({
    member_id: '',
    line_key: '',
});

function openAssignModal(line: LineItem) {
    lineToAssign.value = line;
    assignForm.line_key = line.key;
    assignForm.member_id = props.eligible_members?.[0]?.id ? String(props.eligible_members[0].id) : '';
    isAssignModalOpen.value = true;
}

function submitAssignLine() {
    if (!lineToAssign.value || !assignForm.member_id) return;
    assignForm.line_key = lineToAssign.value.key;
    assignForm.post(route('org.team.assign_line', { member: assignForm.member_id }), {
        onSuccess: () => {
            isAssignModalOpen.value = false;
            lineToAssign.value = null;
        },
    });
}

function unassignLine(line: LineItem) {
    if (!line.assigned_to) return;
    if (!confirm(__('organization.lines.unassign_confirm'))) return;
    router.post(route('org.team.unassign_line', { member: line.assigned_to.member_id || line.assigned_to.id }));
}
</script>

<template>
    <OrgLayout>
        <Head :title="__('organization.lines.inventory_title')" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                        {{ __('organization.lines.inventory_title') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('organization.dashboard.subtitle') }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('org.requests.index')"
                        class="px-4 py-2.5 rounded-2xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-extrabold text-xs shadow-lg shadow-purple-600/30 transition-all flex items-center gap-2"
                    >
                        <span>⭐</span>
                        <span>{{ __('organization.lines.buy_new_org_line') }}</span>
                    </Link>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="currentFilter = 'all'"
                    class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                    :class="currentFilter === 'all' ? 'bg-purple-600 text-white shadow-sm' : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-800'"
                >
                    {{ __('organization.lines.filter_all') }} ({{ lines.length }})
                </button>
                <button
                    type="button"
                    @click="currentFilter = 'assigned'"
                    class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                    :class="currentFilter === 'assigned' ? 'bg-purple-600 text-white shadow-sm' : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-800'"
                >
                    {{ __('organization.lines.filter_assigned') }} ({{ lines.filter(l => l.assigned_to).length }})
                </button>
                <button
                    type="button"
                    @click="currentFilter = 'unassigned'"
                    class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                    :class="currentFilter === 'unassigned' ? 'bg-purple-600 text-white shadow-sm' : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-800'"
                >
                    {{ __('organization.lines.filter_unassigned') }} ({{ lines.filter(l => !l.assigned_to).length }})
                </button>
            </div>

            <!-- Empty State -->
            <div v-if="filteredLines.length === 0" class="p-12 text-center rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                <div class="text-4xl">📞</div>
                <p class="text-xs text-slate-500 font-bold max-w-sm mx-auto">
                    {{ __('organization.lines.no_lines') }}
                </p>
                <Link
                    :href="route('org.requests.index')"
                    class="inline-flex px-4 py-2 rounded-xl bg-purple-600 text-white text-xs font-bold"
                >
                    {{ __('organization.lines.buy_new_org_line') }}
                </Link>
            </div>

            <!-- Lines Table Card -->
            <div v-else class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-4 font-bold">{{ __('organization.lines.number') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.lines.type') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.lines.holder') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.team.status') }}</th>
                                <th class="p-4 font-bold">{{ __('organization.lines.allocated_at') }}</th>
                                <th class="p-4 font-bold text-left">{{ __('organization.lines.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <tr v-for="line in filteredLines" :key="line.key" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="p-4 font-bold text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full" :class="line.assigned_to ? 'bg-emerald-500' : 'bg-purple-500'"></span>
                                        <span class="font-mono text-sm" dir="ltr">{{ line.formatted_number }}</span>
                                    </div>
                                    <div class="text-[11px] text-slate-400">{{ line.display_title }}</div>
                                </td>
                                <td class="p-4">
                                    <span
                                        class="px-2 py-0.5 rounded-lg text-[11px] font-bold"
                                        :class="line.type === 'number' ? 'bg-indigo-500/10 text-indigo-500' : 'bg-emerald-500/10 text-emerald-500'"
                                    >
                                        {{ line.type_label }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div v-if="line.assigned_to" class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-lg bg-slate-200 dark:bg-slate-800 flex items-center justify-center font-bold text-[10px]">
                                            {{ line.assigned_to.name.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-800 dark:text-slate-200">{{ line.assigned_to.name }}</p>
                                            <p class="text-[10px] text-slate-400">{{ line.assigned_to.email }}</p>
                                        </div>
                                    </div>
                                    <span v-else class="text-slate-400 font-bold text-[11px] italic">
                                        {{ __('organization.lines.unassigned') }}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <span class="text-emerald-500 font-bold text-[11px]">
                                        {{ __('organization.lines.status_active') }}
                                    </span>
                                </td>
                                <td class="p-4 text-slate-400 font-mono">{{ line.allocated_at }}</td>
                                <td class="p-4 text-left">
                                    <button
                                        v-if="line.assigned_to"
                                        type="button"
                                        @click="unassignLine(line)"
                                        class="px-3 py-1 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-500 font-bold text-[11px] transition-all"
                                    >
                                        {{ __('organization.lines.revoke') }}
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        @click="openAssignModal(line)"
                                        class="px-3 py-1 rounded-xl bg-purple-500/10 hover:bg-purple-500/20 text-purple-600 dark:text-purple-400 font-bold text-[11px] transition-all"
                                    >
                                        {{ __('organization.lines.assign_to_member') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Direct Line Assignment Modal -->
        <Modal :show="isAssignModalOpen" max-width="md" @close="isAssignModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.lines.assign_to_member') }}
                    </h3>
                    <button type="button" @click="isAssignModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <div v-if="lineToAssign" class="p-3 rounded-2xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-between">
                    <span class="text-xs text-slate-400">{{ __('organization.lines.number') }}:</span>
                    <span class="font-mono font-black text-purple-600 dark:text-purple-400 text-sm" dir="ltr">
                        {{ lineToAssign.formatted_number }}
                    </span>
                </div>

                <div v-if="!eligible_members || eligible_members.length === 0" class="p-4 rounded-2xl bg-amber-500/10 text-amber-500 text-xs font-bold text-center">
                    {{ __('organization.lines.no_eligible_members') }}
                </div>

                <form v-else @submit.prevent="submitAssignLine" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            {{ __('organization.lines.select_member_to_assign') }}
                        </label>
                        <select
                            v-model="assignForm.member_id"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none"
                        >
                            <option
                                v-for="member in eligible_members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.name }} ({{ member.email }})
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
                            {{ __('organization.lines.assign_to_member') }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </OrgLayout>
</template>
