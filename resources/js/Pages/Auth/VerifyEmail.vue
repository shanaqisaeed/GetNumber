<script setup lang="ts">
import { computed } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <AuthLayout title="تأیید نشانی رایانامه" subtitle="پیوند فعال‌سازی به نشانی ثبت‌شده ارسال گردیده است.">
        <Head title="تأیید نشانی رایانامه" />

        <div class="mb-4 text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
            جهت تکمیل فرآیند احراز هویت، لطفاً پیوند ارسال‌شده به رایانامه خود را تأیید فرمایید. در صورت عدم دریافت پیام، می‌توانید درخواست ارسال مجدد ثبت نمایید.
        </div>

        <div
            class="mb-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/50 p-3 rounded-xl border border-emerald-200 dark:border-emerald-800"
            v-if="verificationLinkSent"
        >
            پیوند تأیید جدید با موفقیت به نشانی رایانامه شما ارسال شد.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex flex-col gap-3">
                <PrimaryButton
                    class="w-full justify-center py-3 text-sm font-bold rounded-xl"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    ارسال مجدد پیوند تأیید
                </PrimaryButton>

                <div class="text-center">
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="text-xs text-slate-500 hover:text-rose-500 underline transition-colors"
                    >
                        خروج از سامانه
                    </Link>
                </div>
            </div>
        </form>
    </AuthLayout>
</template>
