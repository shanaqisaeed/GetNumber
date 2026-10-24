<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    status?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <Head title="تنظیمات کاربری و امنیت" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">تنظیمات حساب کاربری</h1>
        </template>

        <div class="py-2 w-full space-y-6">
            <!-- 2026 Hero Profile Showcase Card (Full Width) -->
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white p-6 sm:p-8 border border-indigo-500/20 shadow-xl shadow-indigo-950/20">
                <!-- Decorative ambient backdrop orbs -->
                <div class="absolute -top-24 -right-24 w-72 h-72 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-24 -left-24 w-72 h-72 bg-purple-500/20 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute inset-0 bg-[radial-gradient(#ffffff0a_1px,transparent_1px)] [background-size:16px_16px] pointer-events-none"></div>

                <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                    <div class="flex items-center gap-4 sm:gap-5">
                        <!-- Avatar with multi-color gradient border ring -->
                        <div class="relative">
                            <div class="w-18 h-18 sm:w-20 sm:h-20 rounded-2xl sm:rounded-3xl p-1 bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 shadow-lg shadow-indigo-500/30">
                                <div class="w-full h-full rounded-[14px] sm:rounded-[20px] bg-slate-900 flex items-center justify-center font-black text-2xl sm:text-3xl text-white">
                                    {{ user?.name ? user.name.charAt(0) : 'U' }}
                                </div>
                            </div>
                            <span class="absolute -bottom-1 -left-1 w-5 h-5 rounded-full bg-emerald-500 border-2 border-slate-900 flex items-center justify-center" title="وضعیت آنلاین">
                                <span class="w-2 h-2 rounded-full bg-white animate-ping"></span>
                            </span>
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-xl sm:text-2xl font-black text-white tracking-tight">
                                    {{ user?.name }}
                                </h2>
                                <span
                                    class="px-2.5 py-0.5 rounded-lg text-[11px] font-bold"
                                    :class="user?.is_admin || user?.role === 'admin'
                                        ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                                        : 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/30'"
                                >
                                    {{ user?.is_admin || user?.role === 'admin' ? 'راهبر ارشد سامانه' : 'کاربر سازمانی' }}
                                </span>
                            </div>
                            <p class="text-xs text-indigo-200/80 font-mono mt-1 dir-ltr flex items-center gap-1.5">
                                <span>@{{ user?.username }}</span>
                                <span class="text-slate-500">•</span>
                                <span class="text-[11px] text-slate-400">شناسه اختصاصی: {{ user?.id }}#</span>
                            </p>
                        </div>
                    </div>

                    <!-- Modern Telemetry Badges -->
                    <div class="flex flex-wrap md:flex-col lg:flex-row gap-2.5 w-full md:w-auto">
                        <div class="px-3.5 py-2 rounded-2xl bg-white/5 border border-white/10 backdrop-blur-md flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-300">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div class="text-right">
                                <span class="block text-[10px] text-slate-400">سرویس فعال</span>
                                <span class="block text-xs font-bold text-white">خطوط هوشمند سازمانی</span>
                            </div>
                        </div>

                        <div class="px-3.5 py-2 rounded-2xl bg-white/5 border border-white/10 backdrop-blur-md flex items-center gap-2.5">
                            <div class="w-7 h-7 rounded-lg bg-emerald-500/20 flex items-center justify-center text-emerald-300">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                            <div class="text-right">
                                <span class="block text-[10px] text-slate-400">سطح امنیت</span>
                                <span class="block text-xs font-bold text-white">رمزنگاری سرتاسری ۲۵۶ بیتی</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bento Grid 2026: Profile Info + Password Security (Side by Side on Large Screens) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 w-full">
                <!-- Left Bento: Profile Information -->
                <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-6 sm:p-8 shadow-sm flex flex-col justify-between">
                    <UpdateProfileInformationForm :status="status" />
                </div>

                <!-- Right Bento: Password Security -->
                <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-6 sm:p-8 shadow-sm flex flex-col justify-between">
                    <UpdatePasswordForm />
                </div>
            </div>

            <!-- Danger Zone / Delete Account (Full Width 2026 Security Style) -->
            <div class="w-full bg-rose-500/[0.03] dark:bg-rose-950/20 rounded-3xl border border-rose-200/70 dark:border-rose-900/40 p-6 sm:p-8 shadow-sm">
                <DeleteUserForm />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
