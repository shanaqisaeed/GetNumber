<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

interface Props {
    isOpen: boolean;
    walletBalance?: string | number;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'orderPlaced', orderNumber: string): void;
}>();

// Steps: 1 (Select Service) -> 2 (Configure & Live Validate) -> 3 (Terms & Commitments) -> 4 (Payment & Final Commit)
const currentStep = ref<1 | 2 | 3 | 4>(1);

type ServiceType = 'short_line' | 'custom_id' | 'web3_domain';
const selectedService = ref<ServiceType | null>(null);

// Service 1: Short Lines state
const shortLineLength = ref<number>(4);
const requestedLineNumber = ref<string>('');
const shortLineValidation = ref<{
    loading: boolean;
    valid: boolean;
    available?: boolean;
    message?: string;
    price?: string;
    is_rond?: boolean;
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

// Step 3: Terms
const acceptedTerms = ref(false);

// Step 4: Submission
const isSubmitting = ref(false);
const orderError = ref('');

// Computed Final Price & Identifier
const calculatedPrice = computed(() => {
    if (selectedService.value === 'short_line') return shortLineValidation.value.price || '0.0000';
    if (selectedService.value === 'custom_id') return idValidation.value.price || '0.0000';
    if (selectedService.value === 'web3_domain') return domainValidation.value.price || '0.0000';
    return '0.0000';
});

const finalIdentifier = computed(() => {
    if (selectedService.value === 'short_line') {
        return requestedLineNumber.value || `${shortLineLength.value} رقمی رندوم`;
    }
    if (selectedService.value === 'custom_id') {
        return requestedIdHandle.value.startsWith('@') ? requestedIdHandle.value : `@${requestedIdHandle.value}`;
    }
    if (selectedService.value === 'web3_domain') {
        return `${requestedDomainName.value}.${selectedTld.value}`;
    }
    return '';
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
            });
            shortLineValidation.value = {
                loading: false,
                valid: res.data.available,
                available: res.data.available,
                message: res.data.message,
                price: res.data.base_price,
                is_rond: res.data.is_rond,
            };
        } catch (err: any) {
            shortLineValidation.value = {
                loading: false,
                valid: false,
                message: err.response?.data?.message || 'خطا در اعتبارسنجی سرشماره',
            };
        }
    }, 300);
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
                valid: res.data.available,
                available: res.data.available,
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
                message: err.response?.data?.message || 'خطا در اعتبارسنجی آیدی',
            };
        }
    }, 300);
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
                valid: res.data.available,
                available: res.data.available,
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
                message: err.response?.data?.message || 'خطا در اعتبارسنجی دامنه',
            };
        }
    }, 300);
};

// Wizard Flow Controls
const selectService = (srv: ServiceType) => {
    selectedService.value = srv;
    currentStep.value = 2;
    if (srv === 'short_line') validateShortLineLive();
    if (srv === 'custom_id' && requestedIdHandle.value) validateCustomIdLive();
    if (srv === 'web3_domain' && requestedDomainName.value) validateWeb3DomainLive();
};

const canProceedToTerms = computed(() => {
    if (selectedService.value === 'short_line') return shortLineValidation.value.valid;
    if (selectedService.value === 'custom_id') return idValidation.value.valid;
    if (selectedService.value === 'web3_domain') return domainValidation.value.valid;
    return false;
});

const proceedToTerms = () => {
    if (canProceedToTerms.value) {
        currentStep.value = 3;
    }
};

const proceedToCheckout = () => {
    if (acceptedTerms.value) {
        currentStep.value = 4;
    }
};

const submitFinalOrder = () => {
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
            domain_name: requestedDomainName.value || undefined,
            tld: selectedTld.value || undefined,
        },
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
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
    acceptedTerms.value = false;
    orderError.value = '';
    emit('close');
};

const formatCurrency = (val: string | number) => {
    return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(Number(val) || 0);
};
</script>

<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4"
    >
        <div class="bg-white dark:bg-gray-800 rounded-3xl max-w-2xl w-full shadow-2xl border border-gray-100 dark:border-gray-700/60 overflow-hidden text-right">
            <!-- Modal Header -->
            <div class="p-6 bg-gradient-to-r from-indigo-900 via-indigo-800 to-indigo-900 text-white flex items-center justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded-full bg-indigo-500/30 text-indigo-200 text-xs font-semibold">
                            دسترسی عمومی و سازمانی
                        </span>
                        <span class="text-xs text-indigo-300">مرحله {{ currentStep }} از ۴</span>
                    </div>
                    <h3 class="text-lg font-bold mt-1">سفارش و فعال‌سازی سرویس‌های ویژه</h3>
                </div>
                <button
                    type="button"
                    @click="closeWizard"
                    class="w-8 h-8 rounded-xl bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors"
                >
                    &times;
                </button>
            </div>

            <!-- Steps Indicator -->
            <div class="px-6 py-3 bg-gray-50 dark:bg-gray-800/80 border-b border-gray-100 dark:border-gray-700/60 flex items-center justify-between text-xs font-semibold">
                <div :class="currentStep >= 1 ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-gray-400'">
                    ۱. انتخاب سرویس
                </div>
                <div class="text-gray-300">&larr;</div>
                <div :class="currentStep >= 2 ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-gray-400'">
                    ۲. اعتبارسنجی زنده
                </div>
                <div class="text-gray-300">&larr;</div>
                <div :class="currentStep >= 3 ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-gray-400'">
                    ۳. تعهدات و قوانین
                </div>
                <div class="text-gray-300">&larr;</div>
                <div :class="currentStep >= 4 ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-gray-400'">
                    ۴. تایید و پرداخت
                </div>
            </div>

            <!-- Wizard Body -->
            <div class="p-6 space-y-6">
                <!-- ================= STEP 1: SELECT SERVICE ================= -->
                <div v-if="currentStep === 1" class="space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        لطفاً یکی از ۳ سرویس ویژه زیر را انتخاب نمایید (بدون نیاز به مدارک حقوقی یا تاییدیه شرکتی):
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Option 1: Short Lines -->
                        <div
                            @click="selectService('short_line')"
                            class="p-5 rounded-2xl border-2 border-gray-200 dark:border-gray-700 hover:border-indigo-600 dark:hover:border-indigo-500 bg-gray-50 dark:bg-gray-700/30 cursor-pointer transition-all hover:shadow-md group"
                        >
                            <div class="w-12 h-12 rounded-2xl bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <h4 class="text-base font-bold text-gray-900 dark:text-white">سرشماره‌های کوتاه</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                                خطوط ۴ الی ۸ رقمی رند با قابلیت ارسال و دریافت پیامک و تماس صوتی
                            </p>
                        </div>

                        <!-- Option 2: Organizational IDs -->
                        <div
                            @click="selectService('custom_id')"
                            class="p-5 rounded-2xl border-2 border-gray-200 dark:border-gray-700 hover:border-indigo-600 dark:hover:border-indigo-500 bg-gray-50 dark:bg-gray-700/30 cursor-pointer transition-all hover:shadow-md group"
                        >
                            <div class="w-12 h-12 rounded-2xl bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <h4 class="text-base font-bold text-gray-900 dark:text-white">آیدی‌های اختصاصی</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                                رزرو شناسه متنی (@handle) با تحلیل هوشمند رند بودن و قیمت متناظر
                            </p>
                        </div>

                        <!-- Option 3: Web3 Domains -->
                        <div
                            @click="selectService('web3_domain')"
                            class="p-5 rounded-2xl border-2 border-gray-200 dark:border-gray-700 hover:border-indigo-600 dark:hover:border-indigo-500 bg-gray-50 dark:bg-gray-700/30 cursor-pointer transition-all hover:shadow-md group"
                        >
                            <div class="w-12 h-12 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                                </svg>
                            </div>
                            <h4 class="text-base font-bold text-gray-900 dark:text-white">دامنه‌های Web3</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">
                                ثبت دامنه با پسوندهای فعال و مدیریت نامحدود ساب‌ایمیل و نام مستعار
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ================= STEP 2: CONFIGURE & LIVE VALIDATE ================= -->
                <div v-if="currentStep === 2" class="space-y-5">
                    <!-- A: Short Lines Configuration -->
                    <div v-if="selectedService === 'short_line'" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-2">
                                طول سرشماره را انتخاب کنید:
                            </label>
                            <div class="grid grid-cols-5 gap-2">
                                <button
                                    v-for="l in [4, 5, 6, 7, 8]"
                                    :key="l"
                                    type="button"
                                    @click="shortLineLength = l; validateShortLineLive()"
                                    class="py-2.5 rounded-xl text-xs font-bold transition-all border"
                                    :class="shortLineLength === l ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'"
                                >
                                    {{ l }} رقمی
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                سرشماره درخواستی (اختیاری):
                            </label>
                            <input
                                v-model="requestedLineNumber"
                                @input="validateShortLineLive"
                                type="text"
                                :placeholder="`مثال: ${'1'.repeat(shortLineLength)} یا خالی بگذارید`"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                            <p class="text-[11px] text-gray-400 mt-1">در صورت خالی گذاشتن، یک شماره آزاد از استخر به شما تخصیص می‌یابد.</p>
                        </div>

                        <!-- Live Validation Result Alert -->
                        <div
                            class="p-4 rounded-2xl text-xs flex items-center justify-between"
                            :class="shortLineValidation.valid ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800'"
                        >
                            <div class="flex items-center gap-2">
                                <span v-if="shortLineValidation.loading" class="animate-spin text-indigo-600">⌛</span>
                                <span v-else>{{ shortLineValidation.message || 'سرشماره در دسترس است.' }}</span>
                            </div>
                            <div v-if="shortLineValidation.valid" class="font-mono font-bold text-sm">
                                قیمت: {{ formatCurrency(shortLineValidation.price || 0) }} کوین
                            </div>
                        </div>
                    </div>

                    <!-- B: Custom IDs Configuration -->
                    <div v-if="selectedService === 'custom_id'" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                آیدی متنی درخواستی:
                            </label>
                            <div class="relative">
                                <input
                                    v-model="requestedIdHandle"
                                    @input="validateCustomIdLive"
                                    type="text"
                                    placeholder="مثال: star یا vip_support یا tech"
                                    class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white pl-10"
                                />
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 font-mono text-sm">@</span>
                            </div>
                            <p class="text-[11px] text-gray-400 mt-1">آیدی می‌تواند شامل ۳ الی ۳۲ کاراکتر انگلیسی، اعداد و خط تیره باشد.</p>
                        </div>

                        <!-- Rarity Badge & Calculated Pricing -->
                        <div
                            v-if="requestedIdHandle.trim()"
                            class="p-4 rounded-2xl text-xs flex items-center justify-between"
                            :class="idValidation.valid ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800'"
                        >
                            <div class="flex items-center gap-2">
                                <span v-if="idValidation.loading" class="animate-spin text-indigo-600">⌛</span>
                                <div v-else>
                                    <div>{{ idValidation.message }}</div>
                                    <div v-if="idValidation.rarity_label" class="mt-1 flex items-center gap-1.5">
                                        <span>رده‌بندی هوشمند:</span>
                                        <span class="font-bold px-2 py-0.5 rounded text-[11px]" :class="idValidation.rarity_badge">
                                            {{ idValidation.rarity_label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="idValidation.valid" class="font-mono font-bold text-sm">
                                {{ formatCurrency(idValidation.price || 0) }} کوین
                            </div>
                        </div>
                    </div>

                    <!-- C: Web3 Domains Configuration -->
                    <div v-if="selectedService === 'web3_domain'" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                    نام دامنه درخواستی:
                                </label>
                                <input
                                    v-model="requestedDomainName"
                                    @input="validateWeb3DomainLive"
                                    type="text"
                                    placeholder="مثال: company یا pay"
                                    class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                    پسوند (TLD):
                                </label>
                                <select
                                    v-model="selectedTld"
                                    @change="validateWeb3DomainLive"
                                    class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                >
                                    <option v-for="t in availableTlds" :key="t.id" :value="t.tld">
                                        .{{ t.tld }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Live Validation Result -->
                        <div
                            v-if="requestedDomainName.trim()"
                            class="p-4 rounded-2xl text-xs flex items-center justify-between"
                            :class="domainValidation.valid ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800'"
                        >
                            <div class="flex items-center gap-2">
                                <span v-if="domainValidation.loading" class="animate-spin text-indigo-600">⌛</span>
                                <div v-else>
                                    <div>{{ domainValidation.message }}</div>
                                    <div v-if="domainValidation.rarity_label" class="mt-1">
                                        رده رند: <span class="font-bold text-indigo-600">{{ domainValidation.rarity_label }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="domainValidation.valid" class="font-mono font-bold text-sm">
                                {{ formatCurrency(domainValidation.price || 0) }} کوین
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="pt-4 flex items-center justify-between border-t border-gray-100 dark:border-gray-700">
                        <button
                            type="button"
                            @click="currentStep = 1"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-gray-600 bg-gray-100 dark:bg-gray-700"
                        >
                            &rarr; بازگشت به انتخاب سرویس
                        </button>
                        <button
                            type="button"
                            :disabled="!canProceedToTerms"
                            @click="proceedToTerms"
                            class="px-5 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 transition-colors shadow-sm"
                        >
                            ادامه و مطالعه تعهدات &larr;
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 3: TERMS & COMMITMENTS ================= -->
                <div v-if="currentStep === 3" class="space-y-5">
                    <h4 class="text-base font-bold text-gray-900 dark:text-white">
                        تعهدات و قوانین بهره‌برداری از سرویس
                    </h4>

                    <div class="p-4 rounded-2xl bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600 text-xs text-gray-600 dark:text-gray-300 space-y-2.5 max-h-48 overflow-y-auto leading-relaxed">
                        <p>۱. استفاده از سرویس‌های تخصیص‌یافته صرفاً جهت مقاصد قانونی و مطابق با مقررات ارتباطات و فضای مجازی مجاز است.</p>
                        <p>۲. هرگونه ارسال هرزنامه (Spam)، پیامک‌های تبلیغاتی بدون رضایت گیرنده، و جعل هویت اکیداً ممنوع بوده و موجب مسدودسازی آنی حساب خواهد شد.</p>
                        <p>۳. کلیه تراکنش‌های اعتباری و هزینه‌های مصوب به ازای پیام و مکالمه به صورت خودکار از موجودی کیف‌پول کسر می‌گردد.</p>
                        <p>۴. حق بهره‌برداری از شناسه منحصراً به حساب کاربری خریدار واگذار شده و قابل معامله خارج از پلتفرم نمی‌باشد.</p>
                    </div>

                    <!-- Single Clean Checkbox without redundant title field -->
                    <div class="pt-2 flex items-center gap-3">
                        <input
                            id="accept_terms"
                            v-model="acceptedTerms"
                            type="checkbox"
                            class="w-5 h-5 text-indigo-600 rounded border-gray-300 dark:border-gray-600 focus:ring-indigo-500"
                        />
                        <label for="accept_terms" class="text-xs font-bold text-gray-900 dark:text-white cursor-pointer">
                            کلیه شرایط، قوانین و تعهدات سامانه را مطالعه نموده و می‌پذیرم.
                        </label>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="pt-4 flex items-center justify-between border-t border-gray-100 dark:border-gray-700">
                        <button
                            type="button"
                            @click="currentStep = 2"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-gray-600 bg-gray-100 dark:bg-gray-700"
                        >
                            &rarr; بازگشت به تنظیمات
                        </button>
                        <button
                            type="button"
                            :disabled="!acceptedTerms"
                            @click="proceedToCheckout"
                            class="px-5 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 transition-colors shadow-sm"
                        >
                            مرحله پرداخت و تایید نهایی &larr;
                        </button>
                    </div>
                </div>

                <!-- ================= STEP 4: CHECKOUT & FINAL COMMIT ================= -->
                <div v-if="currentStep === 4" class="space-y-5">
                    <h4 class="text-base font-bold text-gray-900 dark:text-white">
                        پیش‌فاکتور و ثبت نهایی سفارش
                    </h4>

                    <div class="p-5 rounded-2xl bg-indigo-50/60 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/50 space-y-3">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">شناسه سرویس درخواستی:</span>
                            <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400 text-sm">{{ finalIdentifier }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">نوع سرویس:</span>
                            <span class="font-bold text-gray-900 dark:text-white">
                                {{ selectedService === 'short_line' ? 'سرشماره کوتاه' : (selectedService === 'custom_id' ? 'آیدی اختصاصی' : 'دامنه Web3') }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-xs pt-2 border-t border-indigo-100 dark:border-indigo-900">
                            <span class="text-gray-500">مبلغ قابل پرداخت (کسر از کیف‌پول):</span>
                            <span class="font-mono font-black text-base text-gray-900 dark:text-white">
                                {{ formatCurrency(calculatedPrice) }} <span class="text-xs font-normal text-gray-500">کوین</span>
                            </span>
                        </div>
                    </div>

                    <div v-if="orderError" class="p-3 rounded-xl bg-rose-50 text-rose-800 text-xs font-semibold">
                        {{ orderError }}
                    </div>

                    <div class="pt-4 flex items-center justify-between border-t border-gray-100 dark:border-gray-700">
                        <button
                            type="button"
                            @click="currentStep = 3"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-gray-600 bg-gray-100 dark:bg-gray-700"
                        >
                            &rarr; بازگشت به تعهدات
                        </button>
                        <button
                            type="button"
                            :disabled="isSubmitting"
                            @click="submitFinalOrder"
                            class="px-6 py-2.5 text-xs font-bold rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 shadow-md shadow-emerald-600/30 disabled:opacity-50 transition-all"
                        >
                            {{ isSubmitting ? 'در حال ثبت نهایی...' : 'تایید نهایی و کسر از کیف‌پول' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

