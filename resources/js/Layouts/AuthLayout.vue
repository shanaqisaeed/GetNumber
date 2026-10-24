<script setup lang="ts">
import { useTheme } from '@/Composables/useTheme';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    subtitle?: string;
}>();

const { toggleTheme } = useTheme();
</script>

<template>
    <div class="min-h-screen w-full flex items-center justify-center p-0 sm:p-4 relative overflow-x-hidden">
        <!-- 3D Liquid Canvas Ambient Glows -->
        <div class="ambient-mesh" aria-hidden="true">
            <div class="liquid-orb orb-1"></div>
            <div class="liquid-orb orb-2"></div>
            <div class="liquid-orb orb-3"></div>
        </div>

        <!-- Centered Mobile Container on Desktop (Fills viewport on real mobile <640px) -->
        <div class="app-container">
            <!-- Screen Scroll Container -->
            <div class="screen-scroll-container">
                <!-- Top Header Bar with Brand Link and Direct Theme Switcher -->
                <div class="flex items-center justify-between mb-4">
                    <Link href="/" class="glass-pill text-indigo-400 dark:text-indigo-400 font-bold hover:opacity-85 transition-opacity" title="بازگشت به صفحه اصلی">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                        <span>بازگشت به صفحه اصلی</span>
                    </Link>

                    <button
                        type="button"
                        class="app-theme-toggle-btn"
                        @click="toggleTheme"
                        title="تغییر تم تاریک / روشن"
                    >
                        <svg class="theme-icon-sun w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                            <line x1="1" y1="12" x2="3" y2="12"></line>
                            <line x1="21" y1="12" x2="23" y2="12"></line>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                        </svg>
                        <svg class="theme-icon-moon w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>
                </div>

                <!-- Page Slot (Login / Register Content) -->
                <slot />
            </div>
        </div>
    </div>
</template>
