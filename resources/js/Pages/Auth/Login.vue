<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <AuthLayout title="ورود به سامانه" subtitle="جهت دسترسی به خدمات، مشخصات کاربری خود را وارد فرمایید.">
        <Head title="ورود به سامانه" />

        <div v-if="status" class="mb-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 p-3 rounded-xl border border-emerald-200 dark:border-emerald-800">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <InputLabel for="email" value="نشانی رایانامه (ایمیل)" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="name@example.com"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <InputLabel for="password" value="کلمه عبور" />
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        بازیابی کلمه عبور
                    </Link>
                </div>

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex items-center">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="text-xs text-slate-600 dark:text-slate-400">ذخیره نشست کاربری</span>
                </label>
            </div>

            <div>
                <PrimaryButton
                    class="w-full justify-center py-3 text-sm font-bold rounded-xl"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    ورود به سامانه
                </PrimaryButton>
            </div>

            <div class="text-center pt-2 text-xs text-slate-500 dark:text-slate-400">
                حساب کاربری ندارید؟
                <Link :href="route('register')" class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline mr-1">
                    ثبت‌نام متقاضی
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
