<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted } from 'vue';

const props = withDefaults(
    defineProps<{
        buttonClass?: string;
    }>(),
    {
        buttonClass: '',
    }
);

const page = usePage();
const currentLocale = computed<'fa' | 'en'>(() => ((page.props as any).locale === 'en' ? 'en' : 'fa'));
const isOpen = ref(false);
const dropdownRef = ref<HTMLDivElement | null>(null);

const languages = [
    { code: 'fa', name: 'فارسی', flag: '🇮🇷', dir: 'rtl' },
    { code: 'en', name: 'English', flag: '🇺🇸', dir: 'ltr' },
];

function switchLanguage(code: 'fa' | 'en') {
    if (code === currentLocale.value) {
        isOpen.value = false;
        return;
    }

    router.post(
        route('locale.update', { locale: code }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isOpen.value = false;
            },
        }
    );
}

function handleClickOutside(event: MouseEvent) {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
        isOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div class="relative inline-block text-start" ref="dropdownRef">
        <button
            type="button"
            @click="isOpen = !isOpen"
            class="inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/80 dark:border-white/10 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
            :class="buttonClass"
            :title="currentLocale === 'fa' ? 'تغییر زبان سامانه' : 'Change Language'"
            aria-label="تغییر زبان"
        >
            <span class="text-sm leading-none">{{ currentLocale === 'fa' ? '🇮🇷' : '🇬🇧' }}</span>
            <span class="uppercase tracking-wider font-mono text-[11px]">{{ currentLocale }}</span>
            <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <div
                v-if="isOpen"
                class="absolute end-0 mt-2 w-36 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xl py-1.5 z-50 focus:outline-none"
            >
                <button
                    v-for="lang in languages"
                    :key="lang.code"
                    type="button"
                    @click="switchLanguage(lang.code as 'fa' | 'en')"
                    class="w-full text-start px-3 py-2 text-xs font-bold flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                    :class="currentLocale === lang.code ? 'text-indigo-600 dark:text-indigo-400 font-black bg-indigo-50/60 dark:bg-indigo-950/40' : 'text-slate-700 dark:text-slate-300'"
                >
                    <div class="flex items-center gap-2">
                        <span class="text-base">{{ lang.flag }}</span>
                        <span>{{ lang.name }}</span>
                    </div>
                    <span v-if="currentLocale === lang.code" class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                </button>
            </div>
        </Transition>
    </div>
</template>
