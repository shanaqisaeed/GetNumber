<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { __ } from '@/Composables/useTrans';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
    laravelVersion?: string;
    phpVersion?: string;
}>();

// FAQ accordion state
const openFaq = ref<number | null>(0);

const toggleFaq = (index: number) => {
    openFaq.value = openFaq.value === index ? null : index;
};

// 4 Architectural Core Pillars
const corePillars = computed(() => [
    {
        title: __('welcome.features.standard_title'),
        badge: __('welcome.features.standard_badge'),
        description: __('welcome.features.standard_desc'),
        icon: 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
        gradient: 'from-blue-600 to-indigo-600',
    },
    {
        title: __('welcome.features.requests_title'),
        badge: __('welcome.features.requests_badge'),
        description: __('welcome.features.requests_desc'),
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        gradient: 'from-purple-600 to-pink-600',
    },
    {
        title: __('welcome.features.api_title'),
        badge: __('welcome.features.api_badge'),
        description: __('welcome.features.api_desc'),
        icon: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
        gradient: 'from-emerald-600 to-teal-600',
    },
    {
        title: __('welcome.features.tickets_title'),
        badge: __('welcome.features.tickets_badge'),
        description: __('welcome.features.tickets_desc'),
        icon: 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z',
        gradient: 'from-amber-600 to-orange-600',
    },
]);

// Workflow Steps
const workflowSteps = computed(() => [
    {
        step: __('welcome.steps.step_1_num'),
        title: __('welcome.steps.step_1_title'),
        desc: __('welcome.steps.step_1_desc'),
    },
    {
        step: __('welcome.steps.step_2_num'),
        title: __('welcome.steps.step_2_title'),
        desc: __('welcome.steps.step_2_desc'),
    },
    {
        step: __('welcome.steps.step_3_num'),
        title: __('welcome.steps.step_3_title'),
        desc: __('welcome.steps.step_3_desc'),
    },
    {
        step: __('welcome.steps.step_4_num'),
        title: __('welcome.steps.step_4_title'),
        desc: __('welcome.steps.step_4_desc'),
    },
]);

// Operational Metrics
const metrics = computed(() => [
    { value: __('welcome.stats.stat_1_val'), label: __('welcome.stats.stat_1_lbl'), desc: __('welcome.stats.stat_1_desc') },
    { value: __('welcome.stats.stat_2_val'), label: __('welcome.stats.stat_2_lbl'), desc: __('welcome.stats.stat_2_desc') },
    { value: __('welcome.stats.stat_3_val'), label: __('welcome.stats.stat_3_lbl'), desc: __('welcome.stats.stat_3_desc') },
    { value: __('welcome.stats.stat_4_val'), label: __('welcome.stats.stat_4_lbl'), desc: __('welcome.stats.stat_4_desc') },
]);

// Frequently Asked Questions
const faqs = computed(() => [
    {
        q: __('welcome.faqs.q1'),
        a: __('welcome.faqs.a1'),
    },
    {
        q: __('welcome.faqs.q2'),
        a: __('welcome.faqs.a2'),
    },
    {
        q: __('welcome.faqs.q3'),
        a: __('welcome.faqs.a3'),
    },
    {
        q: __('welcome.faqs.q4'),
        a: __('welcome.faqs.a4'),
    },
]);
</script>

<template>
    <Head :title="__('welcome.head_title')" />

    <GuestLayout>
        <!-- 1. Hero Section -->
        <section class="relative pt-12 sm:pt-20 pb-16 px-4 sm:px-6 max-w-6xl mx-auto">
            <div class="text-center max-w-3xl mx-auto">
                <!-- Status Badge -->
                <div class="inline-flex items-center gap-2 mb-6">
                    <span class="glass-pill text-indigo-600 dark:text-indigo-400 font-bold text-xs">
                        <span class="status-dot-pulse"></span>
                        {{ __('welcome.hero_badge') }}
                    </span>
                </div>

                <!-- Main Hero Headline -->
                <h1 class="text-3xl sm:text-5xl font-black text-slate-900 dark:text-white tracking-tight leading-tight sm:leading-tight">
                    {{ __('welcome.hero_title_1') }}
                    <span class="block mt-2 bg-gradient-to-l from-indigo-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
                        {{ __('welcome.hero_title_2') }}
                    </span>
                </h1>

                <!-- Hero Subtitle -->
                <p class="mt-6 text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed max-w-2xl mx-auto font-medium">
                    {{ __('welcome.hero_desc') }}
                </p>

                <!-- Dual CTA Action Buttons -->
                <div class="mt-8 flex flex-wrap justify-center items-center gap-4">
                    <template v-if="$page.props.auth?.user">
                        <Link
                            :href="route('dashboard')"
                            class="glass-btn-primary !w-auto !py-3.5 !px-8 !text-sm !rounded-2xl"
                        >
                            <span>{{ __('welcome.dashboard_link') }}</span>
                            <svg class="w-4 h-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('register')"
                            class="glass-btn-primary !w-auto !py-3.5 !px-8 !text-sm !rounded-2xl"
                        >
                            <span>{{ __('welcome.register_link') }}</span>
                            <svg class="w-4 h-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="19" y1="12" x2="5" y2="12"></line>
                                <polyline points="12 19 5 12 12 5"></polyline>
                            </svg>
                        </Link>
                        <Link
                            :href="route('login')"
                            class="glass-pill !py-3.5 !px-7 !text-sm font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400"
                        >
                            <span>{{ __('welcome.login_link') }}</span>
                        </Link>
                    </template>
                </div>

                <!-- Technical Scope Clarification Notice (Required Policy) -->
                <div class="mt-10 p-4 rounded-2xl glass-card text-start flex items-start gap-3.5 border-indigo-500/20 max-w-2xl mx-auto">
                    <div class="w-9 h-9 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                    </div>
                    <div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        <b class="text-slate-900 dark:text-white block mb-1">{{ __('welcome.notice_title') }}</b>
                        {{ __('welcome.notice_desc') }}
                    </div>
                </div>
            </div>
        </section>

        <!-- 3. Core Architectural Pillars Bento Grid -->
        <section class="py-16 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="text-center mb-12">
                <span class="glass-pill text-xs font-bold text-indigo-600 dark:text-indigo-400 mb-2">{{ __('welcome.features_pill') }}</span>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-2">
                    {{ __('welcome.features_title') }}
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2 max-w-xl mx-auto">
                    {{ __('welcome.features_desc') }}
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div
                    v-for="pillar in corePillars"
                    :key="pillar.title"
                    class="glass-card group flex flex-col justify-between"
                >
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <div
                                class="w-12 h-12 rounded-2xl bg-gradient-to-tr text-white flex items-center justify-center shadow-lg shadow-indigo-500/20 group-hover:scale-105 transition-transform"
                                :class="pillar.gradient"
                            >
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="pillar.icon" />
                                </svg>
                            </div>
                            <span class="glass-pill text-[11px] font-bold text-slate-700 dark:text-slate-300">
                                {{ pillar.badge }}
                            </span>
                        </div>

                        <h3 class="text-base font-bold text-slate-900 dark:text-white mb-2">
                            {{ pillar.title }}
                        </h3>
                        <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 leading-relaxed font-normal">
                            {{ pillar.description }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- 4. Step-by-Step Workflow -->
        <section class="py-16 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="text-center mb-12">
                <span class="glass-pill text-xs font-bold text-indigo-600 dark:text-indigo-400 mb-2">{{ __('welcome.workflow_pill') }}</span>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-2">
                    {{ __('welcome.workflow_title') }}
                </h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="step in workflowSteps"
                    :key="step.step"
                    class="glass-card !p-5 relative"
                >
                    <span class="text-2xl font-black text-indigo-600/30 dark:text-indigo-400/30 block mb-2 font-mono">
                        {{ step.step }}
                    </span>
                    <h4 class="text-sm font-bold text-slate-900 dark:text-white mb-1.5">
                        {{ step.title }}
                    </h4>
                    <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                        {{ step.desc }}
                    </p>
                </div>
            </div>
        </section>

        <!-- 5. Operational Metrics / Stats -->
        <section class="py-12 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="metric in metrics"
                    :key="metric.label"
                    class="glass-card !p-5 text-center"
                >
                    <div class="text-xl sm:text-2xl font-black text-indigo-600 dark:text-indigo-400 font-mono mb-1">
                        {{ metric.value }}
                    </div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white mb-1">
                        {{ metric.label }}
                    </div>
                    <div class="text-[11px] text-slate-500 dark:text-slate-400">
                        {{ metric.desc }}
                    </div>
                </div>
            </div>
        </section>

        <!-- 6. Frequently Asked Questions (Accordion) -->
        <section class="py-16 px-4 sm:px-6 max-w-4xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="text-center mb-10">
                <span class="glass-pill text-xs font-bold text-indigo-600 dark:text-indigo-400 mb-2">{{ __('welcome.faq_pill') }}</span>
                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-2">
                    {{ __('welcome.faq_title') }}
                </h2>
            </div>

            <div class="space-y-3">
                <div
                    v-for="(faq, idx) in faqs"
                    :key="faq.q"
                    class="glass-card !p-4 cursor-pointer transition-all"
                    @click="toggleFaq(idx)"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-bold text-slate-900 dark:text-white">{{ faq.q }}</span>
                        <svg
                            class="w-4 h-4 text-slate-400 transition-transform duration-200 shrink-0"
                            :class="{ 'rotate-180 text-indigo-600 dark:text-indigo-400': openFaq === idx }"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </div>
                    <div
                        v-show="openFaq === idx"
                        class="mt-3 pt-3 border-t border-slate-200/50 dark:border-white/10 text-xs text-slate-600 dark:text-slate-300 leading-relaxed"
                    >
                        {{ faq.a }}
                    </div>
                </div>
            </div>
        </section>

        <!-- 7. Final Call to Action Box -->
        <section class="py-16 px-4 sm:px-6 max-w-6xl mx-auto border-t border-slate-200/60 dark:border-white/10">
            <div class="glass-card !p-8 sm:!p-12 text-center bg-gradient-to-tr from-indigo-950/20 via-purple-950/20 to-transparent relative overflow-hidden">
                <h3 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mb-3">
                    {{ __('welcome.cta_title') }}
                </h3>
                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 max-w-xl mx-auto mb-6 leading-relaxed">
                    {{ __('welcome.cta_desc') }}
                </p>

                <div class="flex flex-wrap justify-center gap-4">
                    <Link
                        :href="route('register')"
                        class="glass-btn-primary !w-auto !py-3 !px-8 !text-sm !rounded-2xl"
                    >
                        <span>{{ __('welcome.cta_register') }}</span>
                        <svg class="w-4 h-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                    </Link>
                    <Link
                        :href="route('login')"
                        class="glass-pill !py-3 !px-7 !text-sm font-bold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400"
                    >
                        <span>{{ __('welcome.cta_login') }}</span>
                    </Link>
                </div>
            </div>
        </section>
    </GuestLayout>
</template>
