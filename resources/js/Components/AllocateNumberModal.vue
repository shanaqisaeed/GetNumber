<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { __ } from '@/Composables/useTrans';

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

// Wizard Step: 1 = Country & Prefix, 2 = Search & Auto-complete, 3 = Confirmation & Deep Rond Analysis
const currentStep = ref<1 | 2 | 3>(1);

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

// Step 2: Selected SubPrefix & 6-digit Suffix
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

// Random pool suggestions for auto-completion & browsing
const poolSuggestions = ref<any[]>([]);
const isLoadingPool = ref(false);

// Step 3: Terms & Form
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
                selectedSubPrefix.value = defaultPref.sub_prefixes?.[0] || '912';
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

// Navigation between steps
const selectPrefixAndProceed = (pref: PrefixItem) => {
    selectedPrefixId.value = pref.id;
    selectedSubPrefix.value = pref.sub_prefixes?.[0] || '912';
    goToStep2();
};

const goToStep1 = () => {
    currentStep.value = 1;
};

const goToStep2 = () => {
    if (!selectedPrefix.value) return;
    currentStep.value = 2;
    loadPoolSuggestions();
    nextTick(() => {
        suffixInputRef.value?.focus();
    });
};

const goToStep3 = () => {
    if (!checkResult.value || !checkResult.value.is_available) return;
    form.phone_prefix_id = selectedPrefix.value?.id || null;
    form.preferred_number = checkResult.value.number || '';
    currentStep.value = 3;
};

const onSubPrefixSelectChange = () => {
    loadPoolSuggestions(suffixInput.value);
    if (suffixInput.value.length === 6) {
        executeCheck();
    }
};

// Real-time verification & pool search debounces
let debounceCheckTimer: ReturnType<typeof setTimeout> | null = null;
let debouncePoolTimer: ReturnType<typeof setTimeout> | null = null;

const onSuffixInput = () => {
    const clean = suffixInput.value.replace(/\D/g, '').slice(0, 6);
    suffixInput.value = clean;

    if (clean.length < 6) {
        checkResult.value = null;
        if (debouncePoolTimer) clearTimeout(debouncePoolTimer);
        debouncePoolTimer = setTimeout(() => {
            loadPoolSuggestions(clean);
        }, 150);
        return;
    }

    if (debounceCheckTimer) clearTimeout(debounceCheckTimer);
    debounceCheckTimer = setTimeout(() => {
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

const loadPoolSuggestions = async (suffixPrefix: string = '') => {
    if (!selectedPrefix.value) return;
    isLoadingPool.value = true;
    try {
        const res = await axios.get(route('numbers.pool_suggestions'), {
            params: {
                prefix_id: selectedPrefix.value.id,
                sub_prefix: selectedSubPrefix.value || undefined,
                suffix_prefix: suffixPrefix || undefined,
            },
        });
        poolSuggestions.value = res.data.suggestions || [];
    } catch {
        poolSuggestions.value = [];
    } finally {
        isLoadingPool.value = false;
    }
};

// Auto-complete or pick a pool number
const applyAutoCompletion = (completedSuffix: string) => {
    suffixInput.value = completedSuffix;
    executeCheck();
};

const pickSuggestion = (item: SuggestionItem | any) => {
    selectedSubPrefix.value = item.sub_prefix;
    suffixInput.value = item.suffix;
    executeCheck();
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

// Comprehensive analysis narrative for Step 3
const rondAnalysisDetails = computed(() => {
    const tier = checkResult.value?.rond?.tier || 'regular';
    const pattern = checkResult.value?.rond?.pattern || __('ui.components.allocate_number_modal.pattern_regular');

    if (tier === 'golden_rond') {
        return {
            title: __('ui.components.allocate_number_modal.tier_golden_title'),
            badgeClass: 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/40',
            icon: '👑',
            summary: __('ui.components.allocate_number_modal.tier_golden_summary'),
            explanation: __('ui.components.allocate_number_modal.tier_golden_desc', { pattern }),
            benefits: [
                __('ui.components.allocate_number_modal.benefit_golden_1'),
                __('ui.components.allocate_number_modal.benefit_golden_2'),
                __('ui.components.allocate_number_modal.benefit_golden_3'),
            ],
        };
    }

    if (tier === 'semi_rond') {
        return {
            title: __('ui.components.allocate_number_modal.tier_semi_title'),
            badgeClass: 'bg-blue-500/15 text-blue-700 dark:text-blue-300 border-blue-500/40',
            icon: '💎',
            summary: __('ui.components.allocate_number_modal.tier_semi_summary'),
            explanation: __('ui.components.allocate_number_modal.tier_semi_desc', { pattern }),
            benefits: [
                __('ui.components.allocate_number_modal.benefit_semi_1'),
                __('ui.components.allocate_number_modal.benefit_semi_2'),
                __('ui.components.allocate_number_modal.benefit_semi_3'),
            ],
        };
    }

    return {
        title: __('ui.components.allocate_number_modal.tier_regular_title'),
        badgeClass: 'bg-slate-500/15 text-slate-700 dark:text-slate-300 border-slate-500/30',
        icon: '📱',
        summary: __('ui.components.allocate_number_modal.tier_regular_summary'),
        explanation: __('ui.components.allocate_number_modal.tier_regular_desc'),
        benefits: [
            __('ui.components.allocate_number_modal.benefit_reg_1'),
            __('ui.components.allocate_number_modal.benefit_reg_2'),
            __('ui.components.allocate_number_modal.benefit_reg_3'),
        ],
    };
});

const submitAllocation = () => {
    if (!termsAccepted.value || form.processing || hasInsufficientBalance.value) return;

    form.post(route('numbers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            termsAccepted.value = false;
            form.reset();
            emit('success', __('ui.components.allocate_number_modal.success_message'));
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
                    <!-- Header with Title & Close -->
                    <div class="flex items-center justify-between pb-3.5 border-b border-slate-200/80 dark:border-white/10">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center text-lg shrink-0 shadow-sm">
                                📱
                            </div>
                            <div>
                                <h3 class="font-black text-base text-slate-900 dark:text-white">{{ __("ui.components.allocate_number_modal.title") }}</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ __("ui.components.allocate_number_modal.subtitle") }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="handleClose"
                            class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer"
                            :aria-label="__('ui.components.allocate_number_modal.close_aria')"
                        >
                            ✕
                        </button>
                    </div>

                    <!-- 3-Step Progress Indicator -->
                    <div class="flex items-center justify-between px-2 pt-1 text-xs font-bold">
                        <!-- Step 1 -->
                        <div
                            @click="currentStep > 1 && (currentStep = 1)"
                            class="flex items-center gap-2"
                            :class="currentStep > 1 ? 'cursor-pointer' : ''"
                        >
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 1
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : (currentStep > 1 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                            >
                                <span v-if="currentStep > 1">✓</span>
                                <span v-else>1</span>
                            </div>
                            <span :class="currentStep === 1 ? 'text-indigo-600 dark:text-indigo-400 font-black' : (currentStep > 1 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">
                                {{ __('ui.components.allocate_number_modal.step_1') }}
                            </span>
                        </div>

                        <div class="flex-1 mx-2 h-0.5 bg-slate-200 dark:bg-white/10"></div>

                        <!-- Step 2 -->
                        <div
                            @click="currentStep === 3 && (currentStep = 2)"
                            class="flex items-center gap-2"
                            :class="currentStep === 3 ? 'cursor-pointer' : ''"
                        >
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center text-xs font-black transition-all"
                                :class="currentStep === 2
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : (currentStep > 2 ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-white/10 text-slate-400')"
                            >
                                <span v-if="currentStep > 2">✓</span>
                                <span v-else>2</span>
                            </div>
                            <span :class="currentStep === 2 ? 'text-indigo-600 dark:text-indigo-400 font-black' : (currentStep > 2 ? 'text-slate-700 dark:text-slate-300' : 'text-slate-400')">
                                {{ __('ui.components.allocate_number_modal.step_2') }}
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
                                <span>3</span>
                            </div>
                            <span :class="currentStep === 3 ? 'text-indigo-600 dark:text-indigo-400 font-black' : 'text-slate-400'">
                                {{ __('ui.components.allocate_number_modal.step_3') }}
                            </span>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 1: Select Country & Phone Prefix          -->
                    <!-- ============================================== -->
                    <div v-if="currentStep === 1" class="space-y-4">
                        <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                            <span>{{ __("ui.components.allocate_number_modal.step_1_desc") }}</span>
                            <span v-if="remainingQuota !== undefined" class="font-bold text-indigo-600 dark:text-indigo-400">
                                {{ __("ui.components.allocate_number_modal.free_quota", { count: remainingQuota }) }}
                            </span>
                        </div>

                        <!-- List / Grid of Prefixes -->
                        <div class="grid grid-cols-1 gap-2.5 max-h-72 overflow-y-auto pr-1">
                            <div
                                v-for="p in activePrefixes"
                                :key="p.id"
                                @click="selectedPrefixId = p.id"
                                class="p-3.5 rounded-2xl border-2 transition-all cursor-pointer flex items-center justify-between shadow-xs"
                                :class="selectedPrefixId === p.id
                                    ? 'border-indigo-600 bg-indigo-50/60 dark:bg-indigo-950/30'
                                    : 'border-slate-200/90 dark:border-white/10 bg-slate-50/50 dark:bg-white/5 hover:border-indigo-400'"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl">{{ p.flag || '🌐' }}</span>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-black text-sm text-slate-900 dark:text-white">
                                                {{ p.country_name || p.name }}
                                            </span>
                                            <span dir="ltr" class="font-mono font-black text-xs text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-lg border border-indigo-500/20">
                                                {{ p.prefix }}
                                            </span>
                                            <span v-if="p.is_default" class="text-[10px] bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 font-bold px-1.5 py-0.5 rounded-md">
                                                {{ __('ui.components.allocate_number_modal.default_badge') }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-2 mt-1.5 text-[11px] text-slate-500">
                                            <span>{{ __("ui.components.allocate_number_modal.tariffs") }}</span>
                                            <span class="text-slate-700 dark:text-slate-300 font-bold">{{ __("ui.components.allocate_number_modal.regular_price", { price: p.price_regular }) }}</span>
                                            <span>•</span>
                                            <span class="text-blue-600 dark:text-blue-400 font-bold">{{ __("ui.components.allocate_number_modal.semi_rond_price", { price: p.price_semi_rond }) }}</span>
                                            <span>•</span>
                                            <span class="text-amber-600 dark:text-amber-400 font-bold">{{ __("ui.components.allocate_number_modal.golden_rond_price", { price: p.price_golden_rond }) }}</span>
                                            <span>{{ __("ui.components.allocate_number_modal.coin_unit") }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center transition-all"
                                    :class="selectedPrefixId === p.id
                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                        : 'border-slate-300 dark:border-white/20'"
                                >
                                    <span v-if="selectedPrefixId === p.id" class="text-xs font-bold">✓</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1 Footer -->
                        <div class="flex items-center justify-between pt-3 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="handleClose"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all cursor-pointer"
                            >
                                {{ __('ui.components.allocate_number_modal.cancel') }}
                            </button>
                            <button
                                type="button"
                                @click="goToStep2"
                                :disabled="!selectedPrefix"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5 cursor-pointer"
                            >
                                <span>{{ __("ui.components.allocate_number_modal.step_next_search") }}</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 2: Phone Structure, Search & Auto-complete-->
                    <!-- ============================================== -->
                    <div v-else-if="currentStep === 2" class="space-y-4">
                        <!-- Top Bar with Back Button & Country Pill -->
                        <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-white/5">
                            <button
                                type="button"
                                @click="goToStep1"
                                class="inline-flex items-center gap-1 text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer"
                            >
                                <span>→</span>
                                <span>{{ __("ui.components.allocate_number_modal.change_country") }}</span>
                            </button>
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-xl bg-indigo-50/80 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800">
                                <span>{{ selectedPrefix?.flag }}</span>
                                <span>{{ selectedPrefix?.country_name || selectedPrefix?.name }}</span>
                                <span dir="ltr" class="font-mono">({{ selectedPrefix?.prefix }})</span>
                            </span>
                        </div>

                        <!-- MAIN UNIFIED PHONE NUMBER BUILDER (LTR) -->
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                {{ __("ui.components.allocate_number_modal.structure_title") }}
                            </label>

                            <div
                                dir="ltr"
                                class="flex items-center p-2 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border-2 transition-all gap-2 shadow-inner"
                                :class="suffixInput.length === 6
                                    ? (checkResult?.is_available ? 'border-emerald-500/80 dark:border-emerald-500/60' : 'border-rose-400 dark:border-rose-500/60')
                                    : 'border-slate-200/90 dark:border-slate-700/80 focus-within:border-indigo-500'"
                            >
                                <!-- 1. Fixed Country Prefix Pill -->
                                <div class="h-12 px-3 rounded-xl bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono font-black text-sm flex items-center gap-1.5 border border-slate-200 dark:border-slate-700 shrink-0 shadow-xs">
                                    <span>{{ selectedPrefix?.flag }}</span>
                                    <span>{{ selectedPrefix?.prefix }}</span>
                                </div>

                                <span class="text-slate-300 dark:text-slate-600 font-bold text-base select-none">/</span>

                                <!-- 2. Sub-Prefix 3-digit Select -->
                                <div class="relative min-w-[90px] shrink-0">
                                    <select
                                        v-model="selectedSubPrefix"
                                        @change="onSubPrefixSelectChange"
                                        class="w-full h-12 pl-3 pr-7 rounded-xl bg-indigo-50/90 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 font-mono font-black text-sm border border-indigo-200 dark:border-indigo-800 shadow-xs focus:border-indigo-500 outline-none cursor-pointer appearance-none"
                                        :title="__('ui.components.allocate_number_modal.subprefix_title')"
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
                                        :title="__('ui.components.allocate_number_modal.suffix_title')"
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
                                            :title="__('ui.components.allocate_number_modal.number_available_title')"
                                        >
                                            ✓
                                        </span>
                                        <span
                                            v-else-if="suffixInput.length === 6 && checkResult && !checkResult.is_available"
                                            class="w-5 h-5 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-black shadow-xs"
                                            :title="__('ui.components.allocate_number_modal.number_taken_title')"
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

                        <!-- LIVE AVAILABILITY STATUS (WHEN 6 DIGITS ENTERED) -->
                        <div v-if="suffixInput.length === 6 && checkResult">
                            <!-- 1. SUCCESS CARD -->
                            <div
                                v-if="checkResult.is_available"
                                class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 space-y-3 animate-in fade-in duration-200"
                            >
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-black text-emerald-700 dark:text-emerald-400">
                                        <span class="w-4 h-4 rounded-full bg-emerald-500 text-white flex items-center justify-center text-[10px]">✓</span>
                                        {{ __("ui.components.allocate_number_modal.number_ready_desc") }}
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
                                    </div>
                                    <div class="text-left">
                                        <span class="text-[10px] text-slate-500 block">{{ __("ui.components.allocate_number_modal.line_tariff") }}</span>
                                        <span class="text-base font-black text-indigo-600 dark:text-indigo-400">
                                            {{ checkResult.price }} {{ __("ui.components.allocate_number_modal.coin_trx") }}
                                        </span>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click="goToStep3"
                                    class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black shadow-md shadow-emerald-600/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
                                >
                                    <span>{{ __("ui.components.allocate_number_modal.step_next_confirm") }}</span>
                                    <span>←</span>
                                </button>
                            </div>

                            <!-- 2. OCCUPIED CARD & SMART ALTERNATIVES -->
                            <div v-else class="space-y-3 animate-in fade-in duration-200">
                                <div class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-500/30 text-xs text-rose-700 dark:text-rose-400 flex items-center gap-2">
                                    <span class="text-base">⚠️</span>
                                    <span>{{ __("ui.components.allocate_number_modal.taken_notice", { number: fullPreviewNumber }) }}</span>
                                </div>

                                <div v-if="checkResult.suggestions && checkResult.suggestions.length > 0" class="space-y-2">
                                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 block">
                                        {{ __("ui.components.allocate_number_modal.alt_subprefixes") }}
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
                                                <span class="text-[10px] font-bold text-emerald-600">{{ __("ui.components.allocate_number_modal.select") }}</span>
                                                <span class="text-[9px] text-slate-400 block">{{ sug.price }} {{ __("ui.components.allocate_number_modal.coin_unit") }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- AUTO-COMPLETION & READY POOL NUMBERS (WHEN LESS THAN 6 DIGITS) -->
                        <div v-if="suffixInput.length < 6" class="space-y-2.5 pt-1">
                            <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                                <span class="font-bold flex items-center gap-1.5">
                                    <span>⚡</span>
                                    <span v-if="suffixInput.length > 0">
                                        {{ __("ui.components.allocate_number_modal.sug_with_digits", { digits: suffixInput }) }}
                                    </span>
                                    <span v-else>
                                        {{ __("ui.components.allocate_number_modal.pool_selection") }}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    @click="loadPoolSuggestions(suffixInput)"
                                    class="text-indigo-600 hover:underline text-[11px] cursor-pointer flex items-center gap-1"
                                >
                                    <span>{{ __("ui.components.allocate_number_modal.refresh") }}</span>
                                    <span :class="isLoadingPool ? 'animate-spin' : ''">↻</span>
                                </button>
                            </div>

                            <!-- Auto-completion Grid -->
                            <div v-if="poolSuggestions.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-52 overflow-y-auto pr-1">
                                <div
                                    v-for="(pool, idx) in poolSuggestions"
                                    :key="idx"
                                    @click="applyAutoCompletion(pool.suffix)"
                                    class="p-2.5 rounded-2xl border border-slate-200/90 dark:border-white/10 bg-slate-50/70 dark:bg-white/5 hover:border-indigo-500 hover:bg-indigo-50/40 dark:hover:bg-indigo-950/20 cursor-pointer transition-all flex items-center justify-between shadow-xs group"
                                >
                                    <div class="space-y-0.5">
                                        <!-- Number with highlighted typed part and completion tail -->
                                        <div dir="ltr" class="font-mono text-xs font-black flex items-center gap-1">
                                            <span class="text-slate-400 dark:text-slate-500 font-bold">
                                                {{ pool.sub_prefix }}
                                            </span>
                                            <span class="text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-1 rounded">
                                                {{ pool.suffix.slice(0, suffixInput.length) }}
                                            </span>
                                            <span class="text-slate-800 dark:text-slate-200 underline decoration-indigo-400/50 decoration-2 underline-offset-4">
                                                {{ pool.suffix.slice(suffixInput.length) }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-2 text-[10px] text-slate-500">
                                            <span>{{ pool.rond?.tier_label }}</span>
                                            <span>•</span>
                                            <span class="font-bold text-slate-700 dark:text-slate-300">{{ pool.price }} {{ __("ui.components.allocate_number_modal.coin_unit") }}</span>
                                        </div>
                                    </div>

                                    <div class="text-left shrink-0">
                                        <span class="px-2 py-1 rounded-xl bg-indigo-600/10 text-indigo-600 dark:text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-all text-[11px] font-bold inline-flex items-center gap-1">
                                            <span>{{ __("ui.components.allocate_number_modal.complete") }}</span>
                                            <span>↵</span>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="!isLoadingPool" class="p-3 rounded-2xl bg-slate-100/70 dark:bg-white/5 text-center text-xs text-slate-500">
                                {{ __("ui.components.allocate_number_modal.not_found") }}
                            </div>
                        </div>

                        <!-- Step 2 Footer -->
                        <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="goToStep1"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all cursor-pointer"
                            >
                                {{ __("ui.components.allocate_number_modal.step_prev_country") }}
                            </button>
                            <button
                                type="button"
                                @click="goToStep3"
                                :disabled="!checkResult || !checkResult.is_available"
                                class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-lg shadow-indigo-600/25 transition-all disabled:opacity-50 inline-flex items-center gap-1.5 cursor-pointer"
                            >
                                <span>{{ __("ui.components.allocate_number_modal.step_next_review") }}</span>
                                <span>←</span>
                            </button>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- STEP 3: Review, Deep Rond Analysis & Payment   -->
                    <!-- ============================================== -->
                    <div v-else-if="currentStep === 3" class="space-y-4">
                        <form @submit.prevent="submitAllocation" class="space-y-4">
                            <!-- Selected Number Showcase Banner -->
                            <div class="p-4 rounded-3xl bg-linear-to-br from-indigo-500/10 via-purple-500/10 to-transparent border border-indigo-500/25 text-center space-y-1.5">
                                <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400">
                                    {{ __("ui.components.allocate_number_modal.selected_number_title") }}
                                </span>
                                <div dir="ltr" class="text-2xl sm:text-3xl font-mono font-black text-indigo-600 dark:text-indigo-400 tracking-wider">
                                    {{ checkResult?.formatted }}
                                </div>
                                <div class="flex items-center justify-center gap-2 flex-wrap text-xs text-slate-600 dark:text-slate-400 pt-0.5">
                                    <span>{{ __("ui.components.allocate_number_modal.country_label", { flag: selectedPrefix?.flag || "", name: selectedPrefix?.country_name || selectedPrefix?.name || "" }) }}</span>
                                    <span>•</span>
                                    <span
                                        class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border"
                                        :class="rondAnalysisDetails.badgeClass"
                                    >
                                        {{ checkResult?.rond?.tier_label }}
                                    </span>
                                </div>
                            </div>

                            <!-- Financial Invoice & Wallet Balance -->
                            <div class="p-4 rounded-2xl bg-indigo-50/80 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-500/20 space-y-2.5 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-600 dark:text-slate-400 font-semibold">{{ __("ui.components.allocate_number_modal.activation_cost") }}</span>
                                    <span class="text-base font-black text-indigo-600 dark:text-indigo-400">
                                        {{ currentRequiredCost }} {{ __("ui.components.allocate_number_modal.coin_trx") }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-2 border-t border-indigo-200/60 dark:border-indigo-500/15">
                                    <span class="text-slate-600 dark:text-slate-400">{{ __("ui.components.allocate_number_modal.wallet_balance") }}</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        {{ userBalance.toFixed(2) }} {{ __("ui.components.allocate_number_modal.coin_unit") }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between pt-1 border-t border-indigo-200/60 dark:border-indigo-500/15">
                                    <span class="text-slate-600 dark:text-slate-400">{{ __("ui.components.allocate_number_modal.remaining_balance") }}</span>
                                    <span
                                        dir="ltr"
                                        class="font-mono font-bold"
                                        :class="hasInsufficientBalance ? 'text-rose-600' : 'text-emerald-600'"
                                    >
                                        {{ (userBalance - currentRequiredCost).toFixed(2) }} {{ __("ui.components.allocate_number_modal.coin_unit") }}
                                    </span>
                                </div>

                                <div v-if="hasInsufficientBalance" class="pt-2 flex items-center justify-between bg-rose-500/10 p-2.5 rounded-xl text-rose-600 dark:text-rose-400 text-[11px] font-bold">
                                    <span>{{ __("ui.components.allocate_number_modal.insufficient_balance") }}</span>
                                    <Link :href="route('profile.edit')" class="underline hover:text-rose-700">
                                        {{ __("ui.components.allocate_number_modal.recharge_wallet") }}
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
                                <span class="leading-relaxed select-none">
                                    {{ __("ui.components.allocate_number_modal.terms_agreement") }}
                                </span>
                            </label>

                            <!-- Step 3 Footer -->
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/10">
                                <button
                                    type="button"
                                    @click="goToStep2"
                                    :disabled="form.processing"
                                    class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition-all cursor-pointer"
                                >
                                    {{ __("ui.components.allocate_number_modal.back_to_search") }}
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
                                    <span>{{ form.processing ? __('ui.components.allocate_number_modal.activating') : __('ui.components.allocate_number_modal.pay_and_activate') }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
