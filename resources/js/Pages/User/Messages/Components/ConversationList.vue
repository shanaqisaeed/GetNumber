<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';

export interface ConversationItem {
    peer_number: string;
    peer_formatted: string;
    peer_title: string;
    peer_type?: string;
    peer_type_label?: string;
    peer_badge_class?: string;
    is_own_number?: boolean;
    is_commercial?: boolean;
    accepts_incoming_sms?: boolean;
    can_reply?: boolean;
    can_reply_reason?: string;
    latest_message: {
        id: number;
        body: string;
        direction: 'inbound' | 'outbound';
        server_received_at: string;
        server_received_at_formatted: string;
    };
    messages_count: number;
    unread_count: number;
}

const props = defineProps<{
    conversations: ConversationItem[];
    hasCurrentNumber: boolean;
    initialSearch?: string;
}>();

const emit = defineEmits<{
    (e: 'select', conv: ConversationItem): void;
    (e: 'start-new'): void;
    (e: 'search-change', query: string): void;
}>();

const searchQuery = ref(props.initialSearch || '');

const filteredConversations = computed(() => {
    if (!searchQuery.value.trim()) return props.conversations;
    const q = searchQuery.value.trim().toLowerCase();
    return props.conversations.filter(c =>
        c.peer_number.toLowerCase().includes(q) ||
        c.peer_title.toLowerCase().includes(q) ||
        (c.latest_message?.body && c.latest_message.body.toLowerCase().includes(q))
    );
});

// Virtual Scrolling Configuration
const ITEM_HEIGHT = 74; // Estimated height per conversation card in px
const BUFFER_ITEMS = 6;
const scrollContainer = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(600);

const onScroll = () => {
    if (scrollContainer.value) {
        scrollTop.value = scrollContainer.value.scrollTop;
    }
};

const totalCount = computed(() => filteredConversations.value.length);
const totalHeight = computed(() => totalCount.value * ITEM_HEIGHT);

const startIndex = computed(() => {
    const idx = Math.floor(scrollTop.value / ITEM_HEIGHT) - BUFFER_ITEMS;
    return Math.max(0, idx);
});

const endIndex = computed(() => {
    const visibleCount = Math.ceil(containerHeight.value / ITEM_HEIGHT);
    const idx = startIndex.value + visibleCount + (BUFFER_ITEMS * 2);
    return Math.min(totalCount.value, idx);
});

const visibleItems = computed(() => {
    return filteredConversations.value.slice(startIndex.value, endIndex.value).map((item, index) => ({
        ...item,
        _virtualIndex: startIndex.value + index,
    }));
});

const offsetY = computed(() => startIndex.value * ITEM_HEIGHT);

onMounted(() => {
    if (scrollContainer.value) {
        containerHeight.value = scrollContainer.value.clientHeight || 600;
    }
});
</script>

<template>
    <div class="space-y-3.5">
        <!-- In-Platform Messaging Disclaimer -->
        <div class="px-3.5 py-2.5 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/60 dark:border-indigo-500/15 flex items-start gap-2.5 text-xs text-indigo-900 dark:text-indigo-200/90 leading-relaxed mb-1">
            <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>
                <b>ارتباطات درون‌پلتفرمی:</b> پیام‌ها صرفاً در محیط پلتفرم و میان خطوط سازمانی ثبت و تبادل می‌شوند و هیچ‌گونه پیامک مخابراتی تجاری ارسال نمی‌گردد.
            </span>
        </div>

        <!-- Action Header: Search & Compose Button -->
        <div class="flex items-center gap-2">
            <div class="relative flex-1">
                <input
                    v-model="searchQuery"
                    @input="emit('search-change', searchQuery)"
                    type="text"
                    placeholder="جستجو در مخاطبان یا پیام‌ها..."
                    class="glass-input !py-2.5 !pr-9 !pl-3 !text-xs !rounded-2xl w-full bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                />
                <svg class="w-4 h-4 text-slate-400 absolute right-3 top-3 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>

            <button
                type="button"
                @click="emit('start-new')"
                :disabled="!hasCurrentNumber"
                class="glass-btn-primary !w-auto !py-2.5 !px-3.5 !text-xs !rounded-2xl flex items-center gap-1.5 shrink-0"
                title="پیام جدید"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span class="hidden sm:inline">پیام جدید</span>
            </button>
        </div>

        <!-- Virtual Scrolled Conversations List -->
        <div
            v-if="filteredConversations.length > 0"
            ref="scrollContainer"
            @scroll="onScroll"
            class="max-h-[640px] overflow-y-auto relative rounded-2xl scrollbar-none"
        >
            <div :style="{ height: `${totalHeight}px`, position: 'relative' }">
                <div
                    :style="{ transform: `translateY(${offsetY}px)`, position: 'absolute', top: 0, left: 0, right: 0 }"
                    class="space-y-2.5"
                >
                    <div
                        v-for="conv in visibleItems"
                        :key="conv.peer_number"
                        @click="emit('select', conv)"
                        class="glass-card !p-3 flex items-center justify-between gap-3 cursor-pointer hover:border-indigo-500/50 hover:scale-[1.01] active:scale-[0.99] transition-all group bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                        style="height: 68px;"
                    >
                        <!-- Contact Avatar & Info -->
                        <div class="flex items-center gap-3 min-w-0">
                            <!-- Avatar with Gradient Ring -->
                            <div class="relative shrink-0">
                                <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-indigo-600/15 via-purple-600/15 to-pink-600/15 border border-indigo-200 dark:border-white/10 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-extrabold text-sm group-hover:from-indigo-600 group-hover:to-purple-600 group-hover:text-white transition-all shadow-sm">
                                    {{ conv.peer_title ? conv.peer_title.charAt(0) : '#' }}
                                </div>
                                <span v-if="conv.unread_count > 0" class="status-dot-pulse absolute -top-1 -right-1"></span>
                            </div>

                            <!-- Details -->
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                                        {{ conv.peer_title }}
                                    </span>
                                    <span v-if="conv.peer_type_label" class="text-[9px] px-1.5 py-0.2 rounded-md font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                                        {{ conv.peer_type_label }}
                                    </span>
                                </div>
                                <div v-if="!conv.is_commercial" dir="ltr" class="text-[10px] font-mono font-bold text-slate-500 dark:text-slate-400 text-right">
                                    {{ conv.peer_formatted }}
                                </div>
                                <div v-else class="text-[10px] text-violet-600 dark:text-violet-400 font-bold">
                                    سرشماره تجاری رسمی
                                </div>
                                <!-- Latest Message Snippet -->
                                <p class="text-xs text-slate-600 dark:text-slate-300 truncate mt-0.5 max-w-[240px]">
                                    <span v-if="conv.latest_message?.direction === 'outbound'" class="text-indigo-600 dark:text-indigo-400 font-bold ml-1">شما:</span>
                                    {{ conv.latest_message?.body }}
                                </p>
                            </div>
                        </div>

                        <!-- Right Info: Timestamp & Unread Badge -->
                        <div class="shrink-0 flex flex-col items-end justify-between self-stretch py-0.5">
                            <span class="text-[10px] font-mono text-slate-500 dark:text-slate-400">
                                {{ conv.latest_message?.server_received_at_formatted }}
                            </span>
                            <span
                                v-if="conv.unread_count > 0"
                                class="mt-1 px-2 py-0.5 rounded-full bg-gradient-to-r from-pink-500 to-rose-500 text-white font-mono text-[10px] font-black shadow-md shadow-rose-500/30 animate-pulse"
                            >
                                {{ conv.unread_count }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty State (No Conversations) -->
        <div v-else class="glass-card !p-8 text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
            <div class="w-14 h-14 rounded-3xl bg-indigo-500/15 border border-indigo-500/30 text-indigo-600 dark:text-indigo-400 mx-auto flex items-center justify-center">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
            </div>
            <div class="space-y-1">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white">هنوز گفتگویی ندارید</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                    پیامک‌های دریافتی یا ارسالی شما در این بخش مانند پیام‌رسان گوشی نمایش داده می‌شوند.
                </p>
            </div>
            <div class="justify-center">
                <button
                    type="button"
                    @click="emit('start-new')"
                    class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl inline-flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>ارسال اولین پیامک</span>
                </button>
            </div>
        </div>
    </div>
</template>

