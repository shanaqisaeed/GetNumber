<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Props {
    status?: string;
    initialTab?: 'info' | 'security' | 'danger';
    wallet?: { balance: string };
    pendingDeposit?: {
        tracking_code: string;
        currency: string;
        network: string;
        requested_amount: string;
        coin_amount: string;
        deposit_address: string;
        status: string;
        status_label: string;
        status_badge: string;
        created_at: string;
    } | null;
}

const props = withDefaults(defineProps<Props>(), {
    initialTab: 'info',
    wallet: () => ({ balance: '0' }),
    pendingDeposit: null,
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

// Integer credit amount formatted
const userCredit = computed(() => {
    const raw = Number(props.wallet?.balance || 0);
    return Math.floor(raw);
});

// Tab navigation state (strictly 3 tabs: info, security, danger)
const activeTab = ref<'info' | 'security' | 'danger'>(
    props.initialTab === 'security' || props.initialTab === 'danger' ? props.initialTab : 'info'
);

watch(
    () => props.initialTab,
    (val) => {
        if (val === 'security' || val === 'danger' || val === 'info') {
            activeTab.value = val;
        }
    }
);

// Quick Recharge Form (strictly integer units, auto gateway load balanced)
const rechargeForm = useForm({
    amount: 25,
});

const presetAmounts = [10, 25, 50, 100, 250];

const stepAmount = (delta: number) => {
    const next = Math.max(1, (Number(rechargeForm.amount) || 0) + delta);
    rechargeForm.amount = next;
};

const setPresetAmount = (val: number) => {
    rechargeForm.amount = val;
};

const submitRecharge = () => {
    rechargeForm.post(route('wallet.deposit.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="تنظیمات کاربری و پروفایل" />

    <UserLayout>
        <div class="space-y-4 max-w-xl mx-auto pb-10">
            <!-- 1. Hero Profile Card with Credit Balance -->
            <div class="dash-wallet-card !p-5 relative overflow-hidden">
                <!-- Background decorative ambient blur -->
                <div class="absolute -top-16 -right-16 w-40 h-40 bg-indigo-500/20 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute -bottom-16 -left-16 w-40 h-40 bg-purple-500/20 rounded-full blur-2xl pointer-events-none"></div>

                <div class="relative z-10 flex items-center gap-3.5">
                    <!-- Avatar with gradient ring -->
                    <div class="relative shrink-0">
                        <div class="w-14 h-14 rounded-2xl p-0.5 bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 shadow-md shadow-indigo-500/30">
                            <div class="w-full h-full rounded-[14px] bg-slate-900 flex items-center justify-center font-black text-xl text-white">
                                {{ user?.name ? user.name.charAt(0) : 'U' }}
                            </div>
                        </div>
                        <span class="status-dot-pulse absolute -bottom-0.5 -right-0.5 border-2 border-slate-900" title="وضعیت آنلاین"></span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <h2 class="text-base font-extrabold text-white truncate">
                                {{ user?.name || 'کاربر گرامی' }}
                            </h2>

                            <!-- Credit Balance Badge (Replacing Role Badge) -->
                            <div class="px-3 py-1 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-500/35 text-xs font-black flex items-center gap-1.5 shadow-sm">
                                <span class="text-amber-400">🪙</span>
                                <span>اعتبار: {{ userCredit }} واحد</span>
                            </div>
                        </div>

                        <p class="text-xs text-indigo-200/80 font-mono mt-0.5 dir-ltr truncate text-right">
                            @{{ user?.username }}
                        </p>
                        <div class="flex items-center gap-2 text-[10px] text-slate-400 mt-1">
                            <span>شناسه حساب: #{{ user?.id }}</span>
                            <span>•</span>
                            <span class="text-emerald-400">احراز شده و فعال</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Quick Access Row (Grid 2: API, Support, Transactions, Logout) -->
            <div class="grid grid-cols-2 gap-2.5">
                <!-- API Tokens Shortcut -->
                <Link
                    :href="route('api-tokens.index')"
                    class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-cyan-500/40 transition-all flex items-center gap-3 group shadow-xs cursor-pointer"
                >
                    <div class="w-10 h-10 rounded-xl bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center group-hover:scale-105 transition-transform shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <div class="text-right min-w-0">
                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">کلیدهای API</span>
                        <span class="text-[10px] text-slate-400">مدیریت توکن‌های اتصال</span>
                    </div>
                </Link>

                <!-- Support Shortcut -->
                <Link
                    :href="route('tickets.index')"
                    class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-indigo-500/40 transition-all flex items-center gap-3 group shadow-xs cursor-pointer"
                >
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center group-hover:scale-105 transition-transform shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div class="text-right min-w-0">
                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">پشتیبانی</span>
                        <span class="text-[10px] text-slate-400">تیکت و ارتباط آنلاین</span>
                    </div>
                </Link>

                <!-- Transactions Shortcut -->
                <Link
                    :href="route('wallet.transactions')"
                    class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-emerald-500/40 transition-all flex items-center gap-3 group shadow-xs cursor-pointer"
                >
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center group-hover:scale-105 transition-transform shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                    </div>
                    <div class="text-right min-w-0">
                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block">لیست تراکنش‌ها</span>
                        <span class="text-[10px] text-slate-400">گردش حساب و پرداخت‌ها</span>
                    </div>
                </Link>

                <!-- Logout Button -->
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="p-3 rounded-2xl bg-rose-50/80 dark:bg-rose-950/20 border border-rose-200/80 dark:border-rose-900/40 hover:bg-rose-100/80 dark:hover:bg-rose-950/40 transition-all flex items-center gap-3 group shadow-xs w-full text-right cursor-pointer"
                >
                    <div class="w-10 h-10 rounded-xl bg-rose-500/15 text-rose-600 dark:text-rose-400 flex items-center justify-center group-hover:scale-105 transition-transform shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </div>
                    <div class="text-right min-w-0">
                        <span class="text-xs font-bold text-rose-600 dark:text-rose-400 block">خروج از حساب</span>
                        <span class="text-[10px] text-rose-400/80">خروج امن از سیستم</span>
                    </div>
                </Link>
            </div>

            <!-- 3. Dedicated Recharge Card (Integer Stepper + Presets + Direct Charge) -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-md space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"></span>
                            شارژ حساب کاربری
                        </h3>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            افزایش آنی موجودی کیف پول به نسبت ۱ به ۱ با ترون (TRX)
                        </p>
                    </div>

                    <span class="text-[11px] font-bold text-amber-500 bg-amber-500/10 px-2 py-0.5 rounded-lg border border-amber-500/20">
                        ۱ TRX = ۱ واحد
                    </span>
                </div>

                <!-- Existing Pending Deposit Alert (if any) -->
                <div
                    v-if="pendingDeposit"
                    class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/25 flex items-center justify-between gap-3 text-xs"
                >
                    <div class="space-y-0.5">
                        <span class="font-bold text-amber-700 dark:text-amber-300 block">یک فاکتور در انتظار پرداخت دارید:</span>
                        <span class="font-mono text-[11px] text-slate-600 dark:text-slate-300">{{ pendingDeposit.tracking_code }} ({{ pendingDeposit.requested_amount }} TRX)</span>
                    </div>
                    <Link
                        :href="route('wallet.deposit.show', pendingDeposit.tracking_code)"
                        class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs shadow-xs transition-all cursor-pointer shrink-0"
                    >
                        مشاهده فاکتور
                    </Link>
                </div>

                <!-- Stepper Input & Form -->
                <form @submit.prevent="submitRecharge" class="space-y-4">
                    <div class="space-y-2">
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                            مقدار افزایش اعتبار (واحد اعتبار):
                        </label>

                        <!-- Plus / Minus Stepper Group -->
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="stepAmount(-5)"
                                class="w-12 h-11 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-black text-lg transition-all flex items-center justify-center cursor-pointer shrink-0 border border-slate-200 dark:border-slate-700"
                                title="کاهش ۵ واحد"
                            >
                                -
                            </button>

                            <div class="relative flex-1">
                                <input
                                    v-model.number="rechargeForm.amount"
                                    type="number"
                                    min="1"
                                    step="1"
                                    required
                                    class="w-full h-11 px-4 text-center rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-base font-black text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
                                />
                                <span class="absolute left-3 top-3 text-xs font-bold text-slate-400 pointer-events-none">
                                    واحد
                                </span>
                            </div>

                            <button
                                type="button"
                                @click="stepAmount(5)"
                                class="w-12 h-11 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-black text-lg transition-all flex items-center justify-center cursor-pointer shrink-0 border border-slate-200 dark:border-slate-700"
                                title="افزایش ۵ واحد"
                            >
                                +
                            </button>
                        </div>
                    </div>

                    <!-- Preset Chips -->
                    <div class="space-y-1.5">
                        <span class="text-[11px] font-bold text-slate-400 block">ارقام پیشنهادی سریع:</span>
                        <div class="grid grid-cols-5 gap-1.5">
                            <button
                                v-for="amt in presetAmounts"
                                :key="amt"
                                type="button"
                                @click="setPresetAmount(amt)"
                                class="py-2 rounded-xl text-xs font-bold transition-all border cursor-pointer text-center"
                                :class="rechargeForm.amount === amt
                                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm shadow-indigo-600/30'
                                    : 'bg-slate-50 dark:bg-slate-800/80 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700'"
                            >
                                {{ amt }}
                            </button>
                        </div>
                    </div>

                    <!-- Payment summary -->
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-850 border border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs">
                        <span class="text-slate-500 dark:text-slate-400">مبلغ نهایی قابل واریز:</span>
                        <div class="font-mono font-black text-slate-900 dark:text-white text-sm">
                            {{ rechargeForm.amount || 0 }} <span class="text-emerald-500 text-xs">TRX</span>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        :disabled="rechargeForm.processing || (rechargeForm.amount || 0) < 1"
                        class="w-full py-3 rounded-2xl bg-gradient-to-r from-amber-500 via-orange-500 to-amber-600 hover:from-amber-600 hover:to-orange-600 text-white font-black text-xs shadow-md shadow-orange-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
                    >
                        <span v-if="rechargeForm.processing">در حال ساخت فاکتور پرداخت...</span>
                        <template v-else>
                            <span>شارژ حساب کاربری</span>
                            <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </template>
                    </button>
                </form>
            </div>

            <!-- 4. Profile Management Tabs (Strictly 3 tabs: Info, Security, Danger) -->
            <div class="grid grid-cols-3 p-1 rounded-2xl bg-slate-200/70 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 text-xs font-bold">
                <button
                    type="button"
                    @click="activeTab = 'info'"
                    class="py-2.5 rounded-xl transition-all flex items-center justify-center gap-1 cursor-pointer"
                    :class="activeTab === 'info'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span>اطلاعات فردی</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'security'"
                    class="py-2.5 rounded-xl transition-all flex items-center justify-center gap-1 cursor-pointer"
                    :class="activeTab === 'security'
                        ? 'bg-white dark:bg-slate-800 text-purple-600 dark:text-purple-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span>تغییر گذرواژه</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'danger'"
                    class="py-2.5 rounded-xl transition-all flex items-center justify-center gap-1 cursor-pointer"
                    :class="activeTab === 'danger'
                        ? 'bg-white dark:bg-slate-800 text-rose-600 dark:text-rose-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span>حذف حساب</span>
                </button>
            </div>

            <!-- 5. Active Tab Content Card -->
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                <!-- Info Tab -->
                <div v-show="activeTab === 'info'">
                    <UpdateProfileInformationForm :status="status" />
                </div>

                <!-- Security / Password Tab -->
                <div v-show="activeTab === 'security'">
                    <UpdatePasswordForm />
                </div>

                <!-- Danger / Delete Account Tab -->
                <div v-show="activeTab === 'danger'">
                    <DeleteUserForm />
                </div>
            </div>
        </div>
    </UserLayout>
</template>
