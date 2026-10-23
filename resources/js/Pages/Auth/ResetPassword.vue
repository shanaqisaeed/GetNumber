<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    email: string;
    token: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout title="تعیین کلمه عبور جدید" subtitle="لطفاً کلمه عبور جدید حساب کاربری خود را وارد فرمایید.">
        <Head title="تعیین کلمه عبور جدید" />

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
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="کلمه عبور جدید" />

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
                <InputLabel
                    for="password_confirmation"
                    value="تکرار کلمه عبور جدید"
                />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError
                    class="mt-2"
                    :message="form.errors.password_confirmation"
                />
            </div>

            <div class="pt-2">
                <PrimaryButton
                    class="w-full justify-center py-3 text-sm font-bold rounded-xl"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    ثبت و ذخیره کلمه عبور جدید
                </PrimaryButton>
            </div>
        </form>
    </AuthLayout>
</template>
