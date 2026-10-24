<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';

interface MessageUser {
    id: number;
    name: string;
    username: string;
}

interface ConversationParty {
    id?: number;
    number: string;
    formatted: string;
    display_title?: string | null;
    effective_title: string;
    is_internal?: boolean;
    type?: string;
    type_label?: string;
    type_badge?: string;
    user?: MessageUser | null;
}

interface AdminConversationItem {
    id: string;
    phone_number_id: number;
    peer_number: string;
    line_a: ConversationParty;
    line_b: ConversationParty;
    latest_message: {
        id: number;
        body: string;
        sender_number: string;
        sender_name: string;
        receiver_name: string;
        sender_is_line_a: boolean;
        destination_number: string;
        direction: string;
        direction_label: string;
        processing_status: string;
        status_label: string;
        status_badge: string;
        server_received_at: string;
        server_received_at_formatted: string;
    };
    messages_count: number;
    unread_count: number;
    failed_count: number;
}

interface ChatMessage {
    id: number;
    phone_number_id: number;
    body: string;
    direction: string;
    direction_label: string;
    is_line_a: boolean;
    sender: {
        number: string;
        formatted: string;
        name: string;
        username?: string | null;
    };
    receiver: {
        number: string;
        formatted: string;
        name: string;
    };
    processing_status: string;
    status_label: string;
    status_badge: string;
    client_message_id?: string | null;
    source_system_id?: string | null;
    server_received_at: string;
    server_received_at_formatted: string;
    server_received_time: string;
    isNew?: boolean;
}

interface AdminMessageItem {
    id: number;
    phone_number_id: number;
    destination_number: string;
    sender: string;
    body: string;
    source_time: string | null;
    server_received_at: string;
    server_received_at_formatted?: string;
    processing_status: 'received' | 'processed' | 'failed';
    status_label?: string;
    status_badge?: string;
    client_message_id: string | null;
    source_system_id: string | null;
    user?: MessageUser | null;
    phoneNumber?: {
        id: number;
        number: string;
        type: string;
        user?: MessageUser | null;
    };
    isNew?: boolean;
}

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
}

const props = defineProps<{
    messages: PaginatedData<AdminMessageItem>;
    conversations?: AdminConversationItem[];
    selectedConversation?: AdminConversationItem | null;
    activeMessages?: ChatMessage[];
    phoneNumbers?: {
        id: number;
        number: string;
        formatted: string;
        title: string;
        type: string;
        type_label: string;
        user?: MessageUser | null;
    }[];
    stats: {
        total_messages: number;
        today_messages: number;
        failed_messages: number;
        total_conversations?: number;
    };
    filters: {
        user_id?: string;
        phone_number_id?: string;
        destination_number?: string;
        sender?: string;
        search?: string;
        status?: string;
        peer?: string;
    };
    statuses: {
        value: string;
        label: string;
        badgeClass: string;
    }[];
}>();

// Primary View Mode: 'chat' (default) or 'table'
const viewMode = ref<'chat' | 'table'>('chat');

// Conversations state
const conversationsList = ref<AdminConversationItem[]>([...(props.conversations || [])]);
const selectedConv = ref<AdminConversationItem | null>(
    props.selectedConversation || (props.conversations && props.conversations.length > 0 ? props.conversations[0] : null)
);
const chatMessages = ref<ChatMessage[]>([...(props.activeMessages || [])]);
const isLoadingChat = ref(false);
const chatSearch = ref('');
const messagesContainerRef = ref<HTMLElement | null>(null);

// Admin Compose / Send in Chat
const adminMessageBody = ref('');
const isSendingAdminSms = ref(false);
const adminSendError = ref<string | null>(null);
const adminSendSuccess = ref<string | null>(null);

// Filter states for Table / General
const search = ref(props.filters.search || '');
const selectedLineId = ref(props.filters.phone_number_id || '');
const destinationNumber = ref(props.filters.destination_number || '');
const selectedStatus = ref(props.filters.status || '');

// Polling state
const isPollingActive = ref(true);
const isPollingInProgress = ref(false);
let pollingTimer: any = null;

// Dynamic raw messages list
const liveMessages = ref<AdminMessageItem[]>([...props.messages.data]);
const totalCount = ref(props.stats.total_messages);
const todayCount = ref(props.stats.today_messages);
const highestMessageId = ref<number>(
    props.messages.data.length > 0
        ? Math.max(...props.messages.data.map(m => m.id))
        : 0
);

// Modals
const activeMessage = ref<AdminMessageItem | null>(null);
const isDetailOpen = ref(false);
const copiedField = ref<string | null>(null);

const messageToDelete = ref<{ id: number; body?: string } | null>(null);
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);

// Filtered conversations based on local chat search
const filteredConversations = computed(() => {
    if (!chatSearch.value.trim()) return conversationsList.value;
    const q = chatSearch.value.trim().toLowerCase();
    return conversationsList.value.filter(c => {
        return (
            c.line_a.number.includes(q) ||
            c.peer_number.includes(q) ||
            c.line_a.effective_title.toLowerCase().includes(q) ||
            c.line_b.effective_title.toLowerCase().includes(q) ||
            (c.line_a.user?.name && c.line_a.user.name.toLowerCase().includes(q)) ||
            (c.line_a.user?.username && c.line_a.user.username.toLowerCase().includes(q)) ||
            (c.line_b.user?.name && c.line_b.user.name.toLowerCase().includes(q)) ||
            (c.line_b.user?.username && c.line_b.user.username.toLowerCase().includes(q)) ||
            c.latest_message.body.toLowerCase().includes(q)
        );
    });
});

const scrollToBottom = async () => {
    await nextTick();
    if (messagesContainerRef.value) {
        messagesContainerRef.value.scrollTop = messagesContainerRef.value.scrollHeight;
    }
};

const selectConversation = async (conv: AdminConversationItem) => {
    selectedConv.value = conv;
    isLoadingChat.value = true;
    adminSendError.value = null;
    adminSendSuccess.value = null;

    try {
        const params = new URLSearchParams();
        params.append('phone_number_id', String(conv.phone_number_id));
        params.append('peer_number', conv.peer_number);

        const res = await fetch(`${route('admin.messages.conversation')}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (res.ok) {
            const data = await res.json();
            if (data.success && Array.isArray(data.messages)) {
                chatMessages.value = data.messages;
                scrollToBottom();
            }
        }
    } catch {
        // tolerate
    } finally {
        isLoadingChat.value = false;
    }
};

const sendAdminMessage = async () => {
    if (!selectedConv.value || !adminMessageBody.value.trim() || isSendingAdminSms.value) return;

    isSendingAdminSms.value = true;
    adminSendError.value = null;
    adminSendSuccess.value = null;

    try {
        const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
        const res = await fetch(route('admin.messages.send'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                from_number_id: selectedConv.value.line_a.id,
                destinations: [selectedConv.value.peer_number],
                body: adminMessageBody.value.trim(),
            }),
        });

        const data = await res.json();
        if (res.ok && data.success) {
            adminMessageBody.value = '';
            adminSendSuccess.value = 'پیامک با موفقیت از طرف خط فرستاده شد.';
            setTimeout(() => { adminSendSuccess.value = null; }, 3000);
            await selectConversation(selectedConv.value);
        } else {
            adminSendError.value = data.message || 'خطا در ارسال پیامک مدیریت';
        }
    } catch (e: any) {
        adminSendError.value = e.message || 'خطا در برقراری ارتباط با سرور';
    } finally {
        isSendingAdminSms.value = false;
    }
};

const pollAdminMessages = async () => {
    if (!isPollingActive.value || isPollingInProgress.value) return;
    if (document.hidden) return;

    isPollingInProgress.value = true;
    try {
        const params = new URLSearchParams();
        params.append('after_id', String(highestMessageId.value));
        if (selectedConv.value) {
            params.append('active_phone_id', String(selectedConv.value.phone_number_id));
            params.append('active_peer', selectedConv.value.peer_number);
            const highestChatId = chatMessages.value.length > 0
                ? Math.max(...chatMessages.value.map(m => m.id))
                : 0;
            params.append('active_after_id', String(highestChatId));
        }

        const res = await fetch(`${route('admin.messages.poll')}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (res.ok) {
            const result = await res.json();
            if (result.success) {
                if (Array.isArray(result.data) && result.data.length > 0) {
                    const incoming = result.data.map((item: any) => ({ ...item, isNew: true }));
                    for (const item of incoming) {
                        if (item.id > highestMessageId.value) {
                            highestMessageId.value = item.id;
                        }
                    }
                    liveMessages.value = [...incoming, ...liveMessages.value];
                    totalCount.value += incoming.length;
                    todayCount.value += incoming.length;

                    setTimeout(() => {
                        for (const item of incoming) {
                            item.isNew = false;
                        }
                    }, 4000);
                }

                if (Array.isArray(result.active_messages) && result.active_messages.length > 0) {
                    const existingIds = new Set(chatMessages.value.map(m => m.id));
                    const newChatMsgs = result.active_messages.filter((m: ChatMessage) => !existingIds.has(m.id));
                    if (newChatMsgs.length > 0) {
                        chatMessages.value = [...chatMessages.value, ...newChatMsgs];
                        scrollToBottom();
                    }
                }
            }
        }
    } catch {
        // tolerate
    } finally {
        isPollingInProgress.value = false;
    }
};

const applyGlobalFilters = () => {
    router.get(
        route('admin.messages.index'),
        {
            search: search.value || undefined,
            phone_number_id: selectedLineId.value || undefined,
            destination_number: destinationNumber.value || undefined,
            status: selectedStatus.value || undefined,
        },
        {
            preserveState: true,
            replace: true,
        }
    );
};

const resetGlobalFilters = () => {
    search.value = '';
    selectedLineId.value = '';
    destinationNumber.value = '';
    selectedStatus.value = '';
    router.get(route('admin.messages.index'), {}, { preserveState: true, replace: true });
};

const confirmDeleteMessage = (msg: { id: number; body?: string }) => {
    messageToDelete.value = msg;
    isDeleteModalOpen.value = true;
};

const closeDeleteModal = () => {
    isDeleteModalOpen.value = false;
    messageToDelete.value = null;
};

const executeDelete = () => {
    if (!messageToDelete.value) return;
    isDeleting.value = true;
    router.delete(route('admin.messages.destroy', messageToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            const delId = messageToDelete.value?.id;
            liveMessages.value = liveMessages.value.filter(m => m.id !== delId);
            chatMessages.value = chatMessages.value.filter(m => m.id !== delId);
            totalCount.value = Math.max(0, totalCount.value - 1);
            closeDeleteModal();
            isDeleting.value = false;
        },
        onError: () => {
            isDeleting.value = false;
        },
    });
};

const openDetail = (message: AdminMessageItem) => {
    activeMessage.value = message;
    isDetailOpen.value = true;
};

const closeDetail = () => {
    isDetailOpen.value = false;
    activeMessage.value = null;
    copiedField.value = null;
};

const copyToClipboard = async (text: string, fieldName: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedField.value = fieldName;
        setTimeout(() => {
            if (copiedField.value === fieldName) {
                copiedField.value = null;
            }
        }, 2500);
    } catch {
        // tolerate
    }
};

const formatDate = (isoString: string | null) => {
    if (!isoString) return '—';
    try {
        const d = new Date(isoString);
        return new Intl.DateTimeFormat('fa-IR', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }).format(d);
    } catch {
        return isoString;
    }
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isDetailOpen.value) closeDetail();
        if (isDeleteModalOpen.value) closeDeleteModal();
    }
};

onMounted(() => {
    pollingTimer = setInterval(pollAdminMessages, 4000);
    window.addEventListener('keydown', handleKeydown);
    scrollToBottom();
});

onUnmounted(() => {
    if (pollingTimer) clearInterval(pollingTimer);
    window.removeEventListener('keydown', handleKeydown);
});

watch(() => props.conversations, (newConvs) => {
    conversationsList.value = [...(newConvs || [])];
    if (newConvs && newConvs.length > 0 && !selectedConv.value) {
        selectConversation(newConvs[0]);
    }
});

watch(() => props.messages, (newMessages) => {
    liveMessages.value = [...newMessages.data];
    highestMessageId.value = newMessages.data.length > 0
        ? Math.max(...newMessages.data.map(m => m.id))
        : 0;
});
</script>

<template>
    <Head title="نظارت بر پیام‌ها و مکالمات سامانه" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header Section with Mode Switcher & Stats -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h1 class="text-xl font-bold text-slate-900 dark:text-white">مانیتورینگ و نظارت بر مکالمات و پیام‌های سامانه</h1>
                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                    نمای گفتگو محور
                                </span>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                بازرسی تعاملی چت‌ها و پیامک‌های ردوبدل شده بین خطوط کاربران و مخاطبان خارجی
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Right Side: View Mode Switcher & Live Polling Status -->
                <div class="flex flex-wrap items-center gap-3">
                    <!-- View Mode Toggle -->
                    <div class="inline-flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/80 dark:border-slate-700/80">
                        <button
                            type="button"
                            @click="viewMode = 'chat'"
                            :class="[
                                'flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all',
                                viewMode === 'chat'
                                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                    : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
                            ]"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                            </svg>
                            <span>نمای چت و مکالمات</span>
                        </button>
                        <button
                            type="button"
                            @click="viewMode = 'table'"
                            :class="[
                                'flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all',
                                viewMode === 'table'
                                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                    : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'
                            ]"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 4h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span>جدول لاگ‌های خام</span>
                        </button>
                    </div>

                    <!-- Silent Live Polling Indicator -->
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium border bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800/60">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        <span>بروزرسانی زنده (۴ ثانیه)</span>
                    </div>
                </div>
            </div>

            <!-- Stats Metric Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">تعداد کل گفتگوهای فعال</p>
                        <p class="text-xl font-bold text-indigo-600 dark:text-indigo-400 mt-1">{{ (stats.total_conversations || conversationsList.length).toLocaleString('fa-IR') }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">کل پیام‌های سامانه</p>
                        <p class="text-xl font-bold text-slate-900 dark:text-white mt-1">{{ totalCount.toLocaleString('fa-IR') }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">پیام‌های واصله امروز</p>
                        <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ todayCount.toLocaleString('fa-IR') }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">پیام‌های وضعیت خطا</p>
                        <p class="text-xl font-bold text-rose-600 dark:text-rose-400 mt-1">{{ stats.failed_messages.toLocaleString('fa-IR') }}</p>
                    </div>
                    <div class="w-10 h-10 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Platform Messaging Notice (Requirement 7.5) -->
            <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/25 flex items-center gap-3 text-xs text-amber-700 dark:text-amber-300">
                <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>
                    <strong>اطلاعیه عملکرد پلتفرم:</strong>
                    کلیه پیام‌های مبادله‌شده به صورت درون‌سامانه‌ای ثبت و پردازش می‌شوند و ارسال مخابراتی پیامک به اپراتورهای تلفن همراه انجام نمی‌گیرد.
                </span>
            </div>

            <!-- Global Line & Search Filter Bar -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200/80 dark:border-slate-800">
                <form @submit.prevent="applyGlobalFilters" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <!-- Line Selector -->
                    <div class="sm:col-span-4">
                        <select
                            v-model="selectedLineId"
                            @change="applyGlobalFilters"
                            class="form-select w-full text-xs rounded-xl px-3 py-2.5 font-medium"
                        >
                            <option value="">همه خطوط سامانه (نمایش کلیه مکالمات)</option>
                            <option
                                v-for="num in (phoneNumbers || [])"
                                :key="num.id"
                                :value="num.id"
                            >
                                {{ num.formatted }} — {{ num.title }} ({{ num.user ? num.user.name + ' - @' + num.user.username : 'بدون کاربر' }})
                            </option>
                        </select>
                    </div>

                    <!-- Search Input -->
                    <div class="sm:col-span-5 relative">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="جستجو در متن پیامک، نام کاربر، نام کاربری یا شماره..."
                            class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white px-3 py-2.5 focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>

                    <!-- Status Filter (for table) -->
                    <div class="sm:col-span-2">
                        <select
                            v-model="selectedStatus"
                            @change="applyGlobalFilters"
                            class="form-select w-full text-xs rounded-xl px-3 py-2.5"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option
                                v-for="st in statuses"
                                :key="st.value"
                                :value="st.value"
                            >
                                {{ st.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Actions -->
                    <div class="sm:col-span-1 flex gap-2">
                        <button
                            type="submit"
                            title="اعمال فیلتر"
                            class="w-full flex items-center justify-center p-2.5 text-xs font-semibold rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 transition-colors shadow-xs"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            @click="resetGlobalFilters"
                            title="پاکسازی فیلترها"
                            class="flex items-center justify-center p-2.5 text-xs rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ============================================================ -->
            <!-- VIEW MODE 1: CHAT & CONVERSATIONS INSPECTOR (DEFAULT)        -->
            <!-- ============================================================ -->
            <div v-if="viewMode === 'chat'" class="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[640px]">
                <!-- RIGHT COLUMN: Conversations List -->
                <div class="lg:col-span-5 flex flex-col bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 overflow-hidden shadow-xs">
                    <!-- Conversations List Header -->
                    <div class="p-4 border-b border-slate-200/80 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <h2 class="text-sm font-bold text-slate-900 dark:text-white">مکالمات و چت‌های فعال سامانه</h2>
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300">
                                    {{ filteredConversations.length }} گفتگو
                                </span>
                            </div>
                        </div>

                        <!-- Quick filter inside conversations -->
                        <div class="relative">
                            <input
                                v-model="chatSearch"
                                type="text"
                                placeholder="فیلتر سریع در شماره‌ها، کاربران یا پیام‌ها..."
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white px-3 py-2 pr-8 focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <svg class="w-4 h-4 text-slate-400 absolute right-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Conversation Items Stream -->
                    <div class="flex-1 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800/60 max-h-[620px]">
                        <div v-if="filteredConversations.length === 0" class="p-12 text-center text-slate-400 dark:text-slate-500">
                            <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <p class="text-xs font-semibold">هیچ گفتگویی منطبق با جستجو یافت نشد</p>
                        </div>

                        <button
                            v-for="conv in filteredConversations"
                            :key="conv.id"
                            type="button"
                            @click="selectConversation(conv)"
                            :class="[
                                'w-full text-right p-4 transition-all duration-200 flex flex-col gap-2 hover:bg-slate-50 dark:hover:bg-slate-800/50',
                                selectedConv?.id === conv.id
                                    ? 'bg-indigo-50/70 dark:bg-indigo-950/30 border-r-4 border-indigo-600 dark:border-indigo-500'
                                    : ''
                            ]"
                        >
                            <!-- Parties Exchange Header -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <!-- Party A -->
                                    <div class="flex flex-col items-start">
                                        <span class="text-xs font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                            {{ conv.line_a.formatted }}
                                        </span>
                                        <span class="text-[10px] text-slate-500 dark:text-slate-400 truncate max-w-[110px]">
                                            {{ conv.line_a.user ? conv.line_a.user.name : conv.line_a.effective_title }}
                                        </span>
                                    </div>

                                    <!-- Exchange Icon -->
                                    <div class="w-6 h-6 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center shrink-0">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                        </svg>
                                    </div>

                                    <!-- Party B -->
                                    <div class="flex flex-col items-start">
                                        <span class="text-xs font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                            {{ conv.line_b.formatted }}
                                        </span>
                                        <span class="text-[10px] text-slate-500 dark:text-slate-400 truncate max-w-[110px]">
                                            {{ conv.line_b.user ? conv.line_b.user.name : conv.line_b.effective_title }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Time & Badge -->
                                <div class="flex flex-col items-end gap-1 shrink-0">
                                    <span class="text-[10px] text-slate-400">
                                        {{ conv.latest_message.server_received_at_formatted }}
                                    </span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                        {{ conv.messages_count }} پیام
                                    </span>
                                </div>
                            </div>

                            <!-- Last Message Direction & Snippet -->
                            <div class="bg-white/60 dark:bg-slate-800/60 p-2 rounded-xl text-xs border border-slate-100 dark:border-slate-800 text-slate-700 dark:text-slate-300">
                                <div class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">
                                    <span class="text-indigo-600 dark:text-indigo-400 font-bold">
                                        {{ conv.latest_message.sender_name }}
                                    </span>
                                    <span>➔</span>
                                    <span>{{ conv.latest_message.receiver_name }}</span>
                                    <span class="text-[10px] opacity-75">({{ conv.latest_message.direction_label }})</span>
                                </div>
                                <p class="text-xs text-slate-800 dark:text-slate-200 line-clamp-2 leading-relaxed">
                                    «{{ conv.latest_message.body }}»
                                </p>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- LEFT COLUMN: Chat Stream & Message Inspector -->
                <div class="lg:col-span-7 flex flex-col bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 overflow-hidden shadow-xs">
                    <!-- If no conversation selected -->
                    <div v-if="!selectedConv" class="flex-1 flex flex-col items-center justify-center p-12 text-center text-slate-400">
                        <div class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-500 flex items-center justify-center mb-4">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">یک گفتگو را از لیست انتخاب کنید</h3>
                        <p class="text-xs text-slate-500 mt-1 max-w-sm">
                            با انتخاب هر یک از گفتگوهای ستون راست، تبادل کامل پیامک‌ها بین دو طرف به صورت حباب‌های چت نمایش داده می‌شود.
                        </p>
                    </div>

                    <!-- Selected Conversation Header Card -->
                    <div v-else class="flex flex-col flex-1">
                        <!-- Active Conversation Header -->
                        <div class="p-4 border-b border-slate-200/80 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/40 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <!-- Two Parties Summary -->
                            <div class="flex items-center gap-3">
                                <!-- Party A Card -->
                                <div class="bg-white dark:bg-slate-900 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-800 shadow-2xs">
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                                        <span class="text-xs font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">
                                            {{ selectedConv.line_a.formatted }}
                                        </span>
                                    </div>
                                    <div class="text-[11px] font-bold text-slate-900 dark:text-white mt-0.5">
                                        {{ selectedConv.line_a.user ? selectedConv.line_a.user.name : selectedConv.line_a.effective_title }}
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-mono" dir="ltr" v-if="selectedConv.line_a.user">
                                        @{{ selectedConv.line_a.user.username }}
                                    </div>
                                </div>

                                <!-- Center Exchange Indicator -->
                                <div class="flex flex-col items-center">
                                    <div class="w-7 h-7 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-200 dark:border-indigo-800">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                        </svg>
                                    </div>
                                    <span class="text-[9px] text-slate-400 mt-0.5">تبادل مستقیم</span>
                                </div>

                                <!-- Party B Card -->
                                <div class="bg-white dark:bg-slate-900 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-800 shadow-2xs">
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full" :class="selectedConv.line_b.is_internal ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                                        <span class="text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400" dir="ltr">
                                            {{ selectedConv.line_b.formatted }}
                                        </span>
                                    </div>
                                    <div class="text-[11px] font-bold text-slate-900 dark:text-white mt-0.5">
                                        {{ selectedConv.line_b.user ? selectedConv.line_b.user.name : selectedConv.line_b.effective_title }}
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-mono" dir="ltr" v-if="selectedConv.line_b.user">
                                        @{{ selectedConv.line_b.user.username }}
                                    </div>
                                </div>
                            </div>

                            <!-- Refresh & Info -->
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="selectConversation(selectedConv)"
                                    title="بارگذاری مجدد پیام‌های این گفتگو"
                                    class="p-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 transition-colors"
                                >
                                    <svg class="w-4 h-4" :class="isLoadingChat ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Chat Messages Container -->
                        <div
                            ref="messagesContainerRef"
                            class="flex-1 p-5 overflow-y-auto space-y-4 max-h-[500px] bg-slate-50/50 dark:bg-slate-950/40"
                        >
                            <div v-if="isLoadingChat" class="py-12 text-center text-slate-400">
                                <svg class="w-8 h-8 mx-auto animate-spin text-indigo-500 mb-2" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <p class="text-xs">در حال بارگذاری پیام‌های گفتگو...</p>
                            </div>

                            <div v-else-if="chatMessages.length === 0" class="py-12 text-center text-slate-400">
                                <p class="text-xs">هیچ پیامی در این گفتگو ثبت نشده است.</p>
                            </div>

                            <!-- Bubbles Stream -->
                            <div
                                v-for="msg in chatMessages"
                                :key="msg.id"
                                :class="[
                                    'flex flex-col',
                                    msg.is_line_a ? 'items-end' : 'items-start'
                                ]"
                            >
                                <!-- Clear Direction Badge: Who sent to Whom -->
                                <div class="flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400 mb-1 px-2">
                                    <span class="font-bold text-slate-900 dark:text-white">{{ msg.sender.name }}</span>
                                    <span class="font-mono" dir="ltr">({{ msg.sender.formatted }})</span>
                                    <span class="text-indigo-500 font-bold">➔</span>
                                    <span class="font-bold text-slate-900 dark:text-white">{{ msg.receiver.name }}</span>
                                    <span class="font-mono" dir="ltr">({{ msg.receiver.formatted }})</span>
                                </div>

                                <!-- Bubble Body -->
                                <div
                                    :class="[
                                        'max-w-[85%] sm:max-w-[75%] rounded-2xl p-4 shadow-xs relative group transition-all',
                                        msg.is_line_a
                                            ? 'bg-indigo-600 text-white rounded-br-xs'
                                            : 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 rounded-bl-xs'
                                    ]"
                                >
                                    <!-- Message Text -->
                                    <p class="text-xs leading-relaxed whitespace-pre-wrap break-words">
                                        {{ msg.body }}
                                    </p>

                                    <!-- Footer & Metadata -->
                                    <div
                                        :class="[
                                            'flex items-center justify-between gap-3 mt-3 pt-2 text-[10px] border-t',
                                            msg.is_line_a ? 'border-indigo-500/40 text-indigo-100' : 'border-slate-100 dark:border-slate-700/80 text-slate-400'
                                        ]"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono" dir="ltr">#{{ msg.id }}</span>
                                            <span>•</span>
                                            <span>{{ msg.server_received_time }}</span>
                                            <span>({{ msg.server_received_at_formatted }})</span>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <!-- Status -->
                                            <span
                                                :class="[
                                                    'px-2 py-0.5 rounded-full text-[9px] font-bold inline-flex items-center gap-1',
                                                    msg.is_line_a
                                                        ? 'bg-indigo-700/60 text-white'
                                                        : msg.status_badge
                                                ]"
                                            >
                                                <span>{{ msg.status_label }}</span>
                                                <template v-if="msg.is_line_a">
                                                    <span v-if="msg.processing_status === 'processed'" class="text-emerald-300 inline-flex items-center -space-x-1 rtl:space-x-reverse" title="دو تیک سبز - خوانده‌شده">
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    </span>
                                                    <span v-else-if="msg.processing_status === 'received'" class="text-indigo-200 inline-flex items-center -space-x-1 rtl:space-x-reverse" title="دو تیک - تحویل داده شده">
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    </span>
                                                    <span v-else-if="msg.processing_status === 'failed'" class="text-rose-300">
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    </span>
                                                    <span v-else class="text-indigo-200">
                                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    </span>
                                                </template>
                                            </span>

                                            <!-- Delete Button for Admin -->
                                            <button
                                                type="button"
                                                @click="confirmDeleteMessage({ id: msg.id, body: msg.body })"
                                                title="حذف این پیام از سامانه"
                                                :class="[
                                                    'opacity-0 group-hover:opacity-100 p-1 rounded-md transition-opacity',
                                                    msg.is_line_a ? 'hover:bg-indigo-700 text-white' : 'hover:bg-slate-100 dark:hover:bg-slate-700 text-rose-500'
                                                ]"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Admin Send / Action Bar -->
                        <div class="p-3 border-t border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900">
                            <!-- Alert feedback -->
                            <div v-if="adminSendError" class="mb-2 p-2 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 text-xs border border-rose-200 dark:border-rose-800">
                                {{ adminSendError }}
                            </div>
                            <div v-if="adminSendSuccess" class="mb-2 p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 text-xs border border-emerald-200 dark:border-emerald-800">
                                {{ adminSendSuccess }}
                            </div>

                            <form @submit.prevent="sendAdminMessage" class="flex items-center gap-2">
                                <div class="shrink-0 text-slate-400 text-xs font-semibold px-2">
                                    ارسال از طرف <span class="font-mono text-indigo-600 dark:text-indigo-400 font-bold" dir="ltr">{{ selectedConv.line_a.formatted }}</span>:
                                </div>
                                <input
                                    v-model="adminMessageBody"
                                    type="text"
                                    placeholder="متن پیامک ارسالی مدیریت به این مکالمه را بنویسید..."
                                    :disabled="isSendingAdminSms"
                                    class="flex-1 text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white px-3 py-2.5 focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <button
                                    type="submit"
                                    :disabled="isSendingAdminSms || !adminMessageBody.trim()"
                                    class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-semibold flex items-center gap-1.5 transition-colors shadow-xs"
                                >
                                    <svg v-if="isSendingAdminSms" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                    <span>ارسال</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- VIEW MODE 2: RAW LOGS TABLE (AUDIT / TECHNICAL ARCHIVE)     -->
            <!-- ============================================================ -->
            <div v-else class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 overflow-hidden shadow-xs">
                <div v-if="liveMessages.length === 0" class="p-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">هیچ پیامی در بایگانی سامانه یافت نشد</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                        هیچ پیامی منطبق با فیلترهای جستجو وجود ندارد.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-600 dark:text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="py-3.5 px-4">شناسه</th>
                                <th class="py-3.5 px-4">شماره مقصد</th>
                                <th class="py-3.5 px-4">کاربر مقصد</th>
                                <th class="py-3.5 px-4">فرستنده</th>
                                <th class="py-3.5 px-4 min-w-[220px]">متن پیام</th>
                                <th class="py-3.5 px-4">زمان سرور</th>
                                <th class="py-3.5 px-4">وضعیت</th>
                                <th class="py-3.5 px-4 text-center">اقدامات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-slate-700 dark:text-slate-300">
                            <tr
                                v-for="msg in liveMessages"
                                :key="msg.id"
                                :class="[
                                    'transition-colors duration-500 hover:bg-slate-50/80 dark:hover:bg-slate-800/40',
                                    msg.isNew ? 'bg-emerald-50/80 dark:bg-emerald-950/30' : ''
                                ]"
                            >
                                <td class="py-3.5 px-4 font-mono text-slate-500 dark:text-slate-400" dir="ltr">
                                    #{{ msg.id }}
                                </td>

                                <td class="py-3.5 px-4 font-mono font-bold text-indigo-600 dark:text-indigo-400 text-left" dir="ltr">
                                    +85 {{ msg.destination_number }}
                                </td>

                                <td class="py-3.5 px-4">
                                    <div v-if="msg.phoneNumber?.user || msg.user" class="flex flex-col">
                                        <span class="font-bold text-slate-900 dark:text-white">
                                            {{ (msg.phoneNumber?.user || msg.user)?.name }}
                                        </span>
                                        <span class="text-[11px] font-mono text-slate-500" dir="ltr">
                                            @{{ (msg.phoneNumber?.user || msg.user)?.username }}
                                        </span>
                                    </div>
                                    <span v-else class="text-slate-400 font-mono text-[11px]">سیستم / آزاد</span>
                                </td>

                                <td class="py-3.5 px-4 font-mono font-semibold text-slate-800 dark:text-slate-200 text-left" dir="ltr">
                                    {{ msg.sender }}
                                </td>

                                <td class="py-3.5 px-4">
                                    <p class="line-clamp-2 max-w-md font-sans text-slate-800 dark:text-slate-200">
                                        {{ msg.body }}
                                    </p>
                                </td>

                                <td class="py-3.5 px-4 text-slate-500 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span>{{ formatDate(msg.server_received_at) }}</span>
                                        <span class="text-[10px] text-slate-400">{{ msg.server_received_at_formatted }}</span>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-[11px] font-semibold border inline-flex items-center gap-1.5',
                                            msg.status_badge || 'bg-slate-100 text-slate-700 border-slate-200'
                                        ]"
                                    >
                                        {{ msg.status_label || msg.processing_status }}
                                    </span>
                                </td>

                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button
                                            type="button"
                                            @click="openDetail(msg)"
                                            class="p-2 rounded-xl text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-slate-800 transition-colors"
                                            title="مشاهده جزییات فنی پیام"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            @click="confirmDeleteMessage(msg)"
                                            class="p-2 rounded-xl text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800 transition-colors"
                                            title="حذف پیام"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination for Table -->
                <div v-if="messages.links && messages.links.length > 3" class="p-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-xs">
                    <span class="text-slate-500">
                        صفحه {{ messages.current_page }} از {{ messages.last_page }} (مجموع {{ messages.total }} پیام)
                    </span>
                    <div class="flex gap-1">
                        <template v-for="(link, i) in messages.links" :key="i">
                            <button
                                v-if="link.url"
                                type="button"
                                @click="router.get(link.url, {}, { preserveState: true })"
                                :class="[
                                    'px-3 py-1.5 rounded-lg font-medium transition-colors',
                                    link.active
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200'
                                ]"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>

            <!-- Detail Modal -->
            <div v-if="isDetailOpen && activeMessage" @click.self="closeDetail" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs">
                <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-xl w-full p-6 border border-slate-200 dark:border-slate-800 shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3 shrink-0 mb-4">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white">جزییات فنی پیام #{{ activeMessage.id }}</h3>
                        <button type="button" @click="closeDetail" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-3 text-xs overflow-y-auto flex-1">
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl space-y-1">
                            <span class="text-slate-400 text-[11px]">متن کامل پیام:</span>
                            <p class="text-slate-900 dark:text-white leading-relaxed whitespace-pre-wrap">{{ activeMessage.body }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl">
                                <span class="text-slate-400 text-[11px] block">شماره مقصد:</span>
                                <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400 text-sm" dir="ltr">+85 {{ activeMessage.destination_number }}</span>
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl">
                                <span class="text-slate-400 text-[11px] block">فرستنده:</span>
                                <span class="font-mono font-bold text-slate-900 dark:text-white text-sm" dir="ltr">{{ activeMessage.sender }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl">
                                <span class="text-slate-400 text-[11px] block">شناسه پیگیری مشتری (Client ID):</span>
                                <span class="font-mono text-slate-700 dark:text-slate-300" dir="ltr">{{ activeMessage.client_message_id || '—' }}</span>
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl">
                                <span class="text-slate-400 text-[11px] block">سامانه مبدأ (Source System ID):</span>
                                <span class="font-mono text-slate-700 dark:text-slate-300" dir="ltr">{{ activeMessage.source_system_id || '—' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-end shrink-0 mt-4">
                        <button type="button" @click="closeDetail" class="px-5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold cursor-pointer">
                            بستن
                        </button>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div v-if="isDeleteModalOpen && messageToDelete" @click.self="closeDeleteModal" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs">
                <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-md w-full p-6 border border-slate-200 dark:border-slate-800 shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                    <div class="overflow-y-auto flex-1 space-y-4">
                        <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div class="text-center">
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white">آیا از حذف این پیامک اطمینان دارید؟</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                شناسه پیام: <span class="font-mono font-bold" dir="ltr">#{{ messageToDelete.id }}</span>
                            </p>
                            <p v-if="messageToDelete.body" class="text-xs text-slate-700 dark:text-slate-300 mt-2 bg-slate-50 dark:bg-slate-800 p-2.5 rounded-xl line-clamp-2">
                                «{{ messageToDelete.body }}»
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2 pt-4 border-t border-slate-100 dark:border-slate-800 shrink-0 mt-4">
                        <button
                            type="button"
                            @click="executeDelete"
                            :disabled="isDeleting"
                            class="inline-flex items-center justify-center gap-2 flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold transition-colors disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="isDeleting" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ isDeleting ? 'در حال حذف...' : 'بله، حذف شود' }}</span>
                        </button>
                        <button
                            type="button"
                            @click="closeDeleteModal"
                            :disabled="isDeleting"
                            class="flex-1 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
