<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <AuthLayout title="تأیید امنیتی هویت" subtitle="جهت ورود به این بخش حساس، لطفاً کلمه عبور خود را تأیید فرمایید.">
        <Head title="تأیید کلمه عبور" />

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="password" value="کلمه عبور" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="pt-2">
                <PrimaryButton
                    class="w-full justify-center py-3 text-sm font-bold rounded-xl"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    تأیید و ادامه
                </PrimaryButton>
            </div>
        </form>
    </AuthLayout>
</template>
