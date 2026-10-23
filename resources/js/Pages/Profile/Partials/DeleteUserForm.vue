<script setup lang="ts">
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value?.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => {
            form.reset();
        },
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-4">
        <header>
            <h2 class="text-base font-bold text-rose-600 dark:text-rose-400">
                حذف حساب کاربری
            </h2>

            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                با حذف حساب، دسترسی‌های شما ابطال شده و کلیه خطوط سازمانی تخصیص‌یافته به وضعیت معلق درخواهند آمد.
            </p>
        </header>

        <DangerButton
            @click="confirmUserDeletion"
            class="px-5 py-2.5 rounded-xl text-xs font-bold"
        >
            حذف حساب کاربری
        </DangerButton>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">
                    تأیید نهایی حذف حساب کاربری
                </h2>

                <p class="mt-2 text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                    این عملیات غیرقابل بازگشت است. جهت تأیید هویت و اعمال حذف، لطفاً کلمه عبور خود را وارد فرمایید.
                </p>

                <div class="mt-5">
                    <InputLabel
                        for="password"
                        value="کلمه عبور"
                        class="sr-only"
                    />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full text-left ltr"
                        placeholder="کلمه عبور خود را وارد فرمایید"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton
                        @click="closeModal"
                        class="px-4 py-2 text-xs rounded-xl"
                    >
                        انصراف
                    </SecondaryButton>

                    <DangerButton
                        class="px-4 py-2 text-xs rounded-xl"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        تأیید و حذف حساب
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>
