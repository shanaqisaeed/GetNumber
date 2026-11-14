<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

interface PrefixItem {
    id: number;
    prefix: string;
    clean_prefix?: string;
    name?: string;
    country_name?: string;
    flag?: string;
    is_default?: boolean;
}

interface Props {
    isOpen: boolean;
    walletBalance?: string | number;
    activePrefixes?: PrefixItem[];
}

const props = withDefaults(defineProps<Props>(), {
    walletBalance: 0,
    activePrefixes: () => [],
});

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'orderPlaced', orderNumber: string): void;
}>();

// Exactly 3 Steps: 1 (Select Service) -> 2 (Configure & Live Validate) -> 3 (Terms & Payment)
const currentStep = ref<1 | 2 | 3>(1);

type ServiceType = 'short_line' | 'custom_id' | 'web3_domain';
const selectedService = ref<ServiceType | null>(null);

// Service 1: Short Lines state
const selectedPrefixId = ref<number | null>(null);
const shortLineLength = ref<number>(4);
const requestedLineNumber = ref<string>('');
const shortLineValidation = ref<{
    loading: boolean;
    valid: boolean;
    available?: boolean;
    message?: string;
    formatted_number?: string;
    price?: string;
    is_rond?: boolean;
    rond_tier_label?: string;
    suggestions?: Array<{ number: string; formatted_number?: string; price?: string }>;
}>({ loading: false, valid: false });

// Service 2: Custom IDs state
const requestedIdHandle = ref<string>('');
const idValidation = ref<{
    loading: boolean;
    valid: boolean;
    available?: boolean;
    message?: string;
    rarity_tier?: string;
    rarity_label?: string;
    rarity_badge?: string;
    price?: string;
}>({ loading: false, valid: false });

// Service 3: Web3 Domains state
const requestedDomainName = ref<string>('');
const selectedTld = ref<string>('web3');
const availableTlds = ref<Array<{ id: number; tld: string; base_price: string }>>([
    { id: 1, tld: 'web3', base_price: '50.0000' },
    { id: 2, tld: 'id', base_price: '40.0000' },
    { id: 3, tld: 'eth', base_price: '100.0000' },
]);
const domainValidation = ref<{
    loading: boolean;
    valid: boolean;
    available?: boolean;
    message?: string;
    full_domain?: string;
    rarity_tier?: string;
    rarity_label?: string;
    price?: string;
}>({ loading: false, valid: false });

// Step 3: Terms & Payment
const acceptedTerms = ref(false);
const isSubmitting = ref(false);
const orderError = ref('');

// Sync default prefix
watch(() => props.activePrefixes, (prefixes) => {
    if (prefixes && prefixes.length > 0 && !selectedPrefixId.value) {
        const def = prefixes.find(p => p.is_default) || prefixes[0];
        selectedPrefixId.value = def.id;
    }
}, { immediate: true });

// Computed Final Price & Identifier
const calculatedPrice = computed(() => {
    if (selectedService.value === 'short_line') return shortLineValidation.value.price || '0.0000';
    if (selectedService.value === 'custom_id') return idValidation.value.price || '0.0000';
    if (selectedService.value === 'web3_domain') return domainValidation.value.price || '0.0000';
    return '0.0000';
});

const finalIdentifier = computed(() => {
    if (selectedService.value === 'short_line') {
        const prefixObj = props.activePrefixes.find(p => p.id === selectedPrefixId.value);
        const prefixStr = prefixObj ? prefixObj.prefix : '';
        return requestedLineNumber.value
            ? (prefixStr ? `${prefixStr} ${requestedLineNumber.value}` : requestedLineNumber.value)
            : `${shortLineLength.value} رقمی هوشمند`;
    }
    if (selectedService.value === 'custom_id') {
        return requestedIdHandle.value.startsWith('@') ? requestedIdHandle.value : `@${requestedIdHandle.value}`;
    }
    if (selectedService.value === 'web3_domain') {
        return `${requestedDomainName.value}.${selectedTld.value}`;
    }
    return '';
});

const isBalanceSufficient = computed(() => {
    const balance = Number(props.walletBalance) || 0;
    const price = Number(calculatedPrice.value) || 0;
    return balance >= price;
});

// Live AJAX Validation Handlers
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const validateShortLineLive = () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    shortLineValidation.value.loading = true;
    debounceTimer = setTimeout(async () => {
        try {
            const res = await axios.post(route('api.validate.short_line'), {
                length: shortLineLength.value,
                number: requestedLineNumber.value.trim() || undefined,
                prefix_id: selectedPrefixId.value || undefined,
            });
            shortLineValidation.value = {
                loading: false,
                valid: Boolean(res.data.available || res.data.is_available),
                available: Boolean(res.data.available || res.data.is_available),
                message: res.data.message,
                formatted_number: res.data.formatted_number,
                price: res.data.final_price || res.data.base_price,
                is_rond: res.data.is_rond,
                rond_tier_label: res.data.rond_tier_label,
                suggestions: res.data.suggestions || [],
            };
        } catch (err: any) {
            const data = err.response?.data;
            shortLineValidation.value = {
                loading: false,
                valid: false,
                available: false,
                message: data?.message || 'خطا در اعتبارسنجی سرشماره',
                suggestions: data?.suggestions || [],
            };
        }
    }, 250);
};

const pickSuggestion = (num: string) => {
    requestedLineNumber.value = num;
    validateShortLineLive();
};

const validateCustomIdLive = () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    if (!requestedIdHandle.value.trim()) {
        idValidation.value = { loading: false, valid: false };
        return;
    }
    idValidation.value.loading = true;
    debounceTimer = setTimeout(async () => {
        try {
            const res = await axios.post(route('api.validate.custom_id'), {
                handle: requestedIdHandle.value.trim(),
            });
            idValidation.value = {
                loading: false,
                valid: Boolean(res.data.available),
                available: Boolean(res.data.available),
                message: res.data.message,
                rarity_tier: res.data.rarity_tier,
                rarity_label: res.data.rarity_label,
                rarity_badge: res.data.rarity_badge,
                price: res.data.price,
            };
        } catch (err: any) {
            idValidation.value = {
                loading: false,
                valid: false,
                available: false,
                message: err.response?.data?.message || 'خطا در اعتبارسنجی آیدی',
            };
        }
    }, 250);
};

const validateWeb3DomainLive = () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    if (!requestedDomainName.value.trim()) {
        domainValidation.value = { loading: false, valid: false };
        return;
    }
    domainValidation.value.loading = true;
    debounceTimer = setTimeout(async () => {
        try {
            const res = await axios.post(route('api.validate.web3_domain'), {
                domain_name: requestedDomainName.value.trim(),
                tld: selectedTld.value,
            });
            domainValidation.value = {
                loading: false,
                valid: Boolean(res.data.available),
                available: Boolean(res.data.available),
                message: res.data.message,
                full_domain: res.data.full_domain,
                rarity_tier: res.data.rarity_tier,
                rarity_label: res.data.rarity_label,
                price: res.data.price,
            };
            if (res.data.tlds && res.data.tlds.length) {
                availableTlds.value = res.data.tlds;
            }
        } catch (err: any) {
            domainValidation.value = {
                loading: false,
                valid: false,
                available: false,
                message: err.response?.data?.message || 'خطا در اعتبارسنجی دامنه',
            };
        }
    }, 250);
};

// Flow Controls
const selectService = (srv: ServiceType) => {
    selectedService.value = srv;
    currentStep.value = 2;
    if (srv === 'short_line') validateShortLineLive();
    if (srv === 'custom_id' && requestedIdHandle.value) validateCustomIdLive();
    if (srv === 'web3_domain' && requestedDomainName.value) validateWeb3DomainLive();
};

const canProceedToStep3 = computed(() => {
    if (selectedService.value === 'short_line') return shortLineValidation.value.valid && !shortLineValidation.value.loading;
    if (selectedService.value === 'custom_id') return idValidation.value.valid && !idValidation.value.loading;
    if (selectedService.value === 'web3_domain') return domainValidation.value.valid && !domainValidation.value.loading;
    return false;
});

const proceedToStep3 = () => {
    if (canProceedToStep3.value) {
        currentStep.value = 3;
    }
};

const submitFinalOrder = () => {
    if (!acceptedTerms.value || !isBalanceSufficient.value) return;

    isSubmitting.value = true;
    orderError.value = '';

    const serviceKey = selectedService.value === 'short_line'
        ? 'phone_line'
        : (selectedService.value === 'custom_id' ? 'custom_id' : 'web3_email_package');

    router.post(route('services.orders.store'), {
        service_key: serviceKey,
        order_context: 'organizational',
        payload: {
            service_key: serviceKey,
            requested_identifier: finalIdentifier.value,
            preferred_number: requestedLineNumber.value || undefined,
            prefix_id: selectedPrefixId.value || undefined,
            handle: requestedIdHandle.value || undefined,
            domain_name: requestedDomainName.value || undefined,
            tld: selectedTld.value || undefined,
        },
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isSubmitting.value = false;
            closeWizard();
        },
        onError: (errors) => {
            isSubmitting.value = false;
            orderError.value = Object.values(errors)[0] || 'خطا در ثبت سفارش';
        },
    });
};

const closeWizard = () => {
    currentStep.value = 1;
    selectedService.value = null;
    requestedLineNumber.value = '';
    requestedIdHandle.value = '';
    requestedDomainName.value = '';
    acceptedTerms.value = false;
    orderError.value = '';
    emit('close');
};

const formatPrice = (p: string | number) => {
    const num = Number(p) || 0;
    return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(num);
};
</script>

<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-3 sm:p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md animate-in fade-in duration-200"
        @click.self="closeWizard"
    >
        <div class="glass-card w-full max-w-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl shadow-2xl p-6 sm:p-8 space-y-6 relative">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-white/10">
                <div>
                    <h3 class="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 animate-pulse"></span>
                        سفارش خدمات سازمانی و ویژه
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        دسترسی مستقیم و عمومی به خدمات بدون نیاز به مدارک شرکتی یا تاییدیه سازمانی
                    </p>
                </div>
                <button
                    type="button"
                    @click="closeWizard"
                    class="w-9 h-9 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                >
                    ✕
                </button>
            </div>

            <!-- 3-Step Breadcrumb Progress Bar -->
            <div class="grid grid-cols-3 gap-2">
                <div
                    class="p-2.5 rounded-2xl text-center border transition-all text-xs font-bold"
                    :class="currentStep === 1
                        ? 'bg-indigo-500/10 border-indigo-500/40 text-indigo-600 dark:text-indigo-400'
                        : (currentStep > 1 ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600' : 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-white/5 text-slate-400')"
                >
                    <span class="block text-[10px] opacity-75">گام اول</span>
                    ۱. انتخاب سرویس
                </div>
                <div
                    class="p-2.5 rounded-2xl text-center border transition-all text-xs font-bold"
                    :class="currentStep === 2
                        ? 'bg-indigo-500/10 border-indigo-500/40 text-indigo-600 dark:text-indigo-400'
                        : (currentStep > 2 ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-600' : 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-white/5 text-slate-400')"
                >
                    <span class="block text-[10px] opacity-75">گام دوم</span>
                    ۲. استعلام و پیکربندی
                </div>
                <div
                    class="p-2.5 rounded-2xl text-center border transition-all text-xs font-bold"
                    :class="currentStep === 3
                        ? 'bg-indigo-500/10 border-indigo-500/40 text-indigo-600 dark:text-indigo-400'
                        : 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-white/5 text-slate-400'"
                >
                    <span class="block text-[10px] opacity-75">گام سوم</span>
                    ۳. تعهدات و پرداخت
                </div>
            </div>

            <!-- STEP 1: Select Service -->
            <div v-if="currentStep === 1" class="space-y-4">
                <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200">
                    نوع سرویس سازمانی مورد نظر خود را انتخاب نمایید:
                </h4>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- 1. Short Lines -->
                    <button
                        type="button"
                        @click="selectService('short_line')"
                        class="p-5 rounded-2xl border text-right space-y-3 transition-all hover:scale-[1.02] cursor-pointer group bg-gradient-to-br from-indigo-500/5 to-purple-500/5 border-indigo-200 dark:border-indigo-500/20 hover:border-indigo-500"
                    >
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center text-xl font-bold">
                            📞
                        </div>
                        <div>
                            <h5 class="font-black text-sm text-slate-900 dark:text-white group-hover:text-indigo-500 transition-colors">
                                سرشماره‌های کوتاه
                            </h5>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                                خطوط ۴ الی ۸ رقمی رند با پیش‌شماره اختصاصی جهت ارسال و دریافت پیامک انبوه
                            </p>
                        </div>
                        <div class="pt-2 border-t border-slate-100 dark:border-white/5 flex items-center justify-between text-xs text-indigo-600 dark:text-indigo-400 font-bold">
                            <span>انتخاب و پیکربندی</span>
                            <span>←</span>
                        </div>
                    </button>

                    <!-- 2. Organizational IDs -->
                    <button
                        type="button"
                        @click="selectService('custom_id')"
                        class="p-5 rounded-2xl border text-right space-y-3 transition-all hover:scale-[1.02] cursor-pointer group bg-gradient-to-br from-emerald-500/5 to-teal-500/5 border-emerald-200 dark:border-emerald-500/20 hover:border-emerald-500"
                    >
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-xl font-bold">
                            🆔
                        </div>
                        <div>
                            <h5 class="font-black text-sm text-slate-900 dark:text-white group-hover:text-emerald-500 transition-colors">
                                آیدی‌های سازمانی
                            </h5>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                                آیدی‌های رسمی و کوتاه مانند @brand همراه با تحلیل هوشمند رند بودن
                            </p>
                        </div>
                        <div class="pt-2 border-t border-slate-100 dark:border-white/5 flex items-center justify-between text-xs text-emerald-600 dark:text-emerald-400 font-bold">
                            <span>انتخاب و پیکربندی</span>
                            <span>←</span>
                        </div>
                    </button>

                    <!-- 3. Web3 Domains -->
                    <button
                        type="button"
                        @click="selectService('web3_domain')"
                        class="p-5 rounded-2xl border text-right space-y-3 transition-all hover:scale-[1.02] cursor-pointer group bg-gradient-to-br from-amber-500/5 to-orange-500/5 border-amber-200 dark:border-amber-500/20 hover:border-amber-500"
                    >
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-500 flex items-center justify-center text-xl font-bold">
                            🌐
                        </div>
                        <div>
                            <h5 class="font-black text-sm text-slate-900 dark:text-white group-hover:text-amber-500 transition-colors">
                                دامنه‌های Web3
                            </h5>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                                مالکیت نام غیرمتمرکز، ساب‌ایمیل نامحدود و هویت رمزنگاری‌شده
                            </p>
                        </div>
                        <div class="pt-2 border-t border-slate-100 dark:border-white/5 flex items-center justify-between text-xs text-amber-600 dark:text-amber-400 font-bold">
                            <span>انتخاب و پیکربندی</span>
                            <span>←</span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- STEP 2: Configure & Live AJAX Validate -->
            <div v-else-if="currentStep === 2" class="space-y-5">
                <!-- Option 1: Short Lines Configuration -->
                <div v-if="selectedService === 'short_line'" class="space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-white/5">
                        <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                            <span>📞</span> تنظیمات و استعلام سرشماره کوتاه
                        </h4>
                        <button type="button" @click="currentStep = 1" class="text-xs text-indigo-600 hover:underline">
                            تغییر سرویس
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                پیش‌شماره خط
                            </label>
                            <select
                                v-model="selectedPrefixId"
                                @change="validateShortLineLive"
                                class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                            >
                                <option v-for="p in activePrefixes" :key="p.id" :value="p.id">
                                    {{ p.prefix }} ({{ p.country_name || p.name }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                طول سرشماره
                            </label>
                            <div class="flex gap-1.5">
                                <button
                                    v-for="len in [4, 5, 6, 7, 8]"
                                    :key="len"
                                    type="button"
                                    @click="shortLineLength = len; if (requestedLineNumber.length > len) requestedLineNumber = ''; validateShortLineLive()"
                                    class="flex-1 py-2 text-xs font-bold rounded-xl border transition-all"
                                    :class="shortLineLength === len
                                        ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm'
                                        : 'bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700 hover:border-indigo-400'"
                                >
                                    {{ len }} رقم
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-bold text-slate-700 dark:text-slate-300">
                                شماره دلخواه (اختیاری)
                            </label>
                            <span class="text-[11px] text-slate-400">
                                در صورت خالی بودن، شماره به صورت هوشمند از مخزن تخصیص می‌یابد
                            </span>
                        </div>
                        <input
                            type="text"
                            dir="ltr"
                            v-model="requestedLineNumber"
                            @input="validateShortLineLive"
                            :maxlength="shortLineLength"
                            placeholder="مثلاً: 1000 یا 5555"
                            class="w-full text-sm font-mono tracking-widest text-center rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                        />
                    </div>

                    <!-- Live Validation Feedback Box -->
                    <div
                        v-if="shortLineValidation.loading"
                        class="p-3.5 rounded-2xl bg-slate-100 dark:bg-slate-800/60 border border-slate-200 dark:border-white/5 flex items-center justify-center gap-2 text-xs text-slate-500"
                    >
                        <span class="w-4 h-4 rounded-full border-2 border-indigo-500 border-t-transparent animate-spin"></span>
                        در حال استعلام وضعیت در دسترس بودن سرشماره از دیتابیس...
                    </div>

                    <!-- Unavailable Alert with Suggestions -->
                    <div
                        v-else-if="shortLineValidation.available === false"
                        class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/30 space-y-2 text-xs"
                    >
                        <div class="text-rose-600 dark:text-rose-400 font-bold flex items-center gap-2">
                            <span>⚠️</span>
                            <span>{{ shortLineValidation.message || 'این سرشماره قبلاً واگذار شده و در دسترس نیست.' }}</span>
                        </div>
                        <div v-if="shortLineValidation.suggestions && shortLineValidation.suggestions.length > 0" class="pt-2 border-t border-rose-200/50 space-y-1.5">
                            <span class="text-[11px] font-bold text-slate-600 dark:text-slate-300 block">
                                پیشنهادهای هوشمند مشابه و آزاد:
                            </span>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="sug in shortLineValidation.suggestions"
                                    :key="sug.number"
                                    type="button"
                                    @click="pickSuggestion(sug.number)"
                                    dir="ltr"
                                    class="px-2.5 py-1 rounded-xl bg-white dark:bg-slate-800 border border-rose-200 dark:border-rose-500/30 text-indigo-600 dark:text-indigo-300 font-mono text-xs font-bold hover:border-indigo-500 cursor-pointer"
                                >
                                    {{ sug.number }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Available Success Card -->
                    <div
                        v-else-if="shortLineValidation.valid"
                        class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-500/30 space-y-2 text-xs"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-emerald-700 dark:text-emerald-300 font-bold flex items-center gap-1.5">
                                <span>✓</span> {{ shortLineValidation.message }}
                            </span>
                            <span class="px-2 py-0.5 rounded-lg bg-emerald-500/10 text-emerald-600 font-bold text-[11px]">
                                رده: {{ shortLineValidation.rond_tier_label || 'عادی' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-slate-600 dark:text-slate-300 pt-2 border-t border-emerald-200/60 dark:border-white/5 font-mono">
                            <span>تعرفه رسمی مصوب:</span>
                            <span class="text-sm font-black text-emerald-600 dark:text-emerald-400">
                                {{ formatPrice(shortLineValidation.price || 0) }} کوین
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Option 2: Custom IDs Configuration -->
                <div v-else-if="selectedService === 'custom_id'" class="space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-white/5">
                        <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                            <span>🆔</span> اعتبارسنجی و ثبت آیدی سازمانی
                        </h4>
                        <button type="button" @click="currentStep = 1" class="text-xs text-indigo-600 hover:underline">
                            تغییر سرویس
                        </button>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            آیدی درخواستی (Handle)
                        </label>
                        <div class="relative">
                            <input
                                type="text"
                                dir="ltr"
                                v-model="requestedIdHandle"
                                @input="validateCustomIdLive"
                                placeholder="mybrand"
                                class="w-full text-sm font-mono tracking-wider text-center rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                            <span class="absolute right-3 top-3.5 text-slate-400 font-mono text-sm">@</span>
                        </div>
                    </div>

                    <!-- Loading -->
                    <div
                        v-if="idValidation.loading"
                        class="p-3.5 rounded-2xl bg-slate-100 dark:bg-slate-800/60 border border-slate-200 dark:border-white/5 flex items-center justify-center gap-2 text-xs text-slate-500"
                    >
                        <span class="w-4 h-4 rounded-full border-2 border-emerald-500 border-t-transparent animate-spin"></span>
                        در حال تحلیل الگو و رند بودن آیدی در بک‌اند...
                    </div>

                    <!-- Unavailable -->
                    <div
                        v-else-if="idValidation.available === false"
                        class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/30 text-xs text-rose-600 dark:text-rose-400 font-bold"
                    >
                        ⚠️ {{ idValidation.message || 'این آیدی قبلاً ثبت شده یا رزرو سیستم است.' }}
                    </div>

                    <!-- Available -->
                    <div
                        v-else-if="idValidation.valid"
                        class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-500/30 space-y-2 text-xs"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-emerald-700 dark:text-emerald-300 font-bold">
                                ✓ {{ idValidation.message }}
                            </span>
                            <span class="px-2 py-0.5 rounded-lg bg-emerald-500/10 text-emerald-600 font-bold text-[11px]">
                                {{ idValidation.rarity_label }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-slate-600 dark:text-slate-300 pt-2 border-t border-emerald-200/60 font-mono">
                            <span>تعرفه محاسبه‌شده بر حسب رند بودن:</span>
                            <span class="text-sm font-black text-emerald-600 dark:text-emerald-400">
                                {{ formatPrice(idValidation.price || 0) }} کوین
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Option 3: Web3 Domains Configuration -->
                <div v-else-if="selectedService === 'web3_domain'" class="space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-white/5">
                        <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                            <span>🌐</span> استعلام دامنه Web3 و پسوند
                        </h4>
                        <button type="button" @click="currentStep = 1" class="text-xs text-indigo-600 hover:underline">
                            تغییر سرویس
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                نام دامنه
                            </label>
                            <input
                                type="text"
                                dir="ltr"
                                v-model="requestedDomainName"
                                @input="validateWeb3DomainLive"
                                placeholder="brandname"
                                class="w-full text-sm font-mono tracking-wider rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                پسوند (TLD)
                            </label>
                            <select
                                dir="ltr"
                                v-model="selectedTld"
                                @change="validateWeb3DomainLive"
                                class="w-full text-sm font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            >
                                <option v-for="t in availableTlds" :key="t.id" :value="t.tld">
                                    .{{ t.tld }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Loading -->
                    <div
                        v-if="domainValidation.loading"
                        class="p-3.5 rounded-2xl bg-slate-100 dark:bg-slate-800/60 border border-slate-200 dark:border-white/5 flex items-center justify-center gap-2 text-xs text-slate-500"
                    >
                        <span class="w-4 h-4 rounded-full border-2 border-amber-500 border-t-transparent animate-spin"></span>
                        در حال بررسی در دسترس بودن دامنه Web3...
                    </div>

                    <!-- Unavailable -->
                    <div
                        v-else-if="domainValidation.available === false"
                        class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/30 text-xs text-rose-600 dark:text-rose-400 font-bold"
                    >
                        ⚠️ {{ domainValidation.message || 'این دامنه قبلاً ثبت شده و در دسترس نیست.' }}
                    </div>

                    <!-- Available -->
                    <div
                        v-else-if="domainValidation.valid"
                        class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-500/30 space-y-2 text-xs"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-emerald-700 dark:text-emerald-300 font-bold">
                                ✓ {{ domainValidation.message }}
                            </span>
                            <span class="px-2 py-0.5 rounded-lg bg-emerald-500/10 text-emerald-600 font-bold text-[11px]">
                                {{ domainValidation.rarity_label }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-slate-600 dark:text-slate-300 pt-2 border-t border-emerald-200/60 font-mono">
                            <span>تعرفه ثبت دامنه:</span>
                            <span class="text-sm font-black text-emerald-600 dark:text-emerald-400">
                                {{ formatPrice(domainValidation.price || 0) }} کوین
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Bottom Navigation for Step 2 -->
                <div class="flex items-center justify-between pt-4 border-t border-slate-200 dark:border-white/10">
                    <button
                        type="button"
                        @click="currentStep = 1"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        بازگشت به انتخاب سرویس
                    </button>
                    <button
                        type="button"
                        @click="proceedToStep3"
                        :disabled="!canProceedToStep3"
                        class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-bold transition-all shadow-sm flex items-center gap-2"
                    >
                        <span>ادامه به مرحله تعهدات و پرداخت</span>
                        <span>←</span>
                    </button>
                </div>
            </div>

            <!-- STEP 3: Terms, Invoice Summary & Instant Wallet Checkout -->
            <div v-else-if="currentStep === 3" class="space-y-5">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-white/5">
                    <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                        <span>📋</span> تعهدات و تایید نهایی سفارش
                    </h4>
                    <button type="button" @click="currentStep = 2" class="text-xs text-indigo-600 hover:underline">
                        ویرایش مشخصات
                    </button>
                </div>

                <!-- Order Invoice Summary Card -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-white/10 space-y-3">
                    <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200/60 dark:border-white/5">
                        <span class="text-slate-500 dark:text-slate-400">نوع سرویس:</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">
                            {{ selectedService === 'short_line' ? 'سرشماره کوتاه اختصاصی' : (selectedService === 'custom_id' ? 'آیدی سازمانی' : 'دامنه Web3') }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200/60 dark:border-white/5 font-mono">
                        <span class="text-slate-500 dark:text-slate-400">شناسه / شماره درخواستی:</span>
                        <span class="font-black text-indigo-600 dark:text-indigo-400 text-sm" dir="ltr">
                            {{ finalIdentifier }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs pb-2 border-b border-slate-200/60 dark:border-white/5 font-mono">
                        <span class="text-slate-500 dark:text-slate-400">مبلغ نهایی قابل کسر:</span>
                        <span class="font-black text-emerald-600 dark:text-emerald-400 text-sm">
                            {{ formatPrice(calculatedPrice) }} کوین
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs font-mono">
                        <span class="text-slate-500 dark:text-slate-400">موجودی فعلی کیف‌پول:</span>
                        <span
                            class="font-bold"
                            :class="isBalanceSufficient ? 'text-slate-700 dark:text-slate-300' : 'text-rose-600 dark:text-rose-400'"
                        >
                            {{ formatPrice(walletBalance) }} کوین
                        </span>
                    </div>
                </div>

                <!-- Insufficient Balance Warning -->
                <div
                    v-if="!isBalanceSufficient"
                    class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/30 text-xs text-rose-600 dark:text-rose-400 flex items-center justify-between"
                >
                    <span class="font-bold">⚠️ موجودی کیف‌پول شما جهت پرداخت این سفارش کافی نیست.</span>
                    <a
                        :href="route('payments.index')"
                        class="px-3 py-1 rounded-lg bg-rose-600 text-white font-bold text-[11px] hover:bg-rose-700 transition-colors"
                    >
                        شارژ کیف‌پول
                    </a>
                </div>

                <!-- Error Alert -->
                <div
                    v-if="orderError"
                    class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/30 text-xs text-rose-600 dark:text-rose-400 font-bold"
                >
                    ⚠️ {{ orderError }}
                </div>

                <!-- Single Terms Acceptance Checkbox (No Title, No Docs required) -->
                <label class="flex items-start gap-3 p-4 rounded-2xl border border-slate-200 dark:border-white/10 bg-slate-50/50 dark:bg-slate-800/30 cursor-pointer text-xs leading-relaxed text-slate-700 dark:text-slate-300">
                    <input
                        type="checkbox"
                        v-model="acceptedTerms"
                        class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <span>
                        کلیه قوانین و مقررات ارتباطات و تعهدات سرویس‌های سازمانی سامانه را مطالعه نموده و متعهد می‌گردم از این سرویس صرفاً در چارچوب ضوابط قانونی و تبادل پیام‌های مجاز استفاده نمایم.
                    </span>
                </label>

                <!-- Bottom Navigation for Step 3 -->
                <div class="flex items-center justify-between pt-4 border-t border-slate-200 dark:border-white/10">
                    <button
                        type="button"
                        @click="currentStep = 2"
                        class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        مرحله قبل
                    </button>
                    <button
                        type="button"
                        @click="submitFinalOrder"
                        :disabled="!acceptedTerms || !isBalanceSufficient || isSubmitting"
                        class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-bold transition-all shadow-sm flex items-center gap-2"
                    >
                        <span v-if="isSubmitting" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                        <span>{{ isSubmitting ? 'در حال ثبت و کسر از کیف‌پول...' : 'تایید نهایی و پرداخت از کیف‌پول' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
