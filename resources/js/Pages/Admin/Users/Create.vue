<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    roles: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const form = useForm({
    name: '',
    username: '',
    password: '',
    role: 'user',
    status: 'active',
});

function submit() {
    form.post(route('admin.users.store'));
}
</script>

<template>
    <Head title="تعریف حساب کاربری جدید" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white">تعریف حساب کاربری جدید</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">صدور دسترسی جدید برای متقاضیان و همکاران سازمانی</p>
                </div>

                <Link
                    :href="route('admin.users.index')"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>بازگشت به فهرست</span>
                </Link>
            </div>
        </template>

        <div class="max-w-3xl bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-6 sm:p-8 shadow-sm">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Name -->
                <div>
                    <label for="name" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        عنوان سازمانی / نام و نام خانوادگی
                    </label>
                    <input
                        id="name"
                        type="text"
                        v-model="form.name"
                        required
                        autofocus
                        placeholder="مثال: شرکت ارتباطات یکپارچه"
                        class="block w-full px-4 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 outline-none transition-all"
                    />
                    <InputError class="mt-2 text-xs" :message="form.errors.name" />
                </div>

                <!-- Username -->
                <div>
                    <label for="username" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        شناسه کاربری یکتا (نام کاربری)
                    </label>
                    <input
                        id="username"
                        type="text"
                        v-model="form.username"
                        required
                        dir="ltr"
                        placeholder="مثال: tehran_agency"
                        class="block w-full px-4 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 outline-none transition-all text-right"
                    />
                    <InputError class="mt-2 text-xs" :message="form.errors.username" />
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        کلمه عبور اولیه
                    </label>
                    <input
                        id="password"
                        type="password"
                        v-model="form.password"
                        required
                        dir="ltr"
                        placeholder="حداقل ۸ نویسه امن"
                        class="block w-full px-4 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 outline-none transition-all"
                    />
                    <InputError class="mt-2 text-xs" :message="form.errors.password" />
                </div>

                <!-- Role and Status Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="role" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            نقش کاربری
                        </label>
                        <select
                            id="role"
                            v-model="form.role"
                            required
                            class="block w-full px-4 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
                        >
                            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                        <InputError class="mt-2 text-xs" :message="form.errors.role" />
                    </div>

                    <div>
                        <label for="status" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            وضعیت اولیه حساب
                        </label>
                        <select
                            id="status"
                            v-model="form.status"
                            required
                            class="block w-full px-4 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
                        >
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                        <InputError class="mt-2 text-xs" :message="form.errors.status" />
                    </div>
                </div>

                <!-- Buttons -->
                <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                    <Link
                        :href="route('admin.users.index')"
                        class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        انصراف
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-600/30 transition-all disabled:opacity-50"
                    >
                        ایجاد حساب کاربری
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
