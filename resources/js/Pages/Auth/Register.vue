<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout title="ثبت‌نام در سامانه" subtitle="جهت دریافت خطوط سازمانی و دسترسی به خدمات، مشخصات خود را وارد نمایید.">
        <Head title="ثبت‌نام در سامانه" />

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="name" value="نام و نام خانوادگی / عنوان سازمانی" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="نشانی رایانامه (ایمیل)" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.email"
                    required
                    autocomplete="username"
                    placeholder="name@example.com"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="کلمه عبور" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="تکرار کلمه عبور" />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div class="pt-2">
                <PrimaryButton
                    class="w-full justify-center py-3 text-sm font-bold rounded-xl"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    ثبت‌نام و ایجاد حساب
                </PrimaryButton>
            </div>

            <div class="text-center pt-2 text-xs text-slate-500 dark:text-slate-400">
                پیش‌تر ثبت‌نام کرده‌اید؟
                <Link :href="route('login')" class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline mr-1">
                    ورود به سامانه
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
