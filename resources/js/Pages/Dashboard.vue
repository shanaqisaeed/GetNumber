<script setup lang="ts">
import AllocateNumberModal from '@/Components/AllocateNumberModal.vue';
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Quota {
    active: number;
    max: number;
    remaining: number;
    percentage: number;
}

interface Stats {
    active_numbers: number;
    total_messages: number;
    unread_messages: number;
    pending_requests: number;
}

interface RecentNumber {
    id: number;
    number: string;
    formatted_number: string;
    display_title?: string | null;
    type: string;
    type_label: string;
    allocated_at: string;
    allocated_ago: string;
}

interface UnreadSenderSummary {
    sender: string;
    phone_number_id: number;
    destination_number: string;
    count: number;
    received_at: string;
}

interface WalletInfo {
    balance: string;
    currency: string;
    trx_equivalent: string;
    trx_to_coin_rate: string;
    number_price_coin: string;
}

const props = defineProps<{
    quota: Quota;
    stats: Stats;
    recent_numbers: RecentNumber[];
    unread_messages: UnreadSenderSummary[];
    default_prefix: string;
    wallet?: WalletInfo;
}>();

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showToast = (msg?: string) => {
    toastMessage.value = msg || 'عملیات با موفقیت انجام شد';
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
};

const copyToClipboard = async (text: string, label = 'شماره در کلیپ‌بورد کپی شد') => {
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

// Standard Number Allocation Modal State
const isAllocateModalOpen = ref(false);
</script>

<template>
    <Head title="میز کار و داشبورد کاربری" />

    <UserLayout>
        <!-- Floating Toast Notification (Positioned at top with z-[100] so it is NEVER hidden under headers) -->
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

        <!-- 0. Coin Wallet & Financial Overview Card -->
        <div class="relative overflow-hidden rounded-3xl p-4 sm:p-5 mb-4 bg-gradient-to-br from-amber-500/15 via-slate-900/40 to-indigo-900/30 dark:from-amber-500/10 dark:via-slate-900/60 dark:to-indigo-950/40 border border-amber-500/30 shadow-xl backdrop-blur-xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center font-black text-sm border border-amber-500/30">
                        🪙
                    </div>
                    <div>
                        <span class="text-xs font-extrabold text-[var(--text-primary)] block">کیف پول و اعتبار داخلی</span>
                        <span class="text-[10px] text-[var(--text-muted)]">واحد حساب: {{ props.wallet?.currency || 'COIN' }}</span>
                    </div>
                </div>
                <Link
                    :href="route('wallet.index')"
                    class="px-2.5 py-1 rounded-xl bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 border border-amber-500/40 text-[11px] font-extrabold flex items-center gap-1 transition-all"
                >
                    <span>گردش حساب</span>
                    <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </Link>
            </div>

            <div class="mt-3.5 flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <div class="text-2xl sm:text-3xl font-black font-mono tracking-tight text-[var(--text-primary)]">
                        {{ props.wallet?.balance ?? '0.0000' }}
                        <span class="text-sm font-extrabold text-amber-400 mr-1">COIN</span>
                    </div>
                    <div class="text-[11px] font-semibold text-[var(--text-muted)] mt-0.5 flex items-center gap-1">
                        <span>معادل تقریبی:</span>
                        <span class="font-mono font-bold text-[var(--text-primary)]">{{ props.wallet?.trx_equivalent ?? '0.0000' }} TRX</span>
                        <span class="text-[10px] opacity-75">(۱ TRX = {{ props.wallet?.trx_to_coin_rate ?? '1' }} COIN)</span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-[var(--text-muted)] hidden sm:inline">
                        هزینه هر خط: <strong class="text-amber-400 font-mono">{{ props.wallet?.number_price_coin ?? '1.0000' }} COIN</strong>
                    </span>
                    <Link
                        :href="route('wallet.index')"
                        class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-slate-950 text-xs font-black shadow-lg shadow-amber-500/20 flex items-center gap-1.5 transition-all"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>شارژ کیف پول</span>
                    </Link>
                </div>
            </div>
        </div>

        <!-- 1. Quota & Line Capacity Card (Liquid Glass Gauge) -->
        <div class="dash-wallet-card">
            <div class="wallet-top-row">
                <span class="wallet-title">سهمیه خطوط سازمانی شما</span>
                <span
                    class="wallet-chip"
                    :class="[
                        props.quota.remaining > 0
                            ? 'bg-emerald-500/15 border-emerald-500/35 text-emerald-400'
                            : 'bg-rose-500/15 border-rose-500/35 text-rose-400'
                    ]"
                >
                    {{ props.quota.remaining > 0 ? 'سهمیه در دسترس' : 'تکمیل سقف سهمیه' }}
                </span>
            </div>

            <!-- Big Gauge Numbers -->
            <div class="wallet-amount">
                <span>{{ props.quota.active }}</span>
                <span class="wallet-currency text-sm font-bold text-slate-400 dark:text-slate-400">/ {{ props.quota.max }} خط</span>
            </div>

            <!-- Dynamic Progress Meter Track -->
            <div class="mt-3.5">
                <div class="flex items-center justify-between text-[11px] font-semibold text-slate-400 dark:text-slate-400 mb-1.5">
                    <span>مصرف‌شده: {{ props.quota.percentage }}٪</span>
                    <span>ظرفیت باقی‌مانده: {{ props.quota.remaining }} شماره</span>
                </div>
                <div class="h-2 w-full rounded-full bg-slate-800/60 dark:bg-slate-800/60 overflow-hidden p-0.5 border border-white/10">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 transition-all duration-700 shadow-sm"
                        :style="{ width: `${Math.max(5, props.quota.percentage)}%` }"
                    ></div>
                </div>
            </div>

            <!-- Quick Action Buttons -->
            <div class="wallet-actions">
                <button
                    type="button"
                    @click="isAllocateModalOpen = true"
                    :disabled="props.quota.remaining <= 0"
                    class="wallet-btn-topup disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <line x1="12" y1="5" x2="12" y2="19" stroke-width="2.5"></line>
                        <line x1="5" y1="12" x2="19" y2="12" stroke-width="2.5"></line>
                    </svg>
                    <span>دریافت شماره اعتباری</span>
                </button>

                <Link :href="route('requests.index')" class="wallet-btn-history">
                    <svg class="w-4 h-4 text-indigo-400 ml-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                    </svg>
                    <span>درخواست خط تجاری</span>
                </Link>
            </div>
        </div>

        <!-- 2. Four Key Metric Cards (2x2 Grid) -->
        <div class="dash-stats-grid">
            <!-- Active Numbers -->
            <Link :href="route('numbers.index')" class="stat-card group">
                <div class="stat-icon-wrapper bg-indigo-500/20 text-indigo-400 group-hover:scale-105 transition-transform">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                        <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"></line>
                    </svg>
                </div>
                <div class="stat-val text-indigo-500 dark:text-indigo-400">
                    {{ props.stats.active_numbers }} <span class="text-xs font-normal">خط</span>
                </div>
                <div class="stat-label">شماره‌های فعال</div>
            </Link>

            <!-- Received SMS Messages -->
            <Link :href="route('messages.index')" class="stat-card group">
                <div class="stat-icon-wrapper bg-emerald-500/20 text-emerald-400 group-hover:scale-105 transition-transform">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                </div>
                <div class="stat-val text-emerald-500 dark:text-emerald-400">
                    {{ props.stats.total_messages }} <span class="text-xs font-normal">پیام</span>
                </div>
                <div class="stat-label">پیامک‌های دریافتی</div>
            </Link>

            <!-- Unread Messages Count -->
            <Link :href="route('messages.index')" class="stat-card group">
                <div class="stat-icon-wrapper bg-rose-500/20 text-rose-400 group-hover:scale-105 transition-transform">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <div class="stat-val text-rose-500 dark:text-rose-400">
                    {{ props.stats.unread_messages }} <span class="text-xs font-normal">جدید</span>
                </div>
                <div class="stat-label">خوانده‌نشده</div>
            </Link>

            <!-- Pending Requests -->
            <Link :href="route('requests.index')" class="stat-card group">
                <div class="stat-icon-wrapper bg-amber-500/20 text-amber-400 group-hover:scale-105 transition-transform">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <circle cx="12" cy="12" r="10" stroke-width="2"></circle>
                        <polyline points="12 6 12 12 16 14" stroke-width="2"></polyline>
                    </svg>
                </div>
                <div class="stat-val text-amber-500 dark:text-amber-400">
                    {{ props.stats.pending_requests }} <span class="text-xs font-normal">مورد</span>
                </div>
                <div class="stat-label">درخواست‌های جاری</div>
            </Link>
        </div>

        <!-- 3. Unread Inbound Messages Summary (Strictly shows who sent them & how many, NO full message body) -->
        <div v-if="props.unread_messages.length > 0" class="mb-5">
            <div class="section-header-row">
                <span class="section-title flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                    پیام‌های خوانده‌نشده
                    <span class="text-[10px] font-extrabold text-rose-400 bg-rose-500/15 border border-rose-500/30 px-2 py-0.5 rounded-full">
                        {{ props.stats.unread_messages }} پیام
                    </span>
                </span>
                <Link :href="route('messages.index')" class="section-link">مشاهده در پیام‌رسان</Link>
            </div>

            <div class="space-y-2">
                <div
                    v-for="item in props.unread_messages"
                    :key="item.sender + '_' + item.phone_number_id"
                    class="active-number-card !p-3 hover:border-rose-500/40 transition-all"
                >
                    <div class="w-10 h-10 rounded-2xl bg-rose-500/15 text-rose-500 border border-rose-500/30 flex items-center justify-center text-sm font-extrabold shrink-0">
                        📩
                    </div>

                    <div class="number-info-box">
                        <div class="flex items-center justify-between">
                            <span class="font-extrabold text-xs text-[var(--text-primary)] font-mono dir-ltr truncate">
                                {{ item.sender }}
                            </span>
                            <span class="text-[10px] font-extrabold text-rose-400 bg-rose-500/15 px-2 py-0.5 rounded-full border border-rose-500/30">
                                {{ item.count }} پیام جدید
                            </span>
                        </div>
                        <div class="number-meta text-[10px]">
                            <span>خط دریافت: {{ item.destination_number }}</span>
                            <span>•</span>
                            <span class="text-rose-400">{{ item.received_at }}</span>
                        </div>
                    </div>

                    <Link
                        :href="route('messages.index', { phone_number_id: item.phone_number_id, peer: item.sender })"
                        class="btn-copy-code !bg-indigo-600 hover:!bg-indigo-500 !text-white !border-indigo-500 text-xs font-bold shrink-0"
                    >
                        مشاهده
                    </Link>
                </div>
            </div>
        </div>

        <!-- 4. Recent Active Numbers List -->
        <div class="mb-5">
            <div class="section-header-row">
                <span class="section-title">خطوط فعال اخیر شما</span>
                <Link :href="route('numbers.index')" class="section-link">مشاهده همه ({{ props.stats.active_numbers }})</Link>
            </div>

            <!-- Numbers Cards -->
            <div v-if="props.recent_numbers.length > 0" class="space-y-2">
                <div
                    v-for="phone in props.recent_numbers"
                    :key="phone.id"
                    class="active-number-card"
                >
                    <div class="number-service-logo bg-gradient-to-tr from-indigo-600 to-purple-600 shadow-md shadow-indigo-500/20">
                        📱
                    </div>

                    <div class="number-info-box">
                        <div class="number-digit">
                            <span class="text-slate-900 dark:text-white font-extrabold tracking-wide">{{ phone.formatted_number }}</span>
                        </div>
                        <div class="number-meta">
                            <span class="status-dot-pulse"></span>
                            <span>{{ phone.display_title || phone.type_label }} • {{ phone.allocated_ago }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5 shrink-0">
                        <button
                            type="button"
                            @click="copyToClipboard(phone.number, 'شماره در کلیپ‌بورد کپی شد')"
                            class="btn-copy-code"
                            title="کپی شماره"
                        >
                            کپی
                        </button>
                        <Link
                            :href="route('messages.index', { phone_number_id: phone.id })"
                            class="w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500/20 flex items-center justify-center transition-all"
                            title="مشاهده پیام‌ها"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="glass-card !p-8 text-center space-y-3">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-indigo-500/15 text-indigo-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                        <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"></line>
                    </svg>
                </div>
                <div>
                    <h3 class="text-xs font-extrabold text-[var(--text-primary)]">تاکنون خطی به شما تخصیص داده نشده است</h3>
                    <p class="text-[11px] text-[var(--text-muted)] mt-1">با کلیک روی دکمه زیر می‌توانید اولین خط سازمانی خود را دریافت کنید.</p>
                </div>
                <button
                    type="button"
                    @click="isAllocateModalOpen = true"
                    class="glass-btn-primary !w-auto !px-5 !py-2.5 !text-xs mx-auto"
                >
                    دریافت اولین خط سازمانی
                </button>
            </div>
        </div>

        <!-- Unified Number Allocation Modal -->
        <AllocateNumberModal
            :show="isAllocateModalOpen"
            :default-prefix="props.default_prefix"
            :remaining-quota="props.quota.remaining"
            :max-quota="props.quota.max"
            @close="isAllocateModalOpen = false"
            @success="showToast"/>
    </UserLayout>
</template>
