<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useForm, usePage, Link, router } from '@inertiajs/vue3';
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

// Wizard Step: 1 = Country, 2 = Number Search & Suggestions, 3 = Confirmation
const currentStep = ref<1 | 2 | 3>(1);

// Step 1: Selected Prefix
const selectedPrefix = ref<PrefixItem | null>(null);

// Step 2: Selected SubPrefix & 6-digit Suffix
const selectedSubPrefix = ref<string>('');
const suffixInput = ref<string>('');
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

// Step 3: Terms
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
                selectPrefix(defaultPref);
            }
            window.addEventListener('keydown', handleKeydown);
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

const selectPrefix = (prefix: PrefixItem) => {
    selectedPrefix.value = prefix;
    const subs = Array.isArray(prefix.sub_prefixes) && prefix.sub_prefixes.length > 0
        ? prefix.sub_prefixes
        : ['912', '910', '918', '935'];
    selectedSubPrefix.value = subs[0];
    checkResult.value = null;
};

const goToStep2 = () => {
    if (!selectedPrefix.value) return;
    currentStep.value = 2;
    loadPoolSuggestions();
};

const goToStep1 = () => {
    currentStep.value = 1;
};

const goToStep3 = () => {
    if (!checkResult.value || !checkResult.value.is_available) return;
    form.phone_prefix_id = selectedPrefix.value?.id || null;
    form.preferred_number = checkResult.value.number || '';
    currentStep.value = 3;
};

// Real-time verification debounce
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const onSuffixChange = () => {
    const clean = suffixInput.value.replace(/\D/g, '').slice(0, 6);
    suffixInput.value = clean;

    if (clean.length !== 6) {
        checkResult.value = null;
        return;
    }

    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        executeCheck();
    }, 350);
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

const pickSubPrefix = (sub: string) => {
    selectedSubPrefix.value = sub;
    if (suffixInput.value.length === 6) {
        executeCheck();
    }
};

const pickSuggestion = (item: SuggestionItem | any) => {
    selectedSubPrefix.value = item.sub_prefix;
    suffixInput.value = item.suffix;
    executeCheck();
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
    } catch (e) {
        // Ignore
    } finally {
        isLoadingPool.value = false;
    }
};

const currentRequiredCost = computed(() => {
    if (checkResult.value && checkResult.value.price) {
        return Number(checkResult.value.price);
    }
    return Number(selectedPrefix.value?.price_regular || 1);
});

const hasInsufficientBalance = computed(() => userBalance.value < currentRequiredCost.value);

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
                    class="glass-card responsive-modal-scroll-card my-auto !p-6 w-full max-w-xl shadow-2xl relative space-y-5 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200"
                >
                    <!-- Header with Wizard Steps Indicator -->
                    <div class="flex items-center justify-between pb-3.5 border-b border-slate-200/80 dark:border-white/10">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center text-lg shrink-0 shadow-sm">
                                📱
                            </div>
                            <div>
                                <h3 class="font-black text-base text-slate-900 dark:text-white">دریافت و رزرو خط ۹ رقمی</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    سامانه تخصیص هوشمند پیش‌شماره و تشخیص خطوط رند
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="handleClose"
                            class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors"
                            aria-label="بستن پنجره"
                        >
                            ✕
                        </button>
                    </div>

                    <!-- Steps Progress Bar -->
                    <div class="flex items-center justify-between px-2 pt-1">
                        <!-- Step 1 -->
                        <div class="flex items-center gap-2">
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 1
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : (currentStep > 1 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                            >
                                <span v-if="currentStep > 1">✓</span>
                                <span v-else>۱</span>
                            </div>
                            <span class="text-xs font-bold" :class="currentStep === 1 ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500'">
                                انتخاب کشور
                            </span>
                        </div>

                        <div class="flex-1 mx-2 h-0.5 bg-slate-200 dark:bg-white/10"></div>

                        <!-- Step 2 -->
                        <div class="flex items-center gap-2">
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 2
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : (currentStep > 2 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                            >
                                <span v-if="currentStep > 2">✓</span>
                                <span v-else>۲</span>
                            </div>
                            <span class="text-xs font-bold" :class="currentStep === 2 ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500'">
                                جستجوی ۶ رقم
                            </span>
                        </div>

                        <div class="flex-1 mx-2 h-0.5 bg-slate-200 dark:bg-white/10"></div>

                        <!-- Step 3 -->
                        <div class="flex items-center gap-2">
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 3
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : 'bg-slate-100 dark:bg-white/10 text-slate-400'"
                            >
                                ۳
                            </div>
                            <span class="text-xs font-bold" :class="currentStep === 3 ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500'">
                                تأیید و پرداخت
                            </span>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 1: Select Country & Prefix                -->
                    <!-- ============================================== -->
                    <div v-if="currentStep === 1" class="space-y-4">
                        <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                            <span>کشور و پیش‌شماره مورد نظر خود را برگزینید:</span>
                            <span v-if="remainingQuota !== undefined" class="font-bold text-indigo-600 dark:text-indigo-400">
                                سهمیه آزاد: {{ remainingQuota }} خط
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-72 overflow-y-auto pr-1">
                            <div
                                v-for="p in activePrefixes"
                                :key="p.id"
                                @click="selectPrefix(p)"
                                class="p-3.5 rounded-2xl border cursor-pointer transition-all flex items-center justify-between"
                                :class="selectedPrefix?.id === p.id
                                    ? 'bg-indigo-50/80 dark:bg-indigo-950/40 border-indigo-500 shadow-sm shadow-indigo-500/10'
                                    : 'bg-slate-50/70 dark:bg-white/5 border-slate-200/80 dark:border-white/10 hover:border-slate-300 dark:hover:border-white/20'"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl">{{ p.flag || '🌐' }}</span>
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs font-black text-slate-900 dark:text-white">
                                                {{ p.country_name || p.name }}
                                            </span>
                                            <span v-if="p.country_code" class="text-[10px] px-1.5 py-0.5 rounded bg-slate-200/60 dark:bg-white/10 text-slate-600 dark:text-slate-300 font-mono">
                                                {{ p.country_code }}
                                            </span>
                                        </div>
                                        <span dir="ltr" class="text-[11px] font-mono text-indigo-600 dark:text-indigo-400 font-bold block mt-0.5">
                                            {{ p.prefix }}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-left">
                                    <span v-if="selectedPrefix?.id === p.id" class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] font-bold">
                                        ✓
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Price Matrix Overview for Selected Prefix -->
                        <div v-if="selectedPrefix" class="p-3.5 rounded-2xl bg-slate-100/80 dark:bg-white/5 border border-slate-200 dark:border-white/10 space-y-2 text-xs">
                            <div class="flex items-center justify-between text-slate-700 dark:text-slate-300 font-semibold">
                                <span>تعرفه‌های خطوط ۹ رقمی {{ selectedPrefix.country_name || selectedPrefix.name }}:</span>
                                <span dir="ltr" class="font-mono text-indigo-600 font-bold">{{ selectedPrefix.prefix }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center pt-1">
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
                        <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="handleClose"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all"
                            >
                                انصراف
                            </button>
                            <button
                                type="button"
                                @click="goToStep2"
                                :disabled="!selectedPrefix"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                            >
                                <span>مرحله بعد: جستجوی شماره</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 2: Sub-prefix & 6-digit Search            -->
                    <!-- ============================================== -->
                    <div v-else-if="currentStep === 2" class="space-y-4">
                        <!-- Sub-prefixes selector pills -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <label class="font-bold text-slate-700 dark:text-slate-300">
                                    ۱. انتخاب پیش‌کد (۳ رقم اول):
                                </label>
                                <span class="text-[11px] text-slate-500">{{ selectedPrefix?.country_name }} ({{ selectedPrefix?.prefix }})</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="sub in (selectedPrefix?.sub_prefixes || ['912', '910', '918', '935'])"
                                    :key="sub"
                                    type="button"
                                    @click="pickSubPrefix(sub)"
                                    class="px-3.5 py-1.5 rounded-xl text-xs font-mono font-bold transition-all border"
                                    :class="selectedSubPrefix === sub
                                        ? 'bg-indigo-600 text-white border-indigo-600 shadow-md shadow-indigo-600/20'
                                        : 'bg-slate-100 dark:bg-white/5 border-slate-200 dark:border-white/10 text-slate-700 dark:text-slate-300 hover:border-slate-300'"
                                >
                                    {{ sub }}
                                </button>
                            </div>
                        </div>

                        <!-- 6-digit Suffix Input Field -->
                        <div class="space-y-1.5">
                            <label class="font-bold text-xs text-slate-700 dark:text-slate-300">
                                ۲. ورود ۶ رقم دلخواه شماره:
                            </label>
                            <div class="flex items-center gap-2">
                                <div class="px-4 py-2.5 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 border border-indigo-200 dark:border-indigo-500/20 font-mono font-black text-sm text-indigo-600 dark:text-indigo-400">
                                    {{ selectedPrefix?.prefix }} {{ selectedSubPrefix }}
                                </div>
                                <div class="relative flex-1">
                                    <input
                                        v-model="suffixInput"
                                        @input="onSuffixChange"
                                        type="text"
                                        maxlength="6"
                                        dir="ltr"
                                        placeholder="مثال: 345678"
                                        class="w-full text-center font-mono font-black tracking-widest text-lg py-2.5 px-4 rounded-2xl border bg-white dark:bg-slate-800 border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    />
                                    <div v-if="isChecking" class="absolute left-3 top-3">
                                        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                ۶ رقم عددی وارد کنید تا وضعیت آزاد بودن و رند بودن شماره بی‌درنگ استعلام شود.
                            </p>
                        </div>

                        <!-- Verification Result Display -->
                        <div v-if="checkResult" class="space-y-3 pt-1">
                            <!-- AVAILABLE -->
                            <div
                                v-if="checkResult.is_available"
                                class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-500/30 space-y-2.5 animate-in fade-in duration-200"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-black text-emerald-700 dark:text-emerald-400">
                                        <span class="w-4 h-4 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]">✓</span>
                                        شماره آزاد و آماده واگذاری است
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

                                <div class="flex items-center justify-between pt-1">
                                    <div>
                                        <span dir="ltr" class="text-base font-mono font-black text-slate-900 dark:text-white">
                                            {{ checkResult.formatted }}
                                        </span>
                                        <span class="text-[11px] text-slate-500 dark:text-slate-400 block">
                                            الگو: {{ checkResult.rond?.pattern }}
                                        </span>
                                    </div>
                                    <div class="text-left">
                                        <span class="text-[10px] text-slate-500 block">تعرفه شماره:</span>
                                        <span class="text-sm font-black text-indigo-600 dark:text-indigo-400">
                                            {{ checkResult.price }} کوین
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- OCCUPIED / SUGGESTIONS -->
                            <div
                                v-else
                                class="space-y-3 animate-in fade-in duration-200"
                            >
                                <div class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-500/30 text-xs text-rose-700 dark:text-rose-400 flex items-center gap-2">
                                    <span>⚠️</span>
                                    <span>شماره انتخابی در حال حاضر واگذار شده است. می‌توانید یکی از گزینه‌های مشابه زیر را برگزینید:</span>
                                </div>

                                <div v-if="checkResult.suggestions && checkResult.suggestions.length > 0" class="space-y-2">
                                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 block">
                                        شماره‌های پیشنهادی سیستم:
                                    </span>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-44 overflow-y-auto pr-1">
                                        <div
                                            v-for="(sug, idx) in checkResult.suggestions"
                                            :key="idx"
                                            @click="pickSuggestion(sug)"
                                            class="p-2.5 rounded-xl border border-slate-200/80 dark:border-white/10 bg-white dark:bg-slate-800 hover:border-indigo-500 cursor-pointer transition-all flex items-center justify-between"
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

                        <!-- Browsing Free Numbers in Pool (when search is empty) -->
                        <div v-else-if="poolSuggestions.length > 0" class="space-y-2 pt-2 border-t border-slate-200/60 dark:border-white/10">
                            <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                                <span>پیشنهاد شماره‌های آزاد موجود در استخر:</span>
                                <button
                                    type="button"
                                    @click="loadPoolSuggestions"
                                    class="text-indigo-600 hover:underline text-[11px]"
                                >
                                    بروزرسانی لیست ↻
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2 max-h-36 overflow-y-auto pr-1">
                                <div
                                    v-for="(pool, idx) in poolSuggestions"
                                    :key="idx"
                                    @click="pickSuggestion(pool)"
                                    class="p-2 rounded-xl border border-slate-200/80 dark:border-white/10 bg-slate-50/60 dark:bg-white/5 hover:border-indigo-500 cursor-pointer transition-all flex items-center justify-between text-xs"
                                >
                                    <span dir="ltr" class="font-mono font-black text-slate-800 dark:text-slate-200">
                                        {{ pool.sub_prefix }} {{ pool.suffix }}
                                    </span>
                                    <span class="text-[10px] text-indigo-600 font-bold">انتخاب</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2 Footer -->
                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="goToStep1"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all"
                            >
                                → تغییر کشور
                            </button>
                            <button
                                type="button"
                                @click="goToStep3"
                                :disabled="!checkResult || !checkResult.is_available"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                            >
                                <span>مرحله بعد: تأیید نهایی</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 3: Review & Instant Allocation            -->
                    <!-- ============================================== -->
                    <div v-else-if="currentStep === 3" class="space-y-4">
                        <form @submit.prevent="submitAllocation" class="space-y-4">
                            <!-- Invoice Summary Card -->
                            <div class="p-4 rounded-2xl bg-indigo-50/90 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-500/20 space-y-3 text-xs">
                                <div class="flex items-center justify-between pb-2 border-b border-indigo-100 dark:border-indigo-500/15">
                                    <span class="text-slate-600 dark:text-slate-400 font-semibold">کشور و پیش‌شماره:</span>
                                    <span class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                        <span>{{ selectedPrefix?.flag }}</span>
                                        <span>{{ selectedPrefix?.country_name }}</span>
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
                                        {{ userBalance.toFixed(2) }} کوین
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
                                    class="rounded-lg border-slate-300 dark:border-white/20 text-indigo-600 focus:ring-indigo-500 mt-0.5"
                                />
                                <span class="leading-relaxed">
                                    قوانین و مقررات استفاده از خطوط مجازی و سیاست‌های ضداسپم سامانه را مطالعه نموده و می‌پذیرم.
                                </span>
                            </label>

                            <!-- Step 3 Footer -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="goToStep2"
                                    :disabled="form.processing"
                                    class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all"
                                >
                                    → بازگشت به جستجو
                                </button>
                                <button
                                    type="submit"
                                    :disabled="!termsAccepted || form.processing || hasInsufficientBalance"
                                    class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                                >
                                    <svg v-if="form.processing" class="animate-spin h-3.5 w-3.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <span>{{ form.processing ? 'در حال ثبت و تخصیص خط...' : 'پرداخت و دریافت آنی خط' }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
