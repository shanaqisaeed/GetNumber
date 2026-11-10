<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import axios from 'axios';

export interface PrefixItem {
    id: number;
    prefix: string;
    clean_prefix: string;
    name: string;
    country_name?: string;
    country_code?: string;
    flag?: string;
    sub_prefixes?: string[];
    price_regular: string | number;
    price_semi_rond: string | number;
    price_golden_rond: string | number;
    is_default: boolean;
}

interface RondInfo {
    tier: 'golden_rond' | 'semi_rond' | 'regular';
    tier_label: string;
    pattern: string;
    is_rond: boolean;
}

interface SuggestionItem {
    priority: number;
    type: string;
    badge: string;
    number: string;
    sub_prefix: string;
    suffix: string;
    formatted: string;
    rond: RondInfo;
    price: string | number;
}

const props = withDefaults(
    defineProps<{
        show: boolean;
        defaultPrefix?: string;
        activePrefixes?: PrefixItem[];
        remainingQuota?: number;
        maxQuota?: number;
    }>(),
    {
        defaultPrefix: '+85',
        activePrefixes: () => [],
        remainingQuota: undefined,
        maxQuota: undefined,
    }
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'success', message?: string): void;
}>();

const page = usePage();
const userBalance = computed(() => Number((page.props.auth as any)?.wallet_balance || 0));

// Wizard Step: 1 = Search & Number Structure, 2 = Confirmation & Payment
const currentStep = ref<1 | 2>(1);

// Step 1: Selected Prefix ID
const selectedPrefixId = ref<number | null>(null);

const selectedPrefix = computed<PrefixItem | null>(() => {
    if (!props.activePrefixes || props.activePrefixes.length === 0) return null;
    if (selectedPrefixId.value) {
        const found = props.activePrefixes.find((p) => p.id === selectedPrefixId.value);
        if (found) return found;
    }
    const def = props.activePrefixes.find((p) => p.is_default);
    return def || props.activePrefixes[0] || null;
});

const subPrefixesList = computed<string[]>(() => {
    if (selectedPrefix.value && Array.isArray(selectedPrefix.value.sub_prefixes) && selectedPrefix.value.sub_prefixes.length > 0) {
        return selectedPrefix.value.sub_prefixes;
    }
    return ['912', '910', '918', '919', '935', '936', '937', '938', '939', '990'];
});

// Step 1: Selected SubPrefix & 6-digit Suffix
const selectedSubPrefix = ref<string>('');
const suffixInput = ref<string>('');
const suffixInputRef = ref<HTMLInputElement | null>(null);

const isChecking = ref(false);
const checkResult = ref<{
    is_available: boolean;
    message?: string;
    number?: string;
    formatted?: string;
    rond?: RondInfo;
    price?: string | number;
    suggestions?: SuggestionItem[];
} | null>(null);

// Random pool suggestions for browsing
const poolSuggestions = ref<any[]>([]);
const isLoadingPool = ref(false);
const showTariffsOverview = ref(false);

// Step 2: Terms & Form
const termsAccepted = ref(false);

const form = useForm({
    phone_prefix_id: null as number | null,
    preferred_number: '',
    display_title: '',
});

// Watch show prop to initialize or reset wizard
watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            currentStep.value = 1;
            termsAccepted.value = false;
            suffixInput.value = '';
            checkResult.value = null;
            poolSuggestions.value = [];
            form.reset();
            form.clearErrors();

            // Default prefix selection
            const defaultPref = props.activePrefixes.find((p) => p.is_default) || props.activePrefixes[0] || null;
            if (defaultPref) {
                selectedPrefixId.value = defaultPref.id;
                selectedSubPrefix.value = subPrefixesList.value[0] || '912';
            }
            loadPoolSuggestions();

            window.addEventListener('keydown', handleKeydown);

            nextTick(() => {
                suffixInputRef.value?.focus();
            });
        } else {
            window.removeEventListener('keydown', handleKeydown);
        }
    }
);

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.show && !form.processing) {
        handleClose();
    }
};

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

const handleClose = () => {
    if (!form.processing) {
        emit('close');
    }
};

const onPrefixIdChange = () => {
    selectedSubPrefix.value = subPrefixesList.value[0] || '912';
    loadPoolSuggestions();
    if (suffixInput.value.length === 6) {
        executeCheck();
    } else {
        checkResult.value = null;
    }
};

const onSubPrefixSelectChange = () => {
    if (suffixInput.value.length === 6) {
        executeCheck();
    }
};

const pickSubPrefix = (sub: string) => {
    selectedSubPrefix.value = sub;
    if (suffixInput.value.length === 6) {
        executeCheck();
    }
};

// Real-time verification debounce
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const onSuffixInput = () => {
    const clean = suffixInput.value.replace(/\D/g, '').slice(0, 6);
    suffixInput.value = clean;

    if (clean.length !== 6) {
        checkResult.value = null;
        return;
    }

    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        executeCheck();
    }, 250);
};

const executeCheck = async () => {
    if (!selectedPrefix.value || suffixInput.value.length !== 6) return;

    isChecking.value = true;
    try {
        const response = await axios.post(route('numbers.check_availability'), {
            prefix_id: selectedPrefix.value.id,
            sub_prefix: selectedSubPrefix.value,
            suffix: suffixInput.value,
        });
        checkResult.value = response.data;
    } catch (err: any) {
        console.error('Check availability error', err);
    } finally {
        isChecking.value = false;
    }
};

const pickSuggestion = (item: SuggestionItem | any) => {
    selectedSubPrefix.value = item.sub_prefix;
    suffixInput.value = item.suffix;
    executeCheck();
};

const pickPoolNumber = (pool: any) => {
    const num = String(pool.number || '');
    if (num.length >= 9) {
        selectedSubPrefix.value = num.slice(0, 3);
        suffixInput.value = num.slice(3, 9);
        executeCheck();
    } else {
        selectedSubPrefix.value = pool.sub_prefix;
        suffixInput.value = pool.suffix;
        executeCheck();
    }
};

const loadPoolSuggestions = async () => {
    if (!selectedPrefix.value) return;
    isLoadingPool.value = true;
    try {
        const res = await axios.get(route('numbers.pool_suggestions'), {
            params: {
                prefix_id: selectedPrefix.value.id,
                sub_prefix: selectedSubPrefix.value || undefined,
            },
        });
        poolSuggestions.value = res.data.suggestions || [];
    } catch {
        poolSuggestions.value = [];
    } finally {
        isLoadingPool.value = false;
    }
};

const goToConfirmation = () => {
    if (!checkResult.value || !checkResult.value.is_available) return;
    form.phone_prefix_id = selectedPrefix.value?.id || null;
    form.preferred_number = checkResult.value.number || '';
    currentStep.value = 2;
};

const goBackToSearch = () => {
    currentStep.value = 1;
    nextTick(() => {
        suffixInputRef.value?.focus();
    });
};

const currentRequiredCost = computed(() => {
    if (checkResult.value && checkResult.value.price) {
        return Number(checkResult.value.price);
    }
    return Number(selectedPrefix.value?.price_regular || 1);
});

const hasInsufficientBalance = computed(() => userBalance.value < currentRequiredCost.value);

const fullPreviewNumber = computed(() => {
    const pfx = selectedPrefix.value?.prefix || '+85';
    const sub = selectedSubPrefix.value || '---';
    const sfx = suffixInput.value ? suffixInput.value.padEnd(6, '·') : '······';
    return `${pfx} ${sub} ${sfx.slice(0, 3)} ${sfx.slice(3)}`;
});

const submitAllocation = () => {
    if (!termsAccepted.value || form.processing || hasInsufficientBalance.value) return;

    form.post(route('numbers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            termsAccepted.value = false;
            form.reset();
            emit('success', 'خط ۹ رقمی جدید با موفقیت خریداری و به حساب شما تخصیص یافت.');
            emit('close');
        },
    });
};
</script>

<template>
    <teleport to="body">
        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 dark:bg-black/75 backdrop-blur-md"
                @click.self="handleClose"
            >
                <div
                    class="glass-card responsive-modal-scroll-card my-auto !p-5 sm:!p-6 w-full max-w-xl shadow-2xl relative space-y-5 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                >
                    <!-- Header with Wizard Title & Close -->
                    <div class="flex items-center justify-between pb-3.5 border-b border-slate-200/80 dark:border-white/10">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center text-lg shrink-0 shadow-sm">
                                📱
                            </div>
                            <div>
                                <h3 class="font-black text-base text-slate-900 dark:text-white">دریافت و رزرو خط ۹ رقمی</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    طراحی ساختار شماره، تشخیص خودکار رند و تخصیص برخط
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="handleClose"
                            class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer"
                            aria-label="بستن پنجره"
                        >
                            ✕
                        </button>
                    </div>

                    <!-- Steps Progress Indicator -->
                    <div class="flex items-center justify-between px-2 pt-1 text-xs font-bold">
                        <!-- Step 1 -->
                        <div class="flex items-center gap-2">
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 1
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : 'bg-emerald-500 text-white'"
                            >
                                <span v-if="currentStep > 1">✓</span>
                                <span v-else>۱</span>
                            </div>
                            <span :class="currentStep === 1 ? 'text-indigo-600 dark:text-indigo-400 font-black' : 'text-slate-500'">
                                ساختار و استعلام شماره
                            </span>
                        </div>

                        <div class="flex-1 mx-3 h-0.5 bg-slate-200 dark:bg-white/10"></div>

                        <!-- Step 2 -->
                        <div class="flex items-center gap-2">
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 2
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : 'bg-slate-100 dark:bg-white/10 text-slate-400'"
                            >
                                <span>۲</span>
                            </div>
                            <span :class="currentStep === 2 ? 'text-indigo-600 dark:text-indigo-400 font-black' : 'text-slate-500'">
                                تأیید نهایی و پرداخت
                            </span>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 1: Interactive Number Structure Search    -->
                    <!-- ============================================== -->
                    <div v-if="currentStep === 1" class="space-y-4">
                        <!-- Quota & Country Info Line -->
                        <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                            <span>پیش‌شماره، پیش‌کد و ۶ رقم دلخواه خود را تعیین کنید:</span>
                            <span v-if="remainingQuota !== undefined" class="font-bold text-indigo-600 dark:text-indigo-400">
                                سهمیه آزاد: {{ remainingQuota }} خط
                            </span>
                        </div>

                        <!-- MAIN UNIFIED PHONE NUMBER BUILDER (LTR) -->
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                ساختار شماره تلفن (از چپ به راست):
                            </label>

                            <div
                                dir="ltr"
                                class="flex flex-wrap sm:flex-nowrap items-center p-2 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border-2 transition-all gap-2 shadow-inner"
                                :class="suffixInput.length === 6
                                    ? (checkResult?.is_available ? 'border-emerald-500/80 dark:border-emerald-500/60' : 'border-rose-400 dark:border-rose-500/60')
                                    : 'border-slate-200/90 dark:border-slate-700/80 focus-within:border-indigo-500'"
                            >
                                <!-- 1. Country & Prefix Select -->
                                <div class="relative min-w-[130px] sm:w-auto shrink-0">
                                    <select
                                        v-model="selectedPrefixId"
                                        @change="onPrefixIdChange"
                                        class="w-full sm:w-auto h-12 pl-3 pr-8 rounded-xl bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono font-black text-xs sm:text-sm border border-slate-200 dark:border-slate-700 shadow-xs focus:border-indigo-500 outline-none cursor-pointer appearance-none"
                                        title="انتخاب پیش‌شماره کشور"
                                    >
                                        <option v-for="p in activePrefixes" :key="p.id" :value="p.id">
                                            {{ p.flag || '🌐' }} {{ p.prefix }} ({{ p.country_name || p.name }})
                                        </option>
                                    </select>
                                    <div class="pointer-events-none absolute right-2.5 top-4 text-slate-400">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>

                                <span class="hidden sm:inline text-slate-300 dark:text-slate-600 font-bold text-base select-none">/</span>

                                <!-- 2. Sub-Prefix 3-digit Select -->
                                <div class="relative min-w-[95px] sm:w-auto shrink-0">
                                    <select
                                        v-model="selectedSubPrefix"
                                        @change="onSubPrefixSelectChange"
                                        class="w-full sm:w-auto h-12 pl-3 pr-7 rounded-xl bg-indigo-50/90 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 font-mono font-black text-sm sm:text-base border border-indigo-200 dark:border-indigo-800 shadow-xs focus:border-indigo-500 outline-none cursor-pointer appearance-none"
                                        title="انتخاب پیش‌کد سه رقمی"
                                    >
                                        <option v-for="sub in subPrefixesList" :key="sub" :value="sub">
                                            {{ sub }}
                                        </option>
                                    </select>
                                    <div class="pointer-events-none absolute right-2.5 top-4 text-indigo-500">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>

                                <!-- 3. Dash Separator -->
                                <span class="text-slate-400 dark:text-slate-500 font-mono font-black text-xl select-none px-0.5">-</span>

                                <!-- 4. 6-digit Suffix Input -->
                                <div class="relative flex-1 min-w-[140px]">
                                    <input
                                        ref="suffixInputRef"
                                        v-model="suffixInput"
                                        @input="onSuffixInput"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="6"
                                        dir="ltr"
                                        placeholder="123456"
                                        class="w-full h-12 px-3.5 rounded-xl bg-white dark:bg-slate-800 font-mono font-black text-lg tracking-widest text-slate-900 dark:text-white placeholder:text-slate-300 dark:placeholder:text-slate-600 border border-slate-200 dark:border-slate-700 focus:border-indigo-500 focus:ring-0 outline-none shadow-xs"
                                        title="ورود ۶ رقم دلخواه شماره"
                                    />

                                    <!-- Status Spinner / Indicator inside input -->
                                    <div class="absolute right-3 top-3.5 flex items-center gap-1.5">
                                        <svg v-if="isChecking" class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span
                                            v-else-if="suffixInput.length === 6 && checkResult?.is_available"
                                            class="w-5 h-5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-xs font-black shadow-xs"
                                            title="شماره آزاد است"
                                        >
                                            ✓
                                        </span>
                                        <span
                                            v-else-if="suffixInput.length === 6 && checkResult && !checkResult.is_available"
                                            class="w-5 h-5 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-black shadow-xs"
                                            title="شماره واگذار شده است"
                                        >
                                            ✕
                                        </span>
                                        <span v-else-if="suffixInput.length > 0 && suffixInput.length < 6" class="text-[10px] font-mono text-slate-400 font-bold">
                                            {{ suffixInput.length }}/6
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Sub-prefix pills for instant switching -->
                        <div class="space-y-1.5">
                            <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 block">
                                انتخاب سریع پیش‌کد سه‌رقمی:
                            </span>
                            <div class="flex flex-wrap gap-1.5" dir="ltr">
                                <button
                                    v-for="sub in subPrefixesList"
                                    :key="sub"
                                    type="button"
                                    @click="pickSubPrefix(sub)"
                                    class="px-3 py-1 rounded-xl text-xs font-mono font-bold transition-all border cursor-pointer"
                                    :class="selectedSubPrefix === sub
                                        ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs'
                                        : 'bg-slate-100 dark:bg-white/5 border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300 hover:border-slate-300'"
                                >
                                    {{ sub }}
                                </button>
                            </div>
                        </div>

                        <!-- LIVE VERIFICATION RESULT DISPLAY -->
                        <div v-if="checkResult" class="space-y-3 pt-1">
                            <!-- 1. AVAILABLE BANNER -->
                            <div
                                v-if="checkResult.is_available"
                                class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 space-y-3 animate-in fade-in duration-200"
                            >
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-black text-emerald-700 dark:text-emerald-400">
                                        <span class="w-4 h-4 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]">✓</span>
                                        شماره آزاد و آماده واگذاری آنی می‌باشد
                                    </span>
                                    <span
                                        class="px-2.5 py-0.5 rounded-full text-[11px] font-black border"
                                        :class="checkResult.rond?.tier === 'golden_rond'
                                            ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30'
                                            : (checkResult.rond?.tier === 'semi_rond'
                                                ? 'bg-blue-500/15 text-blue-700 dark:text-blue-300 border-blue-500/30'
                                                : 'bg-slate-500/10 text-slate-600 dark:text-slate-400 border-slate-500/20')"
                                    >
                                        {{ checkResult.rond?.tier_label }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-1 flex-wrap gap-3">
                                    <div>
                                        <span dir="ltr" class="text-lg font-mono font-black text-slate-900 dark:text-white block">
                                            {{ checkResult.formatted }}
                                        </span>
                                        <span class="text-[11px] text-slate-500 dark:text-slate-400 block mt-0.5">
                                            الگو: {{ checkResult.rond?.pattern }}
                                        </span>
                                    </div>
                                    <div class="text-left">
                                        <span class="text-[10px] text-slate-500 block">تعرفه خط:</span>
                                        <span class="text-base font-black text-indigo-600 dark:text-indigo-400">
                                            {{ checkResult.price }} کوین (TRX)
                                        </span>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="goToConfirmation"
                                    class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black shadow-md shadow-emerald-600/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
                                >
                                    <span>ادامه و رزرو این شماره (مرحله بعد)</span>
                                    <span>←</span>
                                </button>
                            </div>

                            <!-- 2. OCCUPIED BANNER & SMART SUGGESTIONS -->
                            <div
                                v-else
                                class="space-y-3 animate-in fade-in duration-200"
                            >
                                <div class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-500/30 text-xs text-rose-700 dark:text-rose-400 flex items-center gap-2">
                                    <span class="text-base">⚠️</span>
                                    <span>شماره درخواستی ({{ fullPreviewNumber }}) قبلاً واگذار شده است. گزینه‌های آزاد پیشنهادی زیر را بررسی فرمایید:</span>
                                </div>

                                <div v-if="checkResult.suggestions && checkResult.suggestions.length > 0" class="space-y-2">
                                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 block">
                                        پیشنهاد شماره با همین ۶ رقم در سایر پیش‌کدها:
                                    </span>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto pr-1">
                                        <div
                                            v-for="(sug, idx) in checkResult.suggestions"
                                            :key="idx"
                                            @click="pickSuggestion(sug)"
                                            class="p-2.5 rounded-xl border border-slate-200/80 dark:border-white/10 bg-white dark:bg-slate-800 hover:border-indigo-500 cursor-pointer transition-all flex items-center justify-between shadow-xs"
                                        >
                                            <div>
                                                <span dir="ltr" class="font-mono text-xs font-black text-indigo-600 dark:text-indigo-400 block">
                                                    {{ sug.formatted }}
                                                </span>
                                                <span class="text-[10px] text-slate-500 block">
                                                    {{ sug.badge }}
                                                </span>
                                            </div>
                                            <div class="text-left">
                                                <span class="text-[10px] font-bold text-emerald-600">انتخاب</span>
                                                <span class="text-[9px] text-slate-400 block">{{ sug.price }} کوین</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Typing helper hint (when less than 6 digits) -->
                        <div v-else-if="suffixInput.length > 0 && suffixInput.length < 6" class="p-3 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200/60 dark:border-white/10 text-xs text-slate-500 flex items-center justify-between">
                            <span>پیش‌نمایش شماره شما:</span>
                            <span dir="ltr" class="font-mono font-black text-indigo-600 dark:text-indigo-400">
                                {{ fullPreviewNumber }}
                            </span>
                        </div>

                        <!-- Browsing Free Numbers in Pool (when search is empty) -->
                        <div v-else-if="poolSuggestions.length > 0" class="space-y-2 pt-2 border-t border-slate-200/60 dark:border-white/10">
                            <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                                <span>یا انتخاب از بین شماره‌های آماده استخر:</span>
                                <button
                                    type="button"
                                    @click="loadPoolSuggestions"
                                    class="text-indigo-600 hover:underline text-[11px] cursor-pointer"
                                >
                                    بروزرسانی استخر ↻
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2 max-h-36 overflow-y-auto pr-1">
                                <div
                                    v-for="(pool, idx) in poolSuggestions"
                                    :key="idx"
                                    @click="pickPoolNumber(pool)"
                                    class="p-2.5 rounded-xl border border-slate-200/80 dark:border-white/10 bg-slate-50/60 dark:bg-white/5 hover:border-indigo-500 cursor-pointer transition-all flex items-center justify-between text-xs"
                                >
                                    <span dir="ltr" class="font-mono font-black text-slate-800 dark:text-slate-200">
                                        {{ pool.sub_prefix }} {{ pool.suffix }}
                                    </span>
                                    <span class="text-[10px] text-indigo-600 font-bold">انتخاب</span>
                                </div>
                            </div>
                        </div>

                        <!-- Collapsible Tariff Overview for Selected Prefix -->
                        <div class="pt-1">
                            <button
                                type="button"
                                @click="showTariffsOverview = !showTariffsOverview"
                                class="text-[11px] font-bold text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 flex items-center gap-1 cursor-pointer"
                            >
                                <span>{{ showTariffsOverview ? '▲ بستن تعرفه‌های پیش‌شماره' : '▼ مشاهده تعرفه‌های خطوط ۹ رقمی ' + (selectedPrefix?.country_name || selectedPrefix?.name) }}</span>
                            </button>

                            <div v-if="showTariffsOverview && selectedPrefix" class="mt-2 p-3 rounded-2xl bg-slate-100/80 dark:bg-white/5 border border-slate-200 dark:border-white/10 grid grid-cols-3 gap-2 text-center text-xs">
                                <div class="p-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200/60 dark:border-white/10">
                                    <span class="text-[10px] text-slate-500 block">خط عادی</span>
                                    <span class="font-black text-xs text-slate-900 dark:text-white">{{ selectedPrefix.price_regular }} کوین</span>
                                </div>
                                <div class="p-2 rounded-xl bg-blue-50/60 dark:bg-blue-950/30 border border-blue-500/20">
                                    <span class="text-[10px] text-blue-600 dark:text-blue-400 block">نیمه‌رند</span>
                                    <span class="font-black text-xs text-blue-700 dark:text-blue-300">{{ selectedPrefix.price_semi_rond }} کوین</span>
                                </div>
                                <div class="p-2 rounded-xl bg-amber-50/60 dark:bg-amber-950/30 border border-amber-500/20">
                                    <span class="text-[10px] text-amber-600 dark:text-amber-400 block">طلایی</span>
                                    <span class="font-black text-xs text-amber-700 dark:text-amber-300">{{ selectedPrefix.price_golden_rond }} کوین</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1 Footer -->
                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="handleClose"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all cursor-pointer"
                            >
                                انصراف
                            </button>
                            <button
                                type="button"
                                @click="goToConfirmation"
                                :disabled="!checkResult || !checkResult.is_available"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5 cursor-pointer"
                            >
                                <span>مرحله بعد: تأیید نهایی</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 2: Review, Payment & Activation           -->
                    <!-- ============================================== -->
                    <div v-else-if="currentStep === 2" class="space-y-4">
                        <form @submit.prevent="submitAllocation" class="space-y-4">
                            <!-- Invoice Summary Card -->
                            <div class="p-4 rounded-2xl bg-indigo-50/90 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-500/20 space-y-3 text-xs">
                                <div class="flex items-center justify-between pb-2 border-b border-indigo-100 dark:border-indigo-500/15">
                                    <span class="text-slate-600 dark:text-slate-400 font-semibold">کشور و پیش‌شماره:</span>
                                    <span class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                        <span>{{ selectedPrefix?.flag }}</span>
                                        <span>{{ selectedPrefix?.country_name || selectedPrefix?.name }}</span>
                                        <span dir="ltr" class="font-mono text-indigo-600 font-black">({{ selectedPrefix?.prefix }})</span>
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pb-2 border-b border-indigo-100 dark:border-indigo-500/15">
                                    <span class="text-slate-600 dark:text-slate-400 font-semibold">شماره اختصاصی ۹ رقمی:</span>
                                    <span dir="ltr" class="font-mono font-black text-base text-indigo-600 dark:text-indigo-400">
                                        {{ checkResult?.formatted }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pb-2 border-b border-indigo-100 dark:border-indigo-500/15">
                                    <span class="text-slate-600 dark:text-slate-400 font-semibold">وضعیت رند مخابراتی:</span>
                                    <span class="font-black text-amber-600 dark:text-amber-400">
                                        {{ checkResult?.rond?.tier_label }} ({{ checkResult?.rond?.pattern }})
                                    </span>
                                </div>

                                <div class="flex items-center justify-between text-sm pt-1">
                                    <span class="text-slate-900 dark:text-white font-bold">هزینه نهایی فعال‌سازی:</span>
                                    <span class="font-black text-indigo-600 dark:text-indigo-400">
                                        {{ currentRequiredCost }} کوین (TRX)
                                    </span>
                                </div>
                            </div>

                            <!-- User Wallet Status -->
                            <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-xs space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-700 dark:text-slate-300 font-semibold">موجودی کیف پول شما:</span>
                                    <span class="font-mono font-black text-amber-600 dark:text-amber-400">
                                        {{ userBalance.toFixed(2) }} کوین (TRX)
                                    </span>
                                </div>

                                <div v-if="hasInsufficientBalance" class="pt-2 flex items-center justify-between bg-rose-500/10 p-2 rounded-xl text-rose-600 dark:text-rose-400 text-[11px] font-bold">
                                    <span>⚠️ موجودی کیف پول شما برای این سفارش کافی نمی‌باشد.</span>
                                    <Link :href="route('profile.edit')" class="underline hover:text-rose-700">
                                        شارژ حساب ←
                                    </Link>
                                </div>
                            </div>

                            <!-- Terms and Commitments -->
                            <label class="flex items-start gap-2.5 cursor-pointer text-xs text-slate-600 dark:text-slate-400 pt-1">
                                <input
                                    type="checkbox"
                                    v-model="termsAccepted"
                                    class="rounded-lg border-slate-300 dark:border-white/20 text-indigo-600 focus:ring-indigo-500 mt-0.5 cursor-pointer"
                                />
                                <span class="leading-relaxed">
                                    قوانین و مقررات استفاده از خطوط مجازی و سیاست‌های ضداسپم سامانه را مطالعه نموده و می‌پذیرم.
                                </span>
                            </label>

                            <!-- Step 2 Footer -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="goBackToSearch"
                                    :disabled="form.processing"
                                    class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all cursor-pointer"
                                >
                                    → بازگشت به جستجو
                                </button>
                                <button
                                    type="submit"
                                    :disabled="!termsAccepted || form.processing || hasInsufficientBalance"
                                    class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5 cursor-pointer"
                                >
                                    <svg v-if="form.processing" class="animate-spin h-3.5 w-3.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <span>{{ form.processing ? 'در حال فعال‌سازی خط...' : 'پرداخت و دریافت آنی خط' }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
