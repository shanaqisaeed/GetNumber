<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { __ } from '@/Composables/useTrans';
import axios from 'axios';

export interface LineOption {
    id: number;
    key: string;
    number: string;
    formatted_number: string;
    display_title: string;
    type: 'number' | 'custom_id';
    type_label: string;
    status: string;
    is_organization?: boolean;
    organization_name?: string | null;
}

const props = defineProps<{
    show: boolean;
    lines: LineOption[];
    activeLineKey?: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'switched', line: LineOption): void;
}>();

const isSubmitting = ref(false);
const errorMessage = ref<string | null>(null);

async function selectLine(line: LineOption) {
    if (line.key === props.activeLineKey) {
        emit('close');
        return;
    }

    isSubmitting.value = true;
    errorMessage.value = null;

    try {
        const response = await axios.post(route('softphone.switch_line'), {
            line_key: line.key,
        });

        if (response.data.success) {
            emit('switched', line);
            emit('close');
            router.reload();
        }
    } catch (err: any) {
        errorMessage.value = err.response?.data?.message || __('softphone.line.cannot_switch_during_call');
    } finally {
        isSubmitting.value = false;
    }
}

function goToBuyService() {
    emit('close');
    router.visit(route('services.index'));
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="emit('close')">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-500/15 text-indigo-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-800 dark:text-white">
                            {{ __('softphone.line.switch_line') }}
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ __('softphone.line.select_line_desc') }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    @click="emit('close')"
                    class="p-1 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors"
                >
                    ✕
                </button>
            </div>

            <!-- Error Banner -->
            <div v-if="errorMessage" class="mt-4 p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ errorMessage }}</span>
            </div>

            <!-- Lines List -->
            <div class="mt-4 space-y-2.5 max-h-72 overflow-y-auto pr-1">
                <div
                    v-for="line in lines"
                    :key="line.key"
                    @click="selectLine(line)"
                    class="p-3.5 rounded-2xl border transition-all cursor-pointer flex items-center justify-between gap-3"
                    :class="[
                        line.key === activeLineKey
                            ? 'bg-indigo-500/15 border-indigo-500/50 shadow-md shadow-indigo-500/10'
                            : 'bg-slate-50 dark:bg-white/5 border-slate-200 dark:border-white/10 hover:border-indigo-500/30'
                    ]"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <div
                            class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                            :class="line.type === 'number' ? 'bg-indigo-500/20 text-indigo-400' : 'bg-purple-500/20 text-purple-400'"
                        >
                            <svg v-if="line.type === 'number'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-sm text-slate-800 dark:text-white truncate" dir="ltr">
                                    {{ line.formatted_number || line.number }}
                                </span>
                                <span
                                    v-if="line.is_organization"
                                    class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-500 font-bold border border-emerald-500/30"
                                >
                                    {{ line.organization_name || __('organization.dashboard.title') }}
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-400">
                                {{ line.type_label }}
                            </div>
                        </div>
                    </div>

                    <div class="shrink-0 flex items-center gap-2">
                        <span
                            v-if="line.key === activeLineKey"
                            class="text-[11px] font-black px-2.5 py-1 rounded-xl bg-indigo-600 text-white shadow-sm flex items-center gap-1"
                        >
                            ✓ {{ __('softphone.line.active_line_badge') }}
                        </span>
                        <span v-else class="text-xs text-slate-400 group-hover:text-indigo-400">
                            ←
                        </span>
                    </div>
                </div>
            </div>

            <!-- Footer: Buy New Service Button -->
            <div class="mt-5 pt-4 border-t border-slate-200 dark:border-white/10 flex items-center justify-between gap-3">
                <button
                    type="button"
                    @click="goToBuyService"
                    class="w-full py-3 px-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs font-black shadow-lg shadow-indigo-600/25 flex items-center justify-center gap-2 transition-all active:scale-95"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ __('softphone.line.buy_new_line') }}</span>
                </button>
            </div>
        </div>
    </Modal>
</template>
