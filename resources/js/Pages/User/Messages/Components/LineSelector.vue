<script setup lang="ts">
import { ref, computed } from 'vue';

export interface LineItem {
    id: number;
    key?: string;
    number: string;
    formatted_number: string;
    effective_title: string;
    type: string;
    type_label: string;
    badge_class: string;
    unread_count?: number;
}

const props = defineProps<{
    lines: LineItem[];
    selectedLineId: number | null;
    selectedLineKey?: string | null;
    isWebSocketActive: boolean;
    connectionLabel: string;
    connectionStatusDotClass: string;
}>();

const emit = defineEmits<{
    (e: 'switch-line', lineId: number, lineKey?: string): void;
}>();

const isSimSwitcherModalOpen = ref(false);

const currentLine = computed(() => {
    if (props.selectedLineKey) {
        const found = props.lines.find(l => (l.key || (l.type === 'custom_id' ? 'custom_' + l.id : 'phone_' + l.id)) === props.selectedLineKey);
        if (found) return found;
    }
    return props.lines.find(l => l.id === props.selectedLineId) || null;
});

const currentSimIndex = computed(() => {
    const idx = props.lines.findIndex(l => currentLine.value && l.id === currentLine.value.id && l.type === currentLine.value.type);
    return idx >= 0 ? idx + 1 : 1;
});

const currentLineUnreadCount = computed(() => {
    return currentLine.value?.unread_count || 0;
});

const otherLinesTotalUnreadCount = computed(() => {
    return props.lines
        .filter(l => currentLine.value && !(l.id === currentLine.value.id && l.type === currentLine.value.type))
        .reduce((sum, l) => sum + (l.unread_count || 0), 0);
});

const isSimActive = (sim: LineItem) => {
    if (props.selectedLineKey) {
        return (sim.key || (sim.type === 'custom_id' ? 'custom_' + sim.id : 'phone_' + sim.id)) === props.selectedLineKey;
    }
    return currentLine.value ? (sim.id === currentLine.value.id && sim.type === currentLine.value.type) : false;
};

const selectLine = (sim: LineItem) => {
    isSimSwitcherModalOpen.value = false;
    const key = sim.key || (sim.type === 'custom_id' ? 'custom_' + sim.id : 'phone_' + sim.id);
    emit('switch-line', sim.id, key);
};
</script>

<template>
    <!-- Multi-SIM Switcher Card -->
    <div class="glass-card !p-4 border-indigo-500/30 dark:border-indigo-500/30 shadow-md">
        <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200/80 dark:border-white/10">
            <div class="flex items-center gap-2">
                <!-- Real SIM Card SVG Icon -->
                <div class="w-8 h-8 rounded-xl bg-indigo-500/15 dark:bg-indigo-500/25 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 border border-indigo-500/30">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 2h8l4 4v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                        <rect x="8" y="11" width="8" height="7" rx="1" stroke-width="1.5"/>
                        <line x1="12" y1="11" x2="12" y2="18"/>
                        <line x1="8" y1="14.5" x2="16" y2="14.5"/>
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-black text-slate-900 dark:text-white">{{ __('user.messages.active_sim') }}</span>
                        <span class="px-1.5 py-0.2 rounded-md bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-[10px] font-mono font-bold">
                            SIM {{ currentSimIndex }}
                        </span>
                        <span
                            v-if="currentLineUnreadCount > 0"
                            class="px-2 py-0.5 rounded-full bg-rose-600 text-white font-mono text-[10px] font-black shadow-md shadow-rose-500/30 animate-pulse"
                            :title="__('user.messages.new_message_on_line')"
                        >
                            {{ __('user.messages.new_messages_count', { count: currentLineUnreadCount }) }}
                        </span>
                    </div>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-medium block">
                        {{ __('user.messages.inbound_outbound_role') }}
                    </span>
                </div>
            </div>

            <!-- Signal Status Indicator -->
            <div class="flex items-center gap-2">
                <div
                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border transition-all cursor-help"
                    :class="isWebSocketActive ? 'text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/20'"
                    :title="connectionLabel"
                >
                    <span class="w-1.5 h-1.5 rounded-full" :class="connectionStatusDotClass"></span>
                    <span>{{ isWebSocketActive ? __('user.messages.websocket_live') : __('user.messages.auto_sync') }}</span>
                </div>
            </div>
        </div>

        <!-- Current Active Line Highlight Box -->
        <div v-if="currentLine" class="mt-3 p-3 rounded-2xl bg-indigo-50/70 dark:bg-slate-950/60 border border-indigo-200/80 dark:border-indigo-500/25 flex items-center justify-between">
            <div class="min-w-0">
                <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-medium">{{ __('user.messages.current_line_title') }}</span>
                <span class="text-xs font-black text-slate-900 dark:text-white truncate block">
                    {{ currentLine.effective_title }}
                </span>
            </div>

            <div dir="ltr" class="text-left font-mono font-black text-sm sm:text-base text-indigo-700 dark:text-indigo-300 tracking-wider select-all">
                {{ currentLine.formatted_number }}
            </div>
        </div>

        <div v-else-if="lines.length === 0" class="mt-3 text-xs text-rose-500 font-bold">
            {{ __('user.messages.no_lines_found') }}
        </div>
    </div>
</template>

