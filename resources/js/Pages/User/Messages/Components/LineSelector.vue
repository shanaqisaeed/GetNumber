<script setup lang="ts">
import { ref, computed } from 'vue';

export interface LineItem {
    id: number;
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
    isWebSocketActive: boolean;
    connectionLabel: string;
    connectionStatusDotClass: string;
}>();

const emit = defineEmits<{
    (e: 'switch-line', lineId: number): void;
}>();

const isSimSwitcherModalOpen = ref(false);

const currentSimIndex = computed(() => {
    const idx = props.lines.findIndex(l => l.id === props.selectedLineId);
    return idx >= 0 ? idx + 1 : 1;
});

const currentLine = computed(() => {
    return props.lines.find(l => l.id === props.selectedLineId) || null;
});

const currentLineUnreadCount = computed(() => {
    return currentLine.value?.unread_count || 0;
});

const otherLinesTotalUnreadCount = computed(() => {
    return props.lines
        .filter(l => l.id !== props.selectedLineId)
        .reduce((sum, l) => sum + (l.unread_count || 0), 0);
});

const selectLine = (id: number) => {
    isSimSwitcherModalOpen.value = false;
    emit('switch-line', id);
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
                        <span class="text-[11px] font-black text-slate-900 dark:text-white">سیم‌کارت فعال خطوط</span>
                        <span class="px-1.5 py-0.2 rounded-md bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 text-[10px] font-mono font-bold">
                            SIM {{ currentSimIndex }}
                        </span>
                        <span
                            v-if="currentLineUnreadCount > 0"
                            class="px-2 py-0.5 rounded-full bg-rose-600 text-white font-mono text-[10px] font-black shadow-md shadow-rose-500/30 animate-pulse"
                            title="پیام جدید روی این خط"
                        >
                            {{ currentLineUnreadCount }} پیام جدید
                        </span>
                    </div>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-medium block">
                        فرستنده و گیرنده پیامک‌ها
                    </span>
                </div>
            </div>

            <!-- Signal Status Indicator & Switcher Button -->
            <div class="flex items-center gap-2">
                <div
                    class="flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border transition-all cursor-help"
                    :class="isWebSocketActive ? 'text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/20'"
                    :title="connectionLabel"
                >
                    <span class="w-1.5 h-1.5 rounded-full" :class="connectionStatusDotClass"></span>
                    <span>{{ isWebSocketActive ? 'زنده (WebSocket)' : 'همگام‌سازی خودکار' }}</span>
                </div>

                <button
                    v-if="lines.length > 1"
                    type="button"
                    @click="isSimSwitcherModalOpen = true"
                    class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200 dark:border-indigo-800/80 px-2.5 py-1 rounded-xl transition-all flex items-center gap-1.5"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                    </svg>
                    <span>تعویض خط</span>
                    <span
                        v-if="otherLinesTotalUnreadCount > 0"
                        class="px-1.5 py-0.2 rounded-full bg-rose-600 text-white font-mono text-[9px] font-black shadow-sm shadow-rose-500/40 animate-pulse"
                        title="پیام جدید در سایر خطوط"
                    >
                        {{ otherLinesTotalUnreadCount }}
                    </span>
                </button>
            </div>
        </div>

        <!-- Current Active Line Highlight Box -->
        <div v-if="currentLine" class="mt-3 p-3 rounded-2xl bg-indigo-50/70 dark:bg-slate-950/60 border border-indigo-200/80 dark:border-indigo-500/25 flex items-center justify-between">
            <div class="min-w-0">
                <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-medium">عنوان خط فعال:</span>
                <span class="text-xs font-black text-slate-900 dark:text-white truncate block">
                    {{ currentLine.effective_title }}
                </span>
            </div>

            <div dir="ltr" class="text-left font-mono font-black text-sm sm:text-base text-indigo-700 dark:text-indigo-300 tracking-wider select-all">
                {{ currentLine.formatted_number }}
            </div>
        </div>

        <!-- Horizontal Quick-Switch SIM Carousel if user has 2+ lines -->
        <div v-if="lines.length > 1" class="mt-3 flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
            <button
                v-for="(sim, idx) in lines"
                :key="sim.id"
                type="button"
                @click="selectLine(sim.id)"
                class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 shrink-0 border relative"
                :class="[
                    selectedLineId === sim.id
                        ? 'bg-indigo-600 text-white border-indigo-500 shadow-md shadow-indigo-600/25'
                        : 'bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300'
                ]"
            >
                <span class="text-[10px] opacity-75 font-mono">SIM {{ idx + 1 }}</span>
                <span dir="ltr" class="font-mono text-[11px] font-black">{{ sim.formatted_number }}</span>
                <span
                    v-if="(sim.unread_count || 0) > 0"
                    class="px-1.5 py-0.2 rounded-full bg-rose-600 text-white font-mono text-[10px] font-black shadow-sm shadow-rose-500/40 animate-pulse"
                    title="پیام نخوانده"
                >
                    {{ sim.unread_count }}
                </span>
                <span v-if="selectedLineId === sim.id" class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            </button>
        </div>

        <div v-else-if="lines.length === 0" class="mt-3 text-xs text-rose-500 font-bold">
            هیچ خط فعال سازمانی یافت نشد. لطفاً از بخش «خطوط من» شماره جدید تخصیص دهید.
        </div>
    </div>

    <!-- SIM Switcher Modal -->
    <div v-if="isSimSwitcherModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="isSimSwitcherModalOpen = false">
        <div class="glass-card responsive-modal-scroll-card my-auto !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 2h8l4 4v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                            <rect x="8" y="11" width="8" height="7" rx="1" stroke-width="1.5"/>
                            <line x1="12" y1="11" x2="12" y2="18"/>
                            <line x1="8" y1="14.5" x2="16" y2="14.5"/>
                        </svg>
                    </div>
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تعویض خط / سیم‌کارت فرستنده</h3>
                </div>
                <button type="button" @click="isSimSwitcherModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white text-sm">✕</button>
            </div>

            <div class="space-y-2.5">
                <div
                    v-for="(sim, idx) in lines"
                    :key="sim.id"
                    @click="selectLine(sim.id)"
                    class="p-3.5 rounded-2xl border transition-all cursor-pointer flex items-center justify-between"
                    :class="[
                        selectedLineId === sim.id
                            ? 'bg-indigo-50/80 dark:bg-indigo-950/40 border-indigo-500/60 shadow-sm'
                            : 'bg-slate-50 dark:bg-white/5 border-slate-200 dark:border-white/10 hover:border-indigo-400/50'
                    ]"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-xs"
                            :class="selectedLineId === sim.id ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400'"
                        >
                            S{{ idx + 1 }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-black text-slate-900 dark:text-white block">
                                    {{ sim.effective_title }}
                                </span>
                                <span
                                    v-if="(sim.unread_count || 0) > 0"
                                    class="px-2 py-0.5 rounded-full bg-rose-600 text-white font-mono text-[10px] font-black shadow-sm shadow-rose-500/30 animate-pulse"
                                >
                                    {{ sim.unread_count }} پیام جدید
                                </span>
                            </div>
                            <div dir="ltr" class="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400 text-right">
                                {{ sim.formatted_number }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span v-if="selectedLineId === sim.id" class="px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-[10px] font-bold">
                            فعال
                        </span>
                        <div
                            class="w-5 h-5 rounded-full border-2 flex items-center justify-center"
                            :class="selectedLineId === sim.id ? 'border-indigo-600 bg-indigo-600' : 'border-slate-300 dark:border-slate-600'"
                        >
                            <div v-if="selectedLineId === sim.id" class="w-2 h-2 rounded-full bg-white"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2 border-t border-slate-200 dark:border-white/10">
                <button type="button" @click="isSimSwitcherModalOpen = false" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                    بستن
                </button>
            </div>
        </div>
    </div>
</template>

