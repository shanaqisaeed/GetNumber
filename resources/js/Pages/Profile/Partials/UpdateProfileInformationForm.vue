<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    username: user.username,
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-base font-bold text-slate-900 dark:text-white">
                مشخصات حساب کاربری
            </h2>

            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                مشاهده و به‌روزرسانی عنوان سازمانی و شناسه کاربری سامانه.
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'))"
            class="mt-6 space-y-5 max-w-xl"
        >
            <div>
                <InputLabel for="name" value="عنوان سازمانی / نام و نام خانوادگی" />

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
                <InputLabel for="username" value="شناسه کاربری (نام کاربری)" />

                <TextInput
                    id="username"
                    type="text"
                    class="mt-1 block w-full text-left ltr"
                    v-model="form.username"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.username" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">ذخیره تغییرات</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-xs font-semibold text-emerald-600 dark:text-emerald-400"
                    >
                        تغییرات با موفقیت ذخیره گردید.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
