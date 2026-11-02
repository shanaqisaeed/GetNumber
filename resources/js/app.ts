import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initTheme } from './Composables/useTheme';

// Initialize theme immediately to prevent any flash of unstyled theme
initTheme();

const appName = import.meta.env.VITE_APP_NAME || 'GetNumber';

// Global Client-Side Error Reporting to Backend
const reportClientError = (err: any, info?: string) => {
    try {
        const payload = {
            message: err?.message || String(err),
            file: err?.fileName || window.location.pathname,
            line: err?.lineNumber || null,
            stack: err?.stack || info || null,
            url: window.location.href,
        };

        if (navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            navigator.sendBeacon('/api/telemetry/errors', blob);
        } else {
            fetch('/api/telemetry/errors', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true,
            }).catch(() => {});
        }
    } catch {
        // Silently tolerate telemetry errors
    }
};

window.addEventListener('error', (event) => {
    reportClientError(event.error || event.message);
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const vueApp = createApp({ render: () => h(App, props) });

        vueApp.config.errorHandler = (err, instance, info) => {
            console.error('Unhandled Vue Error:', err, info);
            reportClientError(err, info);
        };

        vueApp.use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#6366f1',
    },
});
