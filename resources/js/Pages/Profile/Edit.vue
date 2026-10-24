<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{
    status?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth?.user);

const activeTab = ref<'info' | 'security' | 'danger'>('info');
</script>

<template>
    <Head title="تنظیمات کاربری و پروفایل" />

    <UserLayout>
        <div class="space-y-4 pb-10">
            <!-- 1. Hero Profile Card -->
            <div class="dash-wallet-card !p-5 relative overflow-hidden">
                <!-- Background decorative ambient blur -->
                <div class="absolute -top-16 -right-16 w-40 h-40 bg-indigo-500/20 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute -bottom-16 -left-16 w-40 h-40 bg-purple-500/20 rounded-full blur-2xl pointer-events-none"></div>

                <div class="relative z-10 flex items-center gap-3.5">
                    <!-- Avatar with gradient ring -->
                    <div class="relative shrink-0">
                        <div class="w-14 h-14 rounded-2xl p-0.5 bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 shadow-md shadow-indigo-500/30">
                            <div class="w-full h-full rounded-[14px] bg-slate-900 flex items-center justify-center font-black text-xl text-white">
                                {{ user?.name ? user.name.charAt(0) : 'U' }}
                            </div>
                        </div>
                        <span class="status-dot-pulse absolute -bottom-0.5 -right-0.5 border-2 border-slate-900" title="وضعیت آنلاین"></span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-extrabold text-white truncate">
                                {{ user?.name || 'کاربر گرامی' }}
                            </h2>
                            <span
                                class="px-2 py-0.5 rounded-lg text-[10px] font-extrabold shrink-0"
                                :class="user?.is_admin || user?.role === 'admin'
                                    ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                                    : 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/30'"
                            >
                                {{ user?.is_admin || user?.role === 'admin' ? 'راهبر ارشد' : 'کاربر فعال' }}
                            </span>
                        </div>
                        <p class="text-xs text-indigo-200/80 font-mono mt-0.5 dir-ltr truncate">
                            @{{ user?.username }}
                        </p>
                        <div class="flex items-center gap-2 text-[10px] text-slate-400 mt-1">
                            <span>شناسه حساب: #{{ user?.id }}</span>
                            <span>•</span>
                            <span class="text-emerald-400">احراز شده و فعال</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Quick Access & External Services Row -->
            <div class="grid grid-cols-3 gap-2">
                <!-- API Tokens Shortcut -->
                <Link
                    :href="route('api-tokens.index')"
                    class="p-2.5 rounded-2xl bg-white/90 dark:bg-slate-900/60 border border-slate-200/90 dark:border-white/10 hover:border-cyan-500/40 transition-all flex flex-col items-center justify-center text-center group"
                >
                    <div class="w-8 h-8 rounded-xl bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 flex items-center justify-center mb-1 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <span class="text-[11px] font-bold text-slate-800 dark:text-slate-200">کلیدهای API</span>
                </Link>

                <!-- Tickets Support Shortcut -->
                <Link
                    :href="route('tickets.index')"
                    class="p-2.5 rounded-2xl bg-white/90 dark:bg-slate-900/60 border border-slate-200/90 dark:border-white/10 hover:border-indigo-500/40 transition-all flex flex-col items-center justify-center text-center group"
                >
                    <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center mb-1 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <span class="text-[11px] font-bold text-slate-800 dark:text-slate-200">پشتیبانی</span>
                </Link>

                <!-- Logout Button -->
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="p-2.5 rounded-2xl bg-rose-50/80 dark:bg-rose-950/20 border border-rose-200/80 dark:border-rose-900/40 hover:bg-rose-100/80 dark:hover:bg-rose-950/40 transition-all flex flex-col items-center justify-center text-center group w-full"
                >
                    <div class="w-8 h-8 rounded-xl bg-rose-500/15 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-1 group-hover:scale-105 transition-transform">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </div>
                    <span class="text-[11px] font-bold text-rose-600 dark:text-rose-400">خروج از حساب</span>
                </Link>
            </div>

            <!-- 3. Profile Navigation Tabs -->
            <div class="grid grid-cols-3 p-1 rounded-2xl bg-slate-200/70 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 text-xs font-bold">
                <button
                    type="button"
                    @click="activeTab = 'info'"
                    class="py-2.5 rounded-xl transition-all flex items-center justify-center gap-1.5"
                    :class="activeTab === 'info'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span>اطلاعات فردی</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'security'"
                    class="py-2.5 rounded-xl transition-all flex items-center justify-center gap-1.5"
                    :class="activeTab === 'security'
                        ? 'bg-white dark:bg-slate-800 text-purple-600 dark:text-purple-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span>گذرواژه</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'danger'"
                    class="py-2.5 rounded-xl transition-all flex items-center justify-center gap-1.5"
                    :class="activeTab === 'danger'
                        ? 'bg-white dark:bg-slate-800 text-rose-600 dark:text-rose-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <span>حذف حساب</span>
                </button>
            </div>

            <!-- 4. Active Tab Content Card -->
            <div class="glass-card !p-4 sm:!p-5 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <!-- Info Tab -->
                <div v-show="activeTab === 'info'">
                    <UpdateProfileInformationForm :status="status" />
                </div>

                <!-- Security / Password Tab -->
                <div v-show="activeTab === 'security'">
                    <UpdatePasswordForm />
                </div>

                <!-- Danger / Delete Account Tab -->
                <div v-show="activeTab === 'danger'">
                    <DeleteUserForm />
                </div>
            </div>
        </div>
    </UserLayout>
</template>
