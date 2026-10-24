<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
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

const props = defineProps<{
    quota: Quota;
    stats: Stats;
    recent_numbers: RecentNumber[];
    unread_messages: UnreadSenderSummary[];
    default_prefix: string;
}>();

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
const allocateForm = useForm({
    display_title: '',
    preferred_number: '',
});

const submitAllocation = () => {
    allocateForm.post(route('numbers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isAllocateModalOpen.value = false;
            allocateForm.reset();
            showToast('خط سازمانی جدید با موفقیت تخصیص یافت');
        },
        onError: () => {
            showToast('خطا در تخصیص خط. لطفاً سهمیه و ضوابط را بررسی کنید.');
        },
    });
};
</script>

<template>
    <Head title="پیشخوان کاربری | سامانه مدیریت خطوط" />

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
                    <span>دریافت خط سازمانی</span>
                </button>

                <Link :href="route('requests.index')" class="wallet-btn-history">
                    <svg class="w-4 h-4 text-indigo-400 ml-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                    </svg>
                    <span>خط اختصاصی</span>
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

        <!-- Allocation Modal Dialog -->
        <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="isAllocateModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md" @click.self="isAllocateModalOpen = false">
                <div class="glass-card w-full max-w-sm !p-5 space-y-4 shadow-2xl animate-in zoom-in-95 duration-200">
                    <div class="flex items-center justify-between pb-3 border-b border-white/10">
                        <div class="flex items-center gap-2">
                            <span class="w-8 h-8 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center">
                                📱
                            </span>
                            <div>
                                <h3 class="text-xs font-extrabold text-[var(--text-primary)]">تخصیص خط سازمانی جدید</h3>
                                <p class="text-[10px] text-[var(--text-muted)]">سرشماره ساختاریافته با پیشوند {{ props.default_prefix }}</p>
                            </div>
                        </div>
                        <button type="button" @click="isAllocateModalOpen = false" class="text-xs text-[var(--text-muted)] hover:text-white p-1">
                            ✕
                        </button>
                    </div>

                    <form @submit.prevent="submitAllocation" class="space-y-3.5">
                        <div class="p-3 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-[11px] text-[var(--text-secondary)] space-y-1">
                            <div class="flex justify-between font-bold text-indigo-400">
                                <span>پیشوند پیش‌فرض:</span>
                                <span class="font-mono dir-ltr">{{ props.default_prefix }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>سهمیه مجاز باقی‌مانده:</span>
                                <span class="font-bold text-emerald-400">{{ props.quota.remaining }} شماره</span>
                            </div>
                        </div>

                        <div class="glass-input-wrapper !mb-0">
                            <label class="glass-label">عنوان یا شناسه نمایشی خط (اختیاری)</label>
                            <div class="glass-input-box">
                                <div class="glass-input-icon">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="allocateForm.display_title"
                                    type="text"
                                    class="glass-input"
                                    placeholder="مثال: خط ارتباطی واحد فروش"
                                    maxlength="100"
                                />
                            </div>
                        </div>

                        <div class="pt-2 flex items-center gap-2">
                            <button
                                type="submit"
                                :disabled="allocateForm.processing || props.quota.remaining <= 0"
                                class="glass-btn-primary flex-1 !py-2.5 !text-xs disabled:opacity-50"
                            >
                                <span v-if="allocateForm.processing">در حال تخصیص شماره...</span>
                                <span v-else>تایید و تخصیص آنی خط</span>
                            </button>
                            <button
                                type="button"
                                @click="isAllocateModalOpen = false"
                                class="px-4 py-2.5 rounded-2xl bg-white/5 hover:bg-white/10 text-xs font-semibold text-[var(--text-secondary)] transition-all"
                            >
                                انصراف
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>
    </UserLayout>
</template>
