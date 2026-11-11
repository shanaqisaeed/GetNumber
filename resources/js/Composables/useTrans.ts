import { usePage } from '@inertiajs/vue3';

let cachedTranslations: Record<string, any> = {};

export function setTranslations(translations: Record<string, any>): void {
    if (translations && typeof translations === 'object') {
        cachedTranslations = translations;
    }
}

export function __(key: string, replace: Record<string, any> = {}): string {
    let translations = cachedTranslations;

    try {
        const page = usePage();
        if (page?.props?.translations && typeof page.props.translations === 'object') {
            translations = page.props.translations as Record<string, any>;
            cachedTranslations = translations;
        }
    } catch {
        // usePage may not be available outside component setup
    }

    const parts = key.split('.');
    let current: any = translations;

    for (const part of parts) {
        if (current && typeof current === 'object' && part in current) {
            current = current[part];
        } else {
            current = null;
            break;
        }
    }

    let translation = typeof current === 'string' ? current : key;

    for (const [param, val] of Object.entries(replace)) {
        translation = translation
            .replace(new RegExp(`:${param}\\b`, 'g'), String(val))
            .replace(new RegExp(`\\{${param}\\}`, 'g'), String(val));
    }

    return translation;
}

export function t(key: string, replace?: Record<string, any>): string {
    return __(key, replace);
}

export function useTrans() {
    return {
        __,
        t,
        setTranslations,
    };
}

