<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout title="بازیابی کلمه عبور" subtitle="جهت دریافت پیوند تغییر کلمه عبور، نشانی رایانامه خود را وارد نمایید.">
        <Head title="بازیابی کلمه عبور" />

        <div
            v-if="status"
            class="mb-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 p-3 rounded-xl border border-emerald-200 dark:border-emerald-800"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
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

            <div class="pt-2">
                <PrimaryButton
                    class="w-full justify-center py-3 text-sm font-bold rounded-xl"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    ارسال پیوند بازیابی
                </PrimaryButton>
            </div>

            <div class="text-center pt-2 text-xs text-slate-500 dark:text-slate-400">
                <Link :href="route('login')" class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline">
                    بازگشت به صفحه ورود
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
