<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="تنظیمات کاربری" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-xl font-black text-slate-900 dark:text-white">
                    تنظیمات کاربری
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">مدیریت اطلاعات هویتی و تنظیمات امنیتی حساب</p>
            </div>
        </template>

        <div class="py-4 space-y-6 max-w-4xl">
            <div class="p-6 sm:p-8 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <UpdateProfileInformationForm :status="status" />
            </div>

            <div class="p-6 sm:p-8 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <UpdatePasswordForm />
            </div>

            <div class="p-6 sm:p-8 bg-white dark:bg-slate-900 rounded-3xl border border-rose-200/60 dark:border-rose-950/60 shadow-sm">
                <DeleteUserForm />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
