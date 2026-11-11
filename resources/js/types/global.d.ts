import { PageProps as InertiaPageProps } from '@inertiajs/core';
import { AxiosInstance } from 'axios';
import { route as ziggyRoute } from 'ziggy-js';
import { PageProps as AppPageProps } from './';

declare global {
    interface Window {
        axios: AxiosInstance;
        __: (key: string, replace?: Record<string, any>) => string;
        $t: (key: string, replace?: Record<string, any>) => string;
    }

    /* eslint-disable no-var */
    var route: typeof ziggyRoute;
    var __: (key: string, replace?: Record<string, any>) => string;
    var $t: (key: string, replace?: Record<string, any>) => string;
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof ziggyRoute;
        __: (key: string, replace?: Record<string, any>) => string;
        $t: (key: string, replace?: Record<string, any>) => string;
    }
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}
