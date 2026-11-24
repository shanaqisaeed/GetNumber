<script setup lang="ts">
import { __ } from '@/Composables/useTrans';
import { useRealtimeMessages, RealtimeMessagePayload } from '@/Composables/useRealtimeMessages';
import { useWebNotifications } from '@/Composables/useWebNotifications';
import NotificationPermissionModal from '@/Components/NotificationPermissionModal.vue';
import GlobalIncomingCallModal from '@/Components/GlobalIncomingCallModal.vue';
import LineSwitcherModal, { LineOption } from '@/Components/LineSwitcherModal.vue';
import AudioDeviceTestModal from '@/Components/AudioDeviceTestModal.vue';
import PwaInstallBanner from '@/Components/PwaInstallBanner.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import { useTheme } from '@/Composables/useTheme';
import { useSoftphoneSync } from '@/Composables/useSoftphoneSync';
import { PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const props = withDefaults(defineProps<{
    fullWidth?: boolean;
}>(), {
    fullWidth: false,
});

const page = usePage<PageProps & {
    active_line?: LineOption | null;
    lines?: LineOption[];
    presence?: string;
}>();

const user = computed(() => page.props.auth?.user);
const liveUnreadCount = ref<number>(page.props.auth?.unread_messages_count || 0);

// Active Line & Line Switcher State
const isLineSwitcherOpen = ref(false);
const isAudioTestOpen = ref(false);

const currentPresence = ref<'online' | 'busy' | 'dnd' | 'offline'>(((page.props as any).presence as any) || 'online');
watch(() => (page.props as any).presence, (val) => {
    if (val) currentPresence.value = val as any;
});
async function setPresence(val: 'online' | 'busy' | 'dnd' | 'offline') {
    currentPresence.value = val;
    try {
        await axios.post(route('softphone.presence'), { presence: val });
    } catch {}
}

const activeLine = computed<LineOption | null>(() => {
    return (page.props as any).active_line || null;
});

const availableLines = computed<LineOption[]>(() => {
    return (page.props as any).lines || [];
});

// Softphone Cross-Tab Synchronization
const { isCallActiveInOtherTab, initChannel } = useSoftphoneSync();

onMounted(() => {
    initChannel((switchedKey) => {
        router.reload();
    });
});

watch(
    () => page.props.auth?.unread_messages_count,
    (val) => {
        if (typeof val === 'number') {
            liveUnreadCount.value = val;
        }
    }
);

const handleUnreadCountUpdated = (e: any) => {
    if (typeof e.detail?.total === 'number') {
        liveUnreadCount.value = e.detail.total;
    }
};

// Web Notifications & WebSocket Integration
const { showLocalNotification } = useWebNotifications();

interface FloatingToastPayload {
    title: string;
    body: string;
    peer?: string;
    lineId?: number;
}
const toastNotification = ref<FloatingToastPayload | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showIncomingToast = (msg: RealtimeMessagePayload) => {
    const sender = msg.peer_number || msg.sender || __('ui.layouts.user.unknown_sender');
    toastNotification.value = {
        title: __('ui.layouts.user.new_message_from', { sender }),
        body: msg.body?.substring(0, 80) || '',
        peer: sender,
        lineId: msg.phone_number_id,
    };
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastNotification.value = null;
    }, 6000);
};

const { connectionState } = useRealtimeMessages({
    onMessage: (msg) => {
        if (msg.direction === 'inbound' || (!msg.direction && msg.sender)) {
            liveUnreadCount.value += 1;
            const sender = msg.peer_number || msg.sender;
            if (typeof window !== 'undefined' && (window as any).route && !(window as any).route().current('messages.*')) {
                showIncomingToast(msg);
            }
            showLocalNotification(sender, msg.id);
        }
    },
    onUnreadUpdate: (newTotal) => {
        liveUnreadCount.value = newTotal;
    },
});

onMounted(() => {
    window.addEventListener('user-unread-count-updated', handleUnreadCountUpdated);
});

onUnmounted(() => {
    window.removeEventListener('user-unread-count-updated', handleUnreadCountUpdated);
});

const isMoreMenuOpen = ref(false);
const { isDark, toggleTheme } = useTheme();

const navItems = computed(() => [
    {
        name: computed(() => __('softphone.nav.dashboard')),
        route: 'dashboard',
        href: route('dashboard'),
        active: route().current('dashboard'),
        icon: 'home',
    },
    {
        name: computed(() => __('softphone.nav.calls')),
        route: 'calls.*',
        href: route('calls.index'),
        active: route().current('calls.*'),
        icon: 'calls',
    },
    {
        name: computed(() => __('softphone.nav.messages')),
        route: 'messages.*',
        href: route('messages.index'),
        active: route().current('messages.*'),
        icon: 'chat',
        badge: liveUnreadCount.value,
    },
    {
        name: computed(() => __('softphone.nav.contacts')),
        route: 'contacts.*',
        href: route('contacts.index'),
        active: route().current('contacts.*'),
        icon: 'contacts',
    },
]);
</script>

<template>
    <div class="h-screen w-full flex bg-[var(--bg-canvas)] relative transition-colors duration-300 overflow-hidden">
        <!-- Notification Permission Request Modal -->
        <NotificationPermissionModal />

        <!-- Line Switcher Modal -->
        <LineSwitcherModal
            :show="isLineSwitcherOpen"
            :lines="availableLines"
            :active-line-key="activeLine?.key"
            @close="isLineSwitcherOpen = false"
            @switched="isLineSwitcherOpen = false"
        />

        <!-- Audio Device & Mic Test Modal -->
        <AudioDeviceTestModal
            :show="isAudioTestOpen"
            @close="isAudioTestOpen = false"
        />

        <!-- Multi-tab Synchronization Active Call Warning Banner -->
        <div
            v-if="isCallActiveInOtherTab"
            class="w-full bg-amber-500 text-slate-900 py-2 px-4 text-xs font-black flex items-center justify-center gap-2 z-50 fixed top-0 left-0 right-0 shadow-md"
        >
            <span class="w-2.5 h-2.5 rounded-full bg-slate-900 animate-ping"></span>
            <span>{{ __('softphone.multi_tab.active_elsewhere') }}</span>
            <span class="text-[11px] font-normal">({{ __('softphone.multi_tab.tab_busy_notice') }})</span>
        </div>

        <!-- Floating Live Message Toast -->
        <transition enter-active-class="transition ease-out duration-300" enter-from-class="-translate-y-8 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition ease-in duration-200" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-8 opacity-0">
            <div v-if="toastNotification" class="fixed top-4 left-1/2 -translate-x-1/2 z-50 w-[92%] max-w-[420px] p-3 rounded-2xl border border-indigo-500/40 shadow-2xl flex items-center justify-between gap-3 backdrop-blur-xl bg-slate-900/90 text-white">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-black truncate">{{ toastNotification.title }}</p>
                        <p class="text-[11px] text-slate-300 truncate">{{ toastNotification.body }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <Link
                        v-if="toastNotification.peer"
                        :href="route('messages.index', { phone_number_id: toastNotification.lineId, peer: toastNotification.peer })"
                        class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-[11px] font-bold text-white transition-all shadow-sm"
                        @click="toastNotification = null"
                    >
                        {{ __('ui.layouts.user.view_action') }}
                    </Link>
                    <button @click="toastNotification = null" class="p-1 text-slate-400 hover:text-white text-xs">✕</button>
                </div>
            </div>
        </transition>

        <!-- Global Centered Incoming Call Modal across all pages -->
        <GlobalIncomingCallModal />

        <!-- Ambient Background Mesh -->
        <div class="ambient-mesh">
            <div class="liquid-orb orb-1"></div>
            <div class="liquid-orb orb-2"></div>
            <div class="liquid-orb orb-3"></div>
        </div>

        <!-- ============================================================= -->
        <!-- 1. DESKTOP PROFESSIONAL SOFTPHONE SIDEBAR (md:flex)           -->
        <!-- ============================================================= -->
        <aside class="hidden md:flex flex-col w-64 lg:w-72 shrink-0 border-l border-slate-200/80 dark:border-white/10 bg-white/80 dark:bg-slate-950/80 backdrop-blur-2xl z-30 h-screen sticky top-0 overflow-y-auto p-3.5 space-y-3.5 shadow-xl shadow-slate-900/5">
            <!-- Brand & Softphone Header with Language Switcher & Theme Toggle integrated -->
            <div class="p-3 rounded-2xl bg-slate-100/80 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-md shadow-indigo-600/30 shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <span class="text-sm font-black text-slate-900 dark:text-white tracking-wide truncate">{{ __('ui.layouts.user.nav.brand_name') }}</span>
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-md bg-indigo-500/20 text-indigo-500 dark:text-indigo-400 border border-indigo-500/30">PRO</span>
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="truncate">{{ __('softphone.dialer.title') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Controls Capsule: Language Switcher & Theme Toggle integrated seamlessly -->
                <div class="flex items-center gap-1 p-0.5 rounded-xl bg-white/90 dark:bg-slate-900/90 border border-slate-200 dark:border-white/10 shadow-xs shrink-0">
                    <LanguageSwitcher buttonClass="!h-7 !rounded-lg !px-1.5 !text-[11px]" />
                    <div class="w-px h-3 bg-slate-200 dark:bg-white/10" aria-hidden="true"></div>
                    <button
                        type="button"
                        @click="toggleTheme"
                        class="app-theme-toggle-btn !w-7 !h-7 !rounded-lg flex items-center justify-center hover:bg-slate-100 dark:hover:bg-white/5 transition-colors"
                        :title="isDark ? __('ui.layouts.user.theme_light') : __('ui.layouts.user.theme_dark')"
                    >
                        <svg v-if="isDark" class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Active Line Control & Presence Card in Sidebar -->
            <div class="p-3 rounded-2xl bg-indigo-50/70 dark:bg-white/5 border border-indigo-200/80 dark:border-white/10 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider">{{ __('softphone.line.active_line') }}</span>
                    <button
                        type="button"
                        @click="isLineSwitcherOpen = true"
                        class="text-[10px] text-indigo-600 dark:text-indigo-400 hover:underline font-bold flex items-center gap-1"
                    >
                        <span>{{ __('softphone.line.switch_line') }}</span>
                        <span>⇄</span>
                    </button>
                </div>

                <div v-if="activeLine" class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></div>
                    <div class="min-w-0 flex-1">
                        <div class="font-mono font-black text-xs text-slate-900 dark:text-white truncate" dir="ltr">
                            {{ activeLine.formatted_number || activeLine.number }}
                        </div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate">
                            {{ activeLine.display_title }}
                        </div>
                    </div>
                </div>
                <div v-else-if="availableLines.length > 0">
                    <button
                        type="button"
                        @click="isLineSwitcherOpen = true"
                        class="text-xs text-amber-500 font-bold hover:underline"
                    >
                        {{ __('softphone.line.select_line_prompt') }}
                    </button>
                </div>
                <div v-else class="text-[11px] text-slate-500 dark:text-slate-400">
                    {{ __('softphone.line.no_active_line') }}
                </div>

                <!-- Softphone Presence Selector (در دسترس / مشغول / مزاحم نشوید / آفلاین) -->
                <div class="pt-2 border-t border-slate-200/60 dark:border-white/5 flex items-center justify-between gap-2">
                    <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400">{{ __('softphone.presence.status') }}:</span>
                    <select
                        :value="currentPresence"
                        @change="setPresence(($event.target as HTMLSelectElement).value as any)"
                        class="form-select !py-1 !px-2 !text-[10px] font-bold rounded-xl bg-white dark:bg-slate-900 border-slate-200 dark:border-white/10 text-slate-800 dark:text-slate-200"
                    >
                        <option value="online">🟢 {{ __('softphone.presence.online') }}</option>
                        <option value="busy">🟡 {{ __('softphone.presence.busy') }}</option>
                        <option value="dnd">🔴 {{ __('softphone.presence.dnd') }}</option>
                        <option value="offline">⚪ {{ __('softphone.presence.offline') }}</option>
                    </select>
                </div>
            </div>

            <!-- Primary Navigation Links -->
            <nav class="space-y-1">
                <Link
                    v-for="item in navItems"
                    :key="typeof item.name === 'object' && 'value' in item.name ? item.name.value : item.name"
                    :href="item.href"
                    class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-bold transition-all border"
                    :class="[
                        item.active
                            ? 'bg-indigo-600 text-white border-indigo-500 shadow-md shadow-indigo-600/30'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/5 border-transparent'
                    ]"
                >
                    <div class="flex items-center gap-3">
                        <svg v-if="item.icon === 'home'" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                        <svg v-else-if="item.icon === 'calls'" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <svg v-else-if="item.icon === 'chat'" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <svg v-else-if="item.icon === 'contacts'" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <svg v-else-if="item.icon === 'video'" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>

                        <span>{{ typeof item.name === 'object' && 'value' in item.name ? item.name.value : item.name }}</span>
                    </div>

                    <span v-if="item.badge && item.badge > 0" class="px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-black">
                        {{ item.badge }}
                    </span>
                </Link>
            </nav>

            <!-- Utility Navigation: Wallet, Tickets, Audio Test -->
            <div class="pt-2 border-t border-slate-200/80 dark:border-white/10 space-y-1">
                <!-- Wallet -->
                <Link
                    :href="route('wallet.index')"
                    class="flex items-center justify-between px-3.5 py-2.5 rounded-2xl text-xs font-bold transition-all"
                    :class="route().current('wallet.*')
                        ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/5'"
                >
                    <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <rect x="2" y="5" width="20" height="14" rx="2" stroke-width="2" />
                            <line x1="2" y1="10" x2="22" y2="10" stroke-width="2" />
                        </svg>
                        <span>{{ __('softphone.nav.quick_wallet') }}</span>
                    </div>
                    <span class="font-mono text-[11px] text-amber-500 font-black">
                        {{ $page.props.auth?.wallet_balance || '0' }} COIN
                    </span>
                </Link>

                <!-- Tickets -->
                <Link
                    :href="route('tickets.index')"
                    class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl text-xs font-bold transition-all"
                    :class="route().current('tickets.*')
                        ? 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/30'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/5'"
                >
                    <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span>{{ __('softphone.nav.tickets') }}</span>
                </Link>

                <!-- Audio Device Test -->
                <button
                    type="button"
                    @click="isAudioTestOpen = true"
                    class="w-full flex items-center gap-3 px-3.5 py-2.5 rounded-2xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/5 transition-all text-right"
                >
                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                    </svg>
                    <span>{{ __('softphone.devices.title') }}</span>
                </button>
            </div>

            <!-- Admin Dashboard Link (For Admins & Superadmins) -->
            <div v-if="$page.props.auth?.user?.is_admin" class="pt-1">
                <Link
                    :href="route('admin.dashboard')"
                    class="w-full py-2.5 px-3 rounded-2xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 border border-amber-500/30 text-xs font-bold flex items-center justify-between gap-2 transition-all"
                >
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span>{{ __('ui.layouts.user.nav.switch_to_admin') }}</span>
                    </div>
                    <span class="text-[10px]">⚙️</span>
                </Link>
            </div>

            <!-- Corporate Switcher Button -->
            <div class="pt-1">
                <Link
                    :href="route('org.dashboard')"
                    class="w-full py-2.5 px-3 rounded-2xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-xs font-black shadow-md shadow-indigo-600/20 flex items-center justify-between gap-2 transition-all active:scale-95"
                >
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <span>{{ __('softphone.nav.switch_to_org') }}</span>
                    </div>
                    <span class="text-[10px]">→</span>
                </Link>
            </div>

            <!-- User Profile & Settings Area at Bottom -->
            <div class="mt-auto pt-3 border-t border-slate-200/80 dark:border-white/10 space-y-2">
                <div class="p-2.5 rounded-2xl bg-slate-100/80 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xs font-black shrink-0">
                            {{ user?.name ? user.name.charAt(0) : 'U' }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-black text-slate-900 dark:text-white truncate">{{ user?.name || __('ui.layouts.user.default_user_name') }}</p>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 truncate">{{ user?.email }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <Link
                            :href="route('profile.edit')"
                            class="p-1.5 rounded-lg text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200 dark:hover:bg-white/10 transition-all"
                            :title="__('softphone.nav.profile')"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="p-1.5 rounded-lg text-rose-500 hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-500/10 transition-all"
                            :title="__('softphone.nav.logout')"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>
        </aside>

        <!-- ============================================================= -->
        <!-- 2. MAIN APP CANVAS (Right/Center on Desktop)                  -->
        <!-- ============================================================= -->
        <div class="flex-1 flex flex-col min-w-0 h-screen overflow-y-auto relative z-10">
            <!-- Mobile Top Header Bar (md:hidden) -->
            <header class="md:hidden sticky top-0 z-30 pt-3 pb-2.5 px-4 flex items-center justify-between border-b border-slate-200/80 dark:border-white/10 backdrop-blur-xl bg-[var(--bg-canvas)]/85 transition-colors gap-3">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-indigo-600 to-purple-600 flex items-center justify-center text-white font-black text-xs shadow-md shrink-0">
                        {{ user?.name ? user.name.charAt(0) : 'U' }}
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-black text-[var(--text-primary)] block truncate">
                            {{ user?.name || __('ui.layouts.user.default_user_name') }}
                        </span>
                        <!-- Mobile Active Line Chip -->
                        <button
                            v-if="activeLine"
                            type="button"
                            @click="isLineSwitcherOpen = true"
                            class="inline-flex items-center gap-1 text-[10px] text-indigo-500 font-bold font-mono"
                        >
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <span dir="ltr">{{ activeLine.formatted_number || activeLine.number }}</span>
                            <span class="text-[8px] opacity-70">▼</span>
                        </button>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <!-- Audio Test Mobile -->
                    <button
                        type="button"
                        @click="isAudioTestOpen = true"
                        class="w-8 h-8 rounded-xl glass-card !p-0 flex items-center justify-center text-[var(--text-secondary)]"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                        </svg>
                    </button>

                    <!-- Language Switcher Mobile -->
                    <LanguageSwitcher buttonClass="!h-8 !rounded-xl !px-1.5 !text-xs" />

                    <!-- Theme Toggle Mobile -->
                    <button
                        type="button"
                        @click="toggleTheme"
                        class="app-theme-toggle-btn !w-8 !h-8 !rounded-xl"
                    >
                        <svg v-if="isDark" class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>

                    <!-- Mobile Drawer Button -->
                    <button
                        type="button"
                        @click="isMoreMenuOpen = !isMoreMenuOpen"
                        class="w-8 h-8 rounded-xl glass-card !p-0 flex items-center justify-center text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-all"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Main Content Area with Adaptive Padding -->
            <main :class="['flex-1 p-2 sm:p-4 lg:p-6 w-full mx-auto transition-all duration-300', props.fullWidth ? 'max-w-full' : 'max-w-7xl']">
                <!-- PWA Install & Offline Intranet Banner -->
                <PwaInstallBanner />

                <!-- Flash Messages -->
                <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                    <div v-if="$page.props.flash?.success" class="mb-3.5 p-3 rounded-2xl bg-emerald-500/15 border border-emerald-500/35 text-emerald-600 dark:text-emerald-300 text-xs font-bold flex items-center gap-2">
                        <span>✓</span>
                        <span class="flex-1">{{ $page.props.flash.success }}</span>
                    </div>
                </transition>

                <transition enter-active-class="transition ease-out duration-200" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
                    <div v-if="$page.props.flash?.error" class="mb-3.5 p-3 rounded-2xl bg-rose-500/15 border border-rose-500/35 text-rose-600 dark:text-rose-300 text-xs font-bold flex items-center gap-2">
                        <span>✕</span>
                        <span class="flex-1">{{ $page.props.flash.error }}</span>
                    </div>
                </transition>

                <slot />
                <div class="h-16 md:h-6" aria-hidden="true"></div>
            </main>
        </div>

        <!-- ============================================================= -->
        <!-- 3. MOBILE FLOATING BOTTOM DOCK BAR (md:hidden)                -->
        <!-- ============================================================= -->
        <nav class="phone-dock-bar md:hidden">
            <Link
                v-for="item in navItems"
                :key="typeof item.name === 'object' && 'value' in item.name ? item.name.value : item.name"
                :href="item.href"
                class="dock-item"
                :class="{ active: item.active }"
            >
                <span v-if="item.badge && item.badge > 0" class="dock-badge">
                    {{ item.badge > 99 ? '+99' : item.badge }}
                </span>

                <svg v-if="item.icon === 'home'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                <svg v-else-if="item.icon === 'calls'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                </svg>
                <svg v-else-if="item.icon === 'chat'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                <svg v-else-if="item.icon === 'contacts'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <svg v-else-if="item.icon === 'video'" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>

                <span>{{ typeof item.name === 'object' && 'value' in item.name ? item.name.value : item.name }}</span>
            </Link>
        </nav>

        <!-- ============================================================= -->
        <!-- 4. MORE SERVICES SHEET (Mobile Drawer)                        -->
        <!-- ============================================================= -->
        <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="isMoreMenuOpen" class="fixed inset-0 bg-black/60 backdrop-blur-md z-50 flex flex-col justify-end items-center" @click.self="isMoreMenuOpen = false">
                <div class="glass-card w-full max-w-[460px] !rounded-b-none !rounded-t-[32px] p-5 pb-6 border-b-0 space-y-3.5 shadow-2xl animate-in slide-in-from-bottom duration-300">
                    <div class="flex items-center justify-between pb-3 border-b border-white/10">
                        <span class="text-xs font-extrabold text-[var(--text-primary)] flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            {{ __('ui.layouts.user.nav.services_menu') }}
                        </span>
                        <button @click="isMoreMenuOpen = false" class="text-xs text-[var(--text-muted)] p-1">✕</button>
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">
                        <!-- Direct Link to Admin Panel for Admin Users -->
                        <Link
                            v-if="$page.props.auth?.user?.is_admin"
                            :href="route('admin.dashboard')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 flex items-center gap-2.5 text-xs font-bold text-amber-600 dark:text-amber-400 transition-all col-span-2"
                        >
                            <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-500 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                            <div class="flex-1 flex items-center justify-between">
                                <span>{{ __('ui.layouts.user.nav.switch_to_admin') }}</span>
                                <span class="text-[10px] font-bold">⚙️</span>
                            </div>
                        </Link>

                        <!-- Direct Link to Org Panel -->
                        <Link
                            :href="route('org.dashboard')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-indigo-600/15 hover:bg-indigo-600/25 border border-indigo-500/30 flex items-center gap-2.5 text-xs font-bold text-indigo-400 transition-all col-span-2"
                        >
                            <div class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                            </div>
                            <div class="flex-1 flex items-center justify-between">
                                <span>{{ __('softphone.nav.switch_to_org') }}</span>
                                <span class="text-[10px] font-bold">→</span>
                            </div>
                        </Link>

                        <Link
                            :href="route('wallet.index')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/25 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all col-span-2"
                        >
                            <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-500 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <rect x="2" y="5" width="20" height="14" rx="2" stroke-width="2" />
                                    <line x1="2" y1="10" x2="22" y2="10" stroke-width="2" />
                                </svg>
                            </div>
                            <div class="flex-1 flex items-center justify-between">
                                <span>{{ __('softphone.nav.quick_wallet') }}</span>
                                <span class="font-mono text-xs text-amber-500 font-black">{{ $page.props.auth?.wallet_balance || '0' }} COIN</span>
                            </div>
                        </Link>

                        <Link
                            :href="route('tickets.index')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all"
                        >
                            <div class="w-8 h-8 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </div>
                            <span>{{ __('softphone.nav.tickets') }}</span>
                        </Link>

                        <Link
                            :href="route('profile.edit')"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 flex items-center gap-2.5 text-xs font-bold text-[var(--text-primary)] transition-all"
                        >
                            <div class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <span>{{ __('softphone.nav.profile') }}</span>
                        </Link>

                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            @click="isMoreMenuOpen = false"
                            class="p-3 rounded-2xl bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/20 flex items-center gap-2.5 text-xs font-bold text-rose-500 transition-all text-right w-full col-span-2"
                        >
                            <div class="w-8 h-8 rounded-xl bg-rose-500/20 text-rose-500 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                            </div>
                            <span>{{ __('softphone.nav.logout') }}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>
