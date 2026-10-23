<script setup lang="ts">
import { useTheme, type ThemeMode } from '@/Composables/useTheme';
import { ref, onMounted, onUnmounted } from 'vue';

const { theme, isDark, setTheme } = useTheme();
const isOpen = ref(false);
const dropdownRef = ref<HTMLDivElement | null>(null);

function selectTheme(mode: ThemeMode) {
    setTheme(mode);
    isOpen.value = false;
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
    <div class="relative inline-block text-right" ref="dropdownRef">
        <button
            type="button"
            @click="isOpen = !isOpen"
            class="inline-flex items-center justify-center p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition-colors"
            :title="isDark ? 'حالت تاریک' : 'حالت روشن'"
            aria-label="تغییر تم"
        >
            <!-- Sun icon when light -->
            <svg
                v-if="!isDark"
                class="w-5 h-5 text-amber-500 transition-transform duration-200"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"
                />
            </svg>
            <!-- Moon icon when dark -->
            <svg
                v-else
                class="w-5 h-5 text-indigo-400 transition-transform duration-200"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"
                />
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
                class="absolute left-0 mt-2 w-36 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xl py-1 z-50 focus:outline-none"
            >
                <button
                    type="button"
                    @click="selectTheme('light')"
                    class="w-full text-right px-3 py-2 text-xs font-medium flex items-center gap-2 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    :class="theme === 'light' ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-slate-700 dark:text-slate-300'"
                >
                    <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span>روشن</span>
                </button>

                <button
                    type="button"
                    @click="selectTheme('dark')"
                    class="w-full text-right px-3 py-2 text-xs font-medium flex items-center gap-2 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    :class="theme === 'dark' ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-slate-700 dark:text-slate-300'"
                >
                    <svg class="w-4 h-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                    <span>تاریک</span>
                </button>

                <button
                    type="button"
                    @click="selectTheme('system')"
                    class="w-full text-right px-3 py-2 text-xs font-medium flex items-center gap-2 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    :class="theme === 'system' ? 'text-indigo-600 dark:text-indigo-400 font-bold' : 'text-slate-700 dark:text-slate-300'"
                >
                    <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span>سیستم</span>
                </button>
            </div>
        </Transition>
    </div>
</template>
