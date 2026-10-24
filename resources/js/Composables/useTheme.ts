import { ref, readonly } from 'vue';

export type ThemeMode = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'getnumber_theme';

const currentTheme = ref<ThemeMode>('system');
const isDarkMode = ref<boolean>(false);

function getSavedTheme(): ThemeMode {
    if (typeof window === 'undefined') return 'system';
    const saved = localStorage.getItem(STORAGE_KEY) as ThemeMode | null;
    if (saved === 'light' || saved === 'dark' || saved === 'system') {
        return saved;
    }
    return 'system';
}

function resolveIsDark(mode: ThemeMode): boolean {
    if (typeof window === 'undefined') return false;
    if (mode === 'dark') return true;
    if (mode === 'light') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function applyThemeToDOM(dark: boolean) {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    if (dark) {
        root.classList.add('dark');
        root.classList.remove('light');
        root.style.colorScheme = 'dark';
    } else {
        root.classList.remove('dark');
        root.classList.add('light');
        root.style.colorScheme = 'light';
    }
}

export function initTheme(): void {
    if (typeof window === 'undefined') return;

    currentTheme.value = getSavedTheme();
    isDarkMode.value = resolveIsDark(currentTheme.value);
    applyThemeToDOM(isDarkMode.value);

    // Listen for OS system theme changes
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    mediaQuery.addEventListener('change', (e) => {
        if (currentTheme.value === 'system') {
            isDarkMode.value = e.matches;
            applyThemeToDOM(isDarkMode.value);
        }
    });
}

export function useTheme() {
    function setTheme(mode: ThemeMode) {
        currentTheme.value = mode;
        localStorage.setItem(STORAGE_KEY, mode);
        isDarkMode.value = resolveIsDark(mode);
        applyThemeToDOM(isDarkMode.value);
    }

    function toggleTheme() {
        if (isDarkMode.value) {
            setTheme('light');
        } else {
            setTheme('dark');
        }
    }

    return {
        theme: readonly(currentTheme),
        isDark: readonly(isDarkMode),
        setTheme,
        toggleTheme,
    };
}
