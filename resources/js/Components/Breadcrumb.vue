<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { __ } from '@/Composables/useTrans';

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

const props = withDefaults(defineProps<{
    items?: BreadcrumbItem[];
    homeRoute?: string;
    homeLabel?: string;
}>(), {
    items: () => [],
    homeRoute: 'dashboard',
    homeLabel: '',
});

const resolvedHomeLabel = computed(() => props.homeLabel || __('ui.components.breadcrumb.home_default'));

// Auto-detected trail mapping when props.items is empty
const autoItems = computed<BreadcrumbItem[]>(() => {
    if (props.items && props.items.length > 0) {
        return props.items;
    }

    if (typeof window === 'undefined' || !(window as any).route) {
        return [];
    }

    const ziggy = (window as any).route;
    const currentRoute: string = ziggy().current() || '';

    // Route dictionaries for automatic breadcrumb trail
    const routeMap: Record<string, BreadcrumbItem[]> = {
        // User Routes
        'numbers.index': [{ label: __('ui.components.breadcrumb.my_numbers') }],
        'numbers.create': [{ label: __('ui.components.breadcrumb.my_numbers'), href: ziggy().has?.('numbers.index') ? ziggy('numbers.index') : undefined }, { label: __('ui.components.breadcrumb.allocate_number') }],
        'messages.index': [{ label: __('ui.components.breadcrumb.messages_inbox') }],
        'requests.index': [{ label: __('ui.components.breadcrumb.number_requests') }],
        'requests.create': [{ label: __('ui.components.breadcrumb.number_requests'), href: ziggy().has?.('requests.index') ? ziggy('requests.index') : undefined }, { label: __('ui.components.breadcrumb.submit_request') }],
        'tickets.index': [{ label: __('ui.components.breadcrumb.service_desk') }],
        'tickets.create': [{ label: __('ui.components.breadcrumb.service_desk'), href: ziggy().has?.('tickets.index') ? ziggy('tickets.index') : undefined }, { label: __('ui.components.breadcrumb.new_ticket') }],
        'tickets.show': [{ label: __('ui.components.breadcrumb.service_desk'), href: ziggy().has?.('tickets.index') ? ziggy('tickets.index') : undefined }, { label: __('ui.components.breadcrumb.view_conversation') }],
        'payments.index': [{ label: __('ui.components.breadcrumb.finance_transactions') }],
        'payments.checkout': [{ label: __('ui.components.breadcrumb.finance_transactions'), href: ziggy().has?.('payments.index') ? ziggy('payments.index') : undefined }, { label: __('ui.components.breadcrumb.payment_invoice') }],
        'profile.edit': [{ label: __('ui.components.breadcrumb.profile_security') }],
        'api-tokens.index': [{ label: __('ui.components.breadcrumb.api_tokens') }],

        // Admin Routes
        'admin.users.index': [{ label: __('ui.components.breadcrumb.admin_users') }],
        'admin.services.index': [{ label: __('ui.components.breadcrumb.admin_services') }],
        'admin.numbers.index': [{ label: __('ui.components.breadcrumb.admin_numbers') }],
        'admin.prefixes.index': [{ label: __('ui.components.breadcrumb.admin_prefixes') }],
        'admin.requests.index': [{ label: __('ui.components.breadcrumb.admin_requests') }],
        'admin.messages.index': [{ label: __('ui.components.breadcrumb.admin_messages') }],
        'admin.payments.index': [{ label: __('ui.components.breadcrumb.admin_payments') }],
        'admin.api-tokens.index': [{ label: __('ui.components.breadcrumb.admin_api_tokens') }],
        'admin.tickets.index': [{ label: __('ui.components.breadcrumb.admin_tickets') }],
        'admin.monitoring.index': [{ label: __('ui.components.breadcrumb.admin_monitoring') }],
        'admin.security.incidents.index': [{ label: __('ui.components.breadcrumb.admin_security_incidents') }],
        'admin.audit-logs.index': [{ label: __('ui.components.breadcrumb.admin_audit_logs') }],
    };

    for (const [key, trail] of Object.entries(routeMap)) {
        if (currentRoute === key || currentRoute.startsWith(key.replace('.index', ''))) {
            return trail;
        }
    }

    return [];
});
</script>

<template>
    <nav :aria-label="__('ui.components.breadcrumb.aria_label')" class="flex items-center text-xs text-slate-500 dark:text-slate-400 py-1.5 overflow-x-auto no-scrollbar">
        <ol class="flex items-center gap-1.5 min-w-max">
            <!-- Home Link -->
            <li class="inline-flex items-center">
                <Link
                    :href="route(props.homeRoute)"
                    class="inline-flex items-center gap-1 text-slate-600 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400 font-bold transition-colors"
                >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <span>{{ resolvedHomeLabel }}</span>
                </Link>
            </li>

            <!-- Dynamic Trail Items -->
            <template v-for="(item, idx) in autoItems" :key="idx">
                <li class="inline-flex items-center text-slate-400 dark:text-slate-600">
                    <svg class="w-3 h-3 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </li>

                <li class="inline-flex items-center">
                    <Link
                        v-if="item.href && idx < autoItems.length - 1"
                        :href="item.href"
                        class="hover:text-indigo-600 dark:hover:text-indigo-400 font-medium transition-colors"
                    >
                        {{ item.label }}
                    </Link>
                    <span
                        v-else
                        class="font-black text-slate-900 dark:text-slate-200"
                        aria-current="page"
                    >
                        {{ item.label }}
                    </span>
                </li>
            </template>
        </ol>
    </nav>
</template>
