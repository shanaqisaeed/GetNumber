<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

interface LineItem {
    id: number;
    number: string;
    formatted_number: string;
    effective_title: string;
    type: string;
    type_label: string;
    badge_class: string;
}

interface ConversationItem {
    peer_number: string;
    peer_formatted: string;
    peer_title: string;
    peer_type?: string;
    peer_type_label?: string;
    peer_badge_class?: string;
    is_own_number?: boolean;
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

interface ChatMessage {
    id: number;
    phone_number_id: number;
    destination_number: string;
    sender: string;
    peer_number: string;
    direction: 'inbound' | 'outbound';
    body: string;
    server_received_at: string;
    server_received_at_formatted: string;
    processing_status: string;
}

const props = defineProps<{
    allocatedNumbers: LineItem[];
    currentNumber: LineItem | null;
    conversations: ConversationItem[];
    activePeer: string | null;
    activePeerDetails: {
        number: string;
        formatted_number: string;
        title: string;
        type?: string;
        type_label?: string;
        badge_class?: string;
        is_own_number?: boolean;
    } | null;
    activeMessages: ChatMessage[];
    stats: {
        total_messages: number;
        today_messages: number;
        allocated_lines_count: number;
    };
    search?: string;
}>();

// Selected line ID (SIM / sender number switcher)
const selectedLineId = ref<number | null>(props.currentNumber?.id || null);
const isSimSwitcherModalOpen = ref(false);

// Active SIM metadata
const currentSimIndex = computed(() => {
    const idx = props.allocatedNumbers.findIndex(l => l.id === selectedLineId.value);
    return idx >= 0 ? idx + 1 : 1;
});

// Conversations list & active chat state
const conversationList = ref<ConversationItem[]>([...props.conversations]);
const currentActivePeer = ref<string | null>(props.activePeer || null);
const chatMessages = ref<ChatMessage[]>([...props.activeMessages]);
const currentPeerInfo = ref(props.activePeerDetails || null);
const isLoadingChat = ref(false);

// Search query in conversations list
const searchQuery = ref(props.search || '');

// Quick reply text in active conversation
const replyText = ref('');
const isSendingReply = ref(false);

// New Message Screen state (Phone "To:" box experience)
const isComposingNew = ref<boolean>(false);
const recipientInput = ref('');
const recipientChips = ref<string[]>([]);
const composerText = ref('');
const isSendingComposer = ref(false);
const composeFeedback = ref<{ success: boolean; message: string; results?: any[] } | null>(null);

// Chat stream container for auto-scroll
const chatContainer = ref<HTMLElement | null>(null);

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;
const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
};

// Copy phone number or message
const copyToClipboard = async (text: string, label = 'شماره کپی شد') => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }
        showToast(label);
    } catch {
        showToast(label);
    }
};

const scrollToBottom = () => {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
};

const getHighestMessageId = () => {
    if (chatMessages.value.length === 0) return 0;
    return Math.max(...chatMessages.value.map(m => m.id));
};

// Silent Polling in background
let pollingInterval: any = null;
const isPollingBusy = ref(false);

const performSilentPoll = async () => {
    if (isPollingBusy.value || !selectedLineId.value) return;
    if (document.hidden) return;

    isPollingBusy.value = true;
    try {
        const params = new URLSearchParams();
        params.append('phone_number_id', String(selectedLineId.value));
        if (currentActivePeer.value && !isComposingNew.value) {
            params.append('peer', currentActivePeer.value);
            params.append('after_id', String(getHighestMessageId()));
        }
        if (searchQuery.value) {
            params.append('search', searchQuery.value);
        }

        const res = await fetch(`${route('messages.poll')}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (res.ok) {
            const data = await res.json();
            if (data.success) {
                if (Array.isArray(data.conversations)) {
                    conversationList.value = data.conversations;
                }

                if (Array.isArray(data.new_messages) && data.new_messages.length > 0 && currentActivePeer.value && !isComposingNew.value) {
                    const existingIds = new Set(chatMessages.value.map(m => m.id));
                    const fresh = data.new_messages.filter((m: ChatMessage) => !existingIds.has(m.id));
                    if (fresh.length > 0) {
                        chatMessages.value = [...chatMessages.value, ...fresh];
                        scrollToBottom();
                    }
                }
            }
        }
    } catch {
        // Silently tolerate
    } finally {
        isPollingBusy.value = false;
    }
};

onMounted(() => {
    if (currentActivePeer.value) {
        scrollToBottom();
    }
    pollingInterval = setInterval(performSilentPoll, 4000);
});

onUnmounted(() => {
    if (pollingInterval) clearInterval(pollingInterval);
});

// Switching sending line
const switchLine = (lineId: number) => {
    if (selectedLineId.value === lineId) {
        isSimSwitcherModalOpen.value = false;
        return;
    }
    selectedLineId.value = lineId;
    isSimSwitcherModalOpen.value = false;
    router.get(
        route('messages.index'),
        {
            phone_number_id: lineId,
            search: searchQuery.value || undefined,
        },
        { preserveState: false }
    );
};

// Start composing a new message
const startNewMessage = () => {
    isComposingNew.value = true;
    currentActivePeer.value = null;
    currentPeerInfo.value = null;
    recipientChips.value = [];
    recipientInput.value = '';
    composerText.value = '';
    composeFeedback.value = null;
    chatMessages.value = [];
};

// Return to conversations list (Mobile Back Button)
const closeChatOrComposer = () => {
    currentActivePeer.value = null;
    currentPeerInfo.value = null;
    isComposingNew.value = false;
    chatMessages.value = [];
    replyText.value = '';
    composerText.value = '';
    recipientChips.value = [];
    performSilentPoll();
};

// Select a conversation from list (User explicitly clicks on a contact/number)
const selectConversation = async (conv: ConversationItem) => {
    isComposingNew.value = false;
    currentActivePeer.value = conv.peer_number;
    currentPeerInfo.value = {
        number: conv.peer_number,
        formatted_number: conv.peer_formatted,
        title: conv.peer_title,
        type: conv.peer_type,
        type_label: conv.peer_type_label,
        badge_class: conv.peer_badge_class,
        is_own_number: conv.is_own_number,
    };

    // Mark as read immediately in local state
    const foundIndex = conversationList.value.findIndex(c => c.peer_number === conv.peer_number);
    if (foundIndex !== -1) {
        conversationList.value[foundIndex].unread_count = 0;
    }

    isLoadingChat.value = true;
    chatMessages.value = [];

    try {
        const res = await fetch(
            `${route('messages.conversation')}?phone_number_id=${selectedLineId.value}&peer=${encodeURIComponent(conv.peer_number)}`,
            {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }
        );
        if (res.ok) {
            const data = await res.json();
            if (data.success && Array.isArray(data.messages)) {
                chatMessages.value = data.messages;
                scrollToBottom();
            }
        }
    } catch {
        showToast('خطا در بارگذاری پیام‌ها');
    } finally {
        isLoadingChat.value = false;
    }
};

// Recipient chips handlers
const addRecipientChip = (raw: string) => {
    const val = raw.trim();
    if (!val) return;
    if (!recipientChips.value.includes(val)) {
        recipientChips.value.push(val);
    }
    recipientInput.value = '';
};

const removeRecipientChip = (index: number) => {
    recipientChips.value.splice(index, 1);
};

const onRecipientKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
        e.preventDefault();
        addRecipientChip(recipientInput.value);
    } else if (e.key === 'Backspace' && !recipientInput.value && recipientChips.value.length > 0) {
        recipientChips.value.pop();
    }
};

// Send message from composer
const sendFromComposer = async () => {
    if (recipientInput.value.trim()) {
        addRecipientChip(recipientInput.value);
    }
    if (recipientChips.value.length === 0) {
        showToast('لطفاً حداقل یک شماره مقصد وارد کنید');
        return;
    }
    if (!composerText.value.trim() || !selectedLineId.value) {
        showToast('متن پیام نمی‌تواند خالی باشد');
        return;
    }

    isSendingComposer.value = true;
    composeFeedback.value = null;

    try {
        const res = await fetch(route('messages.send'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                from_number_id: selectedLineId.value,
                destinations: recipientChips.value,
                body: composerText.value.trim(),
            }),
        });

        const data = await res.json();
        composeFeedback.value = {
            success: data.success,
            message: data.message,
            results: data.results,
        };

        if (data.success) {
            showToast('پیامک با موفقیت ارسال شد');
            if (recipientChips.value.length === 1) {
                const targetPeer = recipientChips.value[0].replace(/^\+?85/, '').trim();
                composerText.value = '';
                router.get(
                    route('messages.index'),
                    { phone_number_id: selectedLineId.value, peer: targetPeer },
                    { preserveState: false }
                );
            } else {
                composerText.value = '';
                recipientChips.value = [];
                performSilentPoll();
            }
        } else {
            showToast(data.message || 'خطا در ارسال پیامک');
        }
    } catch {
        composeFeedback.value = {
            success: false,
            message: 'خطا در ارتباط با سرور.',
        };
        showToast('خطا در برقراری ارتباط');
    } finally {
        isSendingComposer.value = false;
    }
};

// Send reply in active conversation
const sendReply = async () => {
    if (!replyText.value.trim() || !selectedLineId.value || !currentActivePeer.value) return;

    isSendingReply.value = true;
    const textToSend = replyText.value.trim();
    replyText.value = '';

    try {
        const res = await fetch(route('messages.send'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                from_number_id: selectedLineId.value,
                destinations: [currentActivePeer.value],
                body: textToSend,
            }),
        });

        const data = await res.json();
        if (data.success) {
            chatMessages.value.push({
                id: data.results?.[0]?.message_id || Date.now(),
                phone_number_id: selectedLineId.value,
                destination_number: currentActivePeer.value,
                sender: props.currentNumber?.number || '',
                peer_number: currentActivePeer.value,
                direction: 'outbound',
                body: textToSend,
                server_received_at: new Date().toISOString(),
                server_received_at_formatted: 'هم‌اکنون',
                processing_status: 'processed',
            });
            scrollToBottom();
            performSilentPoll();
        } else {
            showToast(data.message || 'خطا در ارسال پیامک.');
            replyText.value = textToSend;
        }
    } catch {
        showToast('خطای ارتباط با سرور.');
        replyText.value = textToSend;
    } finally {
        isSendingReply.value = false;
    }
};

// Filtered conversations
const filteredConversations = computed(() => {
    if (!searchQuery.value.trim()) return conversationList.value;
    const q = searchQuery.value.trim().toLowerCase();
    return conversationList.value.filter(c =>
        c.peer_number.toLowerCase().includes(q) ||
        c.peer_title.toLowerCase().includes(q) ||
        c.latest_message.body.toLowerCase().includes(q)
    );
});

// Format time
const formatTime = (isoString: string) => {
    try {
        const d = new Date(isoString);
        return new Intl.DateTimeFormat('fa-IR', {
            hour: '2-digit',
            minute: '2-digit',
        }).format(d);
    } catch {
        return '';
    }
};

// Calculate SMS parts
const smsPartCount = computed(() => {
    const len = composerText.value.length;
    if (len === 0) return 0;
    if (len <= 70) return 1;
    return Math.ceil(len / 67);
});
</script>

<template>
    <Head title="پیام‌ها و گفتگوها" />

    <UserLayout>
        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4 scale-95" enter-to-class="opacity-100 translate-y-0 scale-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 -translate-y-2 scale-95">
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
                >
                    <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-4">
            <!-- ========================================================== -->
            <!-- 1. HIGH-TECH DUAL-SIM / MULTI-SIM SWITCHER CARD             -->
            <!-- ========================================================== -->
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
                            </div>
                            <span class="text-[10px] text-slate-500 dark:text-slate-400 font-medium block">
                                فرستنده و گیرنده پیامک‌ها
                            </span>
                        </div>
                    </div>

                    <!-- Signal Status Indicator & Switcher Button -->
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1 text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full text-[10px] font-bold">
                            <!-- Signal Bars SVG -->
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="2" y="17" width="3" height="5" rx="1"/>
                                <rect x="7" y="13" width="3" height="9" rx="1"/>
                                <rect x="12" y="9" width="3" height="13" rx="1"/>
                                <rect x="17" y="4" width="3" height="18" rx="1"/>
                            </svg>
                            <span>آنلاین</span>
                        </div>

                        <button
                            v-if="allocatedNumbers.length > 1"
                            type="button"
                            @click="isSimSwitcherModalOpen = true"
                            class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200 dark:border-indigo-800/80 px-2.5 py-1 rounded-xl transition-all flex items-center gap-1"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                            <span>تعویض خط</span>
                        </button>
                    </div>
                </div>

                <!-- Current Active Line Highlight Box -->
                <div v-if="currentNumber" class="mt-3 p-3 rounded-2xl bg-indigo-50/70 dark:bg-slate-950/60 border border-indigo-200/80 dark:border-indigo-500/25 flex items-center justify-between">
                    <div class="min-w-0">
                        <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-medium">عنوان خط فعال:</span>
                        <span class="text-xs font-black text-slate-900 dark:text-white truncate block">
                            {{ currentNumber.effective_title }}
                        </span>
                    </div>

                    <div dir="ltr" class="text-left font-mono font-black text-sm sm:text-base text-indigo-700 dark:text-indigo-300 tracking-wider select-all">
                        {{ currentNumber.formatted_number }}
                    </div>
                </div>

                <!-- Horizontal Quick-Switch SIM Carousel if user has 2+ lines -->
                <div v-if="allocatedNumbers.length > 1" class="mt-3 flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
                    <button
                        v-for="(sim, idx) in allocatedNumbers"
                        :key="sim.id"
                        type="button"
                        @click="switchLine(sim.id)"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 shrink-0 border"
                        :class="[
                            selectedLineId === sim.id
                                ? 'bg-indigo-600 text-white border-indigo-500 shadow-md shadow-indigo-600/25'
                                : 'bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300'
                        ]"
                    >
                        <span class="text-[10px] opacity-75 font-mono">SIM {{ idx + 1 }}</span>
                        <span dir="ltr" class="font-mono text-[11px] font-black">{{ sim.formatted_number }}</span>
                        <span v-if="selectedLineId === sim.id" class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    </button>
                </div>

                <div v-else-if="allocatedNumbers.length === 0" class="mt-3 text-xs text-rose-500 font-bold">
                    هیچ خط فعال سازمانی یافت نشد. لطفاً از بخش «خطوط من» شماره جدید تخصیص دهید.
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- VIEW A: CONVERSATIONS LIST (When no chat is active)        -->
            <!-- EXACTLY LIKE MOBILE: Chat does NOT open until clicked!     -->
            <!-- ========================================================== -->
            <div v-if="!currentActivePeer && !isComposingNew" class="space-y-3.5">
                <!-- Action Header: Search & Compose Button -->
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <input
                            v-model="searchQuery"
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
                        @click="startNewMessage"
                        :disabled="!currentNumber"
                        class="glass-btn-primary !w-auto !py-2.5 !px-3.5 !text-xs !rounded-2xl flex items-center gap-1.5 shrink-0"
                        title="پیام جدید"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span class="hidden sm:inline">پیام جدید</span>
                    </button>
                </div>

                <!-- Conversations List -->
                <div v-if="filteredConversations.length > 0" class="space-y-2.5">
                    <div
                        v-for="conv in filteredConversations"
                        :key="conv.peer_number"
                        @click="selectConversation(conv)"
                        class="glass-card !p-3.5 flex items-center justify-between gap-3 cursor-pointer hover:border-indigo-500/50 hover:scale-[1.01] active:scale-[0.99] transition-all group bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
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
                                <div dir="ltr" class="text-[10px] font-mono font-bold text-slate-500 dark:text-slate-400 text-right">
                                    {{ conv.peer_formatted }}
                                </div>
                                <!-- Latest Message Snippet -->
                                <p class="text-xs text-slate-600 dark:text-slate-300 truncate mt-0.5 max-w-[240px]">
                                    <span v-if="conv.latest_message.direction === 'outbound'" class="text-indigo-600 dark:text-indigo-400 font-bold ml-1">شما:</span>
                                    {{ conv.latest_message.body }}
                                </p>
                            </div>
                        </div>

                        <!-- Right Info: Timestamp & Unread Badge -->
                        <div class="shrink-0 flex flex-col items-end justify-between self-stretch py-0.5">
                            <span class="text-[10px] font-mono text-slate-500 dark:text-slate-400">
                                {{ conv.latest_message.server_received_at_formatted }}
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
                    <button
                        type="button"
                        @click="startNewMessage"
                        class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl inline-flex items-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>ارسال اولین پیامک</span>
                    </button>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- VIEW B: ACTIVE CHAT SCREEN (Mobile Chat Experience)        -->
            <!-- Opens ONLY when a contact is selected                     -->
            <!-- ========================================================== -->
            <div v-else-if="currentActivePeer" class="space-y-3">
                <!-- Chat Navigation Header with Back Button -->
                <div class="glass-card !p-3 flex items-center justify-between gap-2.5 bg-white/95 dark:bg-slate-900/80 border-slate-200/90 dark:border-white/10">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <!-- Back Button (RTL points right) -->
                        <button
                            type="button"
                            @click="closeChatOrComposer"
                            class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white flex items-center justify-center shrink-0 transition-colors"
                            title="بازگشت به لیست گفتگوها"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <!-- Contact Details -->
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                                    {{ currentPeerInfo?.title || currentActivePeer }}
                                </span>
                                <span v-if="currentPeerInfo?.type_label" class="text-[9px] px-1.5 py-0.2 rounded-md font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                                    {{ currentPeerInfo?.type_label }}
                                </span>
                            </div>
                            <div dir="ltr" class="text-[10px] font-mono text-slate-500 dark:text-slate-400 text-right">
                                {{ currentPeerInfo?.formatted_number || currentActivePeer }}
                            </div>
                        </div>
                    </div>

                    <!-- Header Action: Copy Number -->
                    <div class="flex items-center gap-1.5 shrink-0">
                        <button
                            type="button"
                            @click="copyToClipboard(currentPeerInfo?.formatted_number || currentActivePeer || '', 'شماره در کلیپ‌بورد کپی شد')"
                            class="w-8 h-8 rounded-xl glass-pill !p-0 flex items-center justify-center text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                            title="کپی شماره"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Chat Messages Stream -->
                <div
                    ref="chatContainer"
                    class="glass-card !p-3.5 space-y-3 min-h-[380px] max-h-[55vh] overflow-y-auto scroll-smooth bg-white/70 dark:bg-slate-950/50 border-slate-200/80 dark:border-white/10"
                >
                    <!-- Loading Indicator -->
                    <div v-if="isLoadingChat" class="py-12 text-center text-xs text-slate-500 dark:text-slate-400 flex items-center justify-center gap-2">
                        <svg class="animate-spin w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>در حال بارگذاری تاریخچه پیام‌ها...</span>
                    </div>

                    <!-- Messages Stream -->
                    <div v-else-if="chatMessages.length > 0" class="space-y-2.5">
                        <div
                            v-for="msg in chatMessages"
                            :key="msg.id"
                            class="flex flex-col"
                            :class="msg.direction === 'outbound' ? 'items-end' : 'items-start'"
                        >
                            <!-- Message Bubble -->
                            <div
                                class="max-w-[85%] rounded-2xl p-3 text-xs leading-relaxed shadow-md select-text"
                                :class="[
                                    msg.direction === 'outbound'
                                        ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-bl-sm'
                                        : 'bg-white dark:bg-slate-800 border border-slate-200/90 dark:border-slate-700/80 text-slate-900 dark:text-slate-100 rounded-br-sm'
                                ]"
                            >
                                <p class="whitespace-pre-wrap break-words font-medium">{{ msg.body }}</p>

                                <!-- Time & Status Row -->
                                <div
                                    class="flex items-center gap-1.5 mt-1.5 text-[9px] font-mono"
                                    :class="msg.direction === 'outbound' ? 'text-indigo-200 justify-end' : 'text-slate-500 dark:text-slate-400 justify-start'"
                                >
                                    <span>{{ formatTime(msg.server_received_at) || msg.server_received_at_formatted }}</span>
                                    <svg v-if="msg.direction === 'outbound'" class="w-3.5 h-3.5 text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty thread placeholder -->
                    <div v-else class="py-16 text-center text-xs text-slate-500 dark:text-slate-400 space-y-2">
                        <div class="w-10 h-10 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 mx-auto flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <p>پیامی برای نمایش در این گفتگو وجود ندارد.</p>
                    </div>
                </div>

                <!-- Chat Reply Bar with Crystal-Clear Material Send SVG -->
                <div class="glass-card !p-2 flex items-center gap-2 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                    <input
                        v-model="replyText"
                        @keydown.enter.prevent="sendReply"
                        type="text"
                        placeholder="پیام خود را بنویسید..."
                        :disabled="isSendingReply"
                        class="flex-1 bg-slate-100/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-3.5 py-2 text-xs text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:border-indigo-500"
                    />

                    <!-- Ultra Sharp Material/Telegram Send Button -->
                    <button
                        type="button"
                        @click="sendReply"
                        :disabled="!replyText.trim() || isSendingReply"
                        class="w-10 h-10 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white flex items-center justify-center shadow-md shadow-indigo-500/30 disabled:opacity-40 disabled:cursor-not-allowed transition-all active:scale-95 shrink-0"
                        title="ارسال پیام"
                    >
                        <svg v-if="isSendingReply" class="animate-spin w-5 h-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <!-- Standard Telegram / Material Paper Plane Icon (Oriented in RTL) -->
                        <svg v-else class="w-5 h-5 -scale-x-100 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- VIEW C: NEW MESSAGE COMPOSER SCREEN                        -->
            <!-- ========================================================== -->
            <div v-else-if="isComposingNew" class="space-y-3.5">
                <!-- Composer Header with Back Button -->
                <div class="glass-card !p-3 flex items-center justify-between gap-2.5 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                    <div class="flex items-center gap-2.5">
                        <button
                            type="button"
                            @click="closeChatOrComposer"
                            class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white flex items-center justify-center shrink-0 transition-colors"
                            title="بازگشت"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        <span class="text-xs font-black text-slate-900 dark:text-white">ارسال پیامک جدید</span>
                    </div>
                </div>

                <!-- Recipient Field ("To:") -->
                <div class="glass-card !p-3.5 space-y-2 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                    <label class="text-[11px] font-bold text-slate-600 dark:text-slate-300 block">گیرنده (شماره مقصد):</label>
                    <div class="flex flex-wrap items-center gap-1.5 p-2 rounded-2xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10 focus-within:border-indigo-500 transition-colors">
                        <!-- Chips -->
                        <span
                            v-for="(chip, idx) in recipientChips"
                            :key="idx"
                            class="inline-flex items-center gap-1 text-[11px] font-mono font-bold bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 border border-indigo-500/30 px-2 py-0.5 rounded-xl"
                        >
                            <span>{{ chip }}</span>
                            <button type="button" @click="removeRecipientChip(idx)" class="hover:text-rose-500">✕</button>
                        </span>

                        <input
                            v-model="recipientInput"
                            @keydown="onRecipientKeyDown"
                            @blur="addRecipientChip(recipientInput)"
                            type="text"
                            placeholder="شماره مقصد را وارد و اینتر بزنید..."
                            class="flex-1 min-w-[150px] bg-transparent border-0 p-1 text-xs text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:ring-0"
                            dir="ltr"
                        />
                    </div>
                </div>

                <!-- Message Body Textarea -->
                <div class="glass-card !p-3.5 space-y-2 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                    <div class="flex items-center justify-between">
                        <label class="text-[11px] font-bold text-slate-600 dark:text-slate-300">متن پیامک:</label>
                        <span class="text-[10px] font-mono text-slate-500 dark:text-slate-400">
                            {{ composerText.length }} کاراکتر ({{ smsPartCount }} پیامک)
                        </span>
                    </div>

                    <textarea
                        v-model="composerText"
                        rows="5"
                        placeholder="متن پیام خود را بنویسید..."
                        class="w-full glass-input !p-3 !text-xs !rounded-2xl resize-none bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                    ></textarea>

                    <button
                        type="button"
                        @click="sendFromComposer"
                        :disabled="isSendingComposer || !composerText.trim()"
                        class="glass-btn-primary !w-full !py-3 !text-xs !rounded-2xl mt-2 flex items-center justify-center gap-2"
                    >
                        <svg v-if="isSendingComposer" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4 -scale-x-100 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                        <span>ارسال پیامک</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- SIM Switcher Modal (For easily choosing between lines) -->
        <div v-if="isSimSwitcherModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md">
            <div class="glass-card !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 2h8l4 4v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                                <rect x="8" y="11" width="8" height="7" rx="1" stroke-width="1.5"/>
                            </svg>
                        </div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">تعویض خط / سیم‌کارت فرستنده</h3>
                    </div>
                    <button type="button" @click="isSimSwitcherModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white text-sm">✕</button>
                </div>

                <div class="space-y-2.5 max-h-[60vh] overflow-y-auto">
                    <div
                        v-for="(sim, idx) in allocatedNumbers"
                        :key="sim.id"
                        @click="switchLine(sim.id)"
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
                                <span class="text-xs font-black text-slate-900 dark:text-white block">
                                    {{ sim.effective_title }}
                                </span>
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
    </UserLayout>
</template>
