<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: Boolean;
    status?: String;
}>();

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-base font-bold text-slate-900 dark:text-white">
                اطلاعات کاربری
            </h2>

            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                بروزرسانی مشخصات سازمانی و نشانی رایانامه حساب کاربری.
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="mt-6 space-y-5 max-w-xl"
        >
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
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                    نشانی رایانامه شما تأیید نشده است.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="underline hover:text-amber-700 dark:hover:text-amber-300 mr-1"
                    >
                        جهت ارسال مجدد پیوند تأیید اینجا کلیک نمایید.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-xs font-semibold text-emerald-600 dark:text-emerald-400"
                >
                    پیوند تأیید جدید به رایانامه شما ارسال شد.
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <PrimaryButton
                    class="px-5 py-2.5 rounded-xl text-sm font-bold"
                    :disabled="form.processing"
                >
                    ذخیره تغییرات
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-xs font-medium text-emerald-600 dark:text-emerald-400"
                    >
                        اطلاعات با موفقیت ذخیره شد.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
