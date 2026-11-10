<script setup lang="ts">
import { ref } from 'vue';

interface SessionItem {
    id: string;
    ip_address: string;
    is_current_device: boolean;
    platform: string;
    browser: string;
    device_type: 'desktop' | 'mobile' | 'tablet';
    last_active: string;
    last_activity_date: string;
    last_activity_timestamp: number;
}

interface Props {
    sessions?: SessionItem[];
    hasPassword?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    sessions: () => [],
    hasPassword: true,
});

const sessionsList = ref<SessionItem[]>([...props.sessions]);

// Keep reactive with prop changes
const updateSessions = (newSessions: SessionItem[]) => {
    sessionsList.value = [...newSessions];
};

const isModalOpen = ref(false);
const password = ref('');
const passwordError = ref<string | null>(null);
const isLoading = ref(false);
const terminatingSessionId = ref<string | null>(null);
const toast = ref<string | null>(null);

const showToast = (msg: string) => {
    toast.value = msg;
    setTimeout(() => {
        toast.value = null;
    }, 4000);
};

// Terminate a single specific session
const terminateSession = async (sessionId: string) => {
    if (!confirm('آیا از خاتمه دادن به این نشست و خروج این دستگاه از حساب اطمینان دارید؟')) {
        return;
    }

    terminatingSessionId.value = sessionId;

    try {
        const res = await fetch(route('sessions.destroy', sessionId), {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });

        const data = await res.json();
        if (res.ok && data.success) {
            showToast('نشست دستگاه انتخابی با موفقیت لغو شد و کاربر از آن خارج گردید.');
            if (data.sessions) {
                updateSessions(data.sessions);
            } else {
                sessionsList.value = sessionsList.value.filter(s => s.id !== sessionId);
            }
        } else {
            alert(data.message || 'خطا در لغو نشست.');
        }
    } catch {
        alert('خطا در ارتباط با سرور.');
    } finally {
        terminatingSessionId.value = null;
    }
};

// Terminate all other sessions across devices
const logoutOtherSessions = async () => {
    if (props.hasPassword && !password.value) {
        passwordError.value = 'ورود کلمه عبور فعلی الزامی است.';
        return;
    }

    isLoading.value = true;
    passwordError.value = null;

    try {
        const res = await fetch(route('sessions.logout_other'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({ password: password.value }),
        });

        const data = await res.json();
        if (res.ok && data.success) {
            showToast('تمامی نشست‌های فعال روی سایر دستگاه‌ها با موفقیت لغو و خارج شدند.');
            isModalOpen.value = false;
            password.value = '';
            if (data.sessions) {
                updateSessions(data.sessions);
            } else {
                sessionsList.value = sessionsList.value.filter(s => s.is_current_device);
            }
        } else {
            passwordError.value = data.errors?.password?.[0] || data.message || 'کلمه عبور وارد شده اشتباه است.';
        }
    } catch {
        passwordError.value = 'خطا در برقراری ارتباط با سرور.';
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <div class="space-y-6">
        <!-- Toast Notification -->
        <div v-if="toast" class="p-3.5 rounded-2xl bg-emerald-600 text-white text-xs font-bold text-center shadow-lg transition-all animate-in fade-in slide-in-from-top-2">
            {{ toast }}
        </div>

        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-cyan-500 shadow-sm shadow-cyan-500/50"></span>
                    دستگاه‌ها و نشست‌های فعال (Active Sessions)
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    فهرست تمام مرورگرها و دستگاه‌هایی که هم‌اکنون به این حساب کاربری دسترسی دارند. با خروج از هر نشست، ارتباط آن دستگاه بلافاصله قطع می‌شود.
                </p>
            </div>

            <button
                type="button"
                @click="isModalOpen = true; password = ''; passwordError = null;"
                class="px-4 py-2 rounded-xl text-xs font-bold bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/40 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-900/60 transition-all flex items-center gap-1.5 cursor-pointer shadow-xs"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                <span>خروج از تمام دستگاه‌های دیگر</span>
            </button>
        </div>

        <!-- Sessions Cards List -->
        <div class="space-y-3">
            <div
                v-for="session in sessionsList"
                :key="session.id"
                class="p-4 rounded-2xl border transition-all flex items-center justify-between gap-4 flex-wrap"
                :class="session.is_current_device
                    ? 'bg-indigo-50/50 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800/80 shadow-xs'
                    : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700/80'"
            >
                <div class="flex items-center gap-3.5">
                    <!-- Device Icon -->
                    <div
                        class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0"
                        :class="session.is_current_device
                            ? 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400'
                            : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'"
                    >
                        <!-- Mobile Icon -->
                        <svg v-if="session.device_type === 'mobile'" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        <!-- Tablet Icon -->
                        <svg v-else-if="session.device_type === 'tablet'" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        <!-- Desktop / Laptop Icon -->
                        <svg v-else class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>

                    <!-- Device Info -->
                    <div class="space-y-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h4 class="text-xs font-black text-slate-900 dark:text-white">
                                {{ session.platform }} — {{ session.browser }}
                            </h4>

                            <!-- Current Device Badge -->
                            <span
                                v-if="session.is_current_device"
                                class="inline-flex items-center gap-1 text-[10px] font-black px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30"
                            >
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                این دستگاه (نشست جاری)
                            </span>
                        </div>

                        <div class="flex items-center gap-3 text-[11px] text-slate-500 dark:text-slate-400 flex-wrap">
                            <span class="font-mono" dir="ltr">IP: {{ session.ip_address }}</span>
                            <span>•</span>
                            <span>{{ session.last_active }}</span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div>
                    <button
                        v-if="!session.is_current_device"
                        type="button"
                        @click="terminateSession(session.id)"
                        :disabled="terminatingSessionId === session.id"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/50 border border-rose-200 dark:border-rose-900/60 transition-all cursor-pointer"
                    >
                        {{ terminatingSessionId === session.id ? 'در حال قطع...' : 'قطع این نشست' }}
                    </button>
                    <span v-else class="text-[11px] text-indigo-600 dark:text-indigo-400 font-bold px-3 py-1.5">
                        نشست فعال
                    </span>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="sessionsList.length === 0" class="p-6 text-center text-xs text-slate-400">
                هیچ نشست فعالی در سیستم یافت نشد.
            </div>
        </div>

        <!-- Terminate All Other Sessions Modal -->
        <div
            v-if="isModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm"
            @click.self="isModalOpen = false"
        >
            <div class="p-6 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200">
                <div class="flex items-center gap-3 text-rose-600 dark:text-rose-400">
                    <div class="w-10 h-10 rounded-2xl bg-rose-500/15 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-black">خروج قطعی از تمام دستگاه‌های دیگر</h4>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">ابطال کلیه نشست‌های فعال به جز این دستگاه</p>
                    </div>
                </div>

                <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    با تایید این عملیات، تمامی نشست‌های فعال این حساب روی سایر رایانه‌ها، مرورگرها و تلفن‌های همراه به صورت قطعی لغو شده و مجدداً به صفحه ورود هدایت خواهند شد.
                </p>

                <div v-if="hasPassword" class="space-y-1.5">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                        کلمه عبور فعلی حساب جهت تایید امنیتی:
                    </label>
                    <input
                        v-model="password"
                        @keydown.enter.prevent="logoutOtherSessions"
                        type="password"
                        placeholder="••••••••••••"
                        dir="ltr"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none dark:text-white font-mono"
                        required
                    />
                    <p v-if="passwordError" class="text-[11px] text-rose-500 font-bold">{{ passwordError }}</p>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                    <button
                        type="button"
                        @click="isModalOpen = false"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="logoutOtherSessions"
                        :disabled="isLoading || (hasPassword && !password)"
                        class="px-5 py-2 rounded-xl text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-md shadow-rose-600/30 transition-all cursor-pointer disabled:opacity-50"
                    >
                        <span v-if="isLoading">در حال خروج از سایر دستگاه‌ها...</span>
                        <span v-else>تایید و خروج از همه</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

