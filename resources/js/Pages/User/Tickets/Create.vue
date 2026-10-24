<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface UserNumber {
    id: number;
    number: string;
    formatted_number: string;
    display_title: string;
}

const props = defineProps<{
    departments: Array<{ value: string; label: string }>;
    priorities: Array<{ value: string; label: string }>;
    userNumbers: UserNumber[];
}>();

const form = useForm({
    subject: '',
    department: 'technical',
    priority: 'medium',
    phone_number_id: null as number | null,
    message: '',
    attachments: [] as File[],
});

const fileInputRef = ref<HTMLInputElement | null>(null);
const fileError = ref<string | null>(null);

function onFileSelect(e: Event) {
    fileError.value = null;
    const target = e.target as HTMLInputElement;
    if (!target.files) return;

    const files = Array.from(target.files);
    if (form.attachments.length + files.length > 5) {
        fileError.value = 'حداکثر می‌توانید تا ۵ فایل پیوست نمایید.';
        return;
    }

    const allowed = ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'txt'];
    for (const f of files) {
        const ext = f.name.split('.').pop()?.toLowerCase();
        if (!ext || !allowed.includes(ext)) {
            fileError.value = `پسوند فایل «${f.name}» مجاز نمی‌باشد. فرمت‌های مجاز: JPG, PNG, PDF, ZIP, TXT.`;
            return;
        }
        if (f.size > 5 * 1024 * 1024) {
            fileError.value = `حجم فایل «${f.name}» بیش از سقف ۵ مگابایت است.`;
            return;
        }
        form.attachments.push(f);
    }

    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
}

function removeAttachment(index: number) {
    form.attachments.splice(index, 1);
}

function formatFileSize(bytes: number): string {
    if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(1) + ' مگابایت';
    }
    return (bytes / 1024).toFixed(0) + ' کیلوبایت';
}

function submit() {
    form.post(route('tickets.store'), {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="ثبت تیکت پشتیبانی جدید" />

    <UserLayout>
        <div class="space-y-4">
            <!-- Header Card with Back Button -->
            <div class="glass-card !p-3.5 flex items-center justify-between gap-3 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                <div class="flex items-center gap-2.5 min-w-0">
                    <Link
                        :href="route('tickets.index')"
                        class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white flex items-center justify-center shrink-0 transition-colors"
                        title="بازگشت به لیست تیکت‌ها"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </Link>
                    <div class="min-w-0">
                        <h1 class="text-xs font-black text-slate-900 dark:text-white">ثبت تیکت پشتیبانی جدید</h1>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 truncate">پاسخگویی سریع توسط تیم پشتیبانی فنی</p>
                    </div>
                </div>
            </div>

            <!-- Form Card -->
            <form @submit.prevent="submit" class="glass-card !p-4 space-y-3.5 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                <!-- Subject -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                        موضوع تیکت <span class="text-rose-500">*</span>
                    </label>
                    <input
                        v-model="form.subject"
                        type="text"
                        placeholder="عنوان خلاصه (مثال: بررسی اختلال وب‌هوک)"
                        class="glass-input !py-2.5 !text-xs w-full bg-slate-100/90 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                        required
                    />
                    <p v-if="form.errors.subject" class="text-rose-500 text-[10px] font-bold mt-1">{{ form.errors.subject }}</p>
                </div>

                <!-- Department & Priority -->
                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                            دپارتمان <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model="form.department"
                            class="glass-input !py-2.5 !px-2.5 !text-xs w-full bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white cursor-pointer"
                        >
                            <option v-for="d in departments" :key="d.value" :value="d.value" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">{{ d.label }}</option>
                        </select>
                        <p v-if="form.errors.department" class="text-rose-500 text-[10px] font-bold mt-1">{{ form.errors.department }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                            اولویت <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model="form.priority"
                            class="glass-input !py-2.5 !px-2.5 !text-xs w-full bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white cursor-pointer"
                        >
                            <option v-for="p in priorities" :key="p.value" :value="p.value" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">{{ p.label }}</option>
                        </select>
                        <p v-if="form.errors.priority" class="text-rose-500 text-[10px] font-bold mt-1">{{ form.errors.priority }}</p>
                    </div>
                </div>

                <!-- Optional Linked Number -->
                <div v-if="userNumbers.length > 0">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                        خط سازمانی مرتبط (اختیاری):
                    </label>
                    <select
                        v-model="form.phone_number_id"
                        class="glass-input !py-2.5 !px-2.5 !text-xs w-full bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white cursor-pointer"
                    >
                        <option :value="null" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">بدون اتصال به خط مشخص</option>
                        <option v-for="num in userNumbers" :key="num.id" :value="num.id" class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
                            {{ num.formatted_number }} - {{ num.display_title }}
                        </option>
                    </select>
                </div>

                <!-- Message -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                        شرح درخواست یا پیام <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        v-model="form.message"
                        rows="5"
                        placeholder="متن پیام و جزئیات دقیق مسئله را بنویسید..."
                        class="glass-input !p-3 !text-xs w-full resize-none leading-relaxed bg-slate-100/90 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                        required
                    ></textarea>
                    <p v-if="form.errors.message" class="text-rose-500 text-[10px] font-bold mt-1">{{ form.errors.message }}</p>
                </div>

                <!-- Attachments Dropzone -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                        پیوست تصویر یا سند (اختیاری):
                    </label>

                    <div
                        @click="fileInputRef?.click()"
                        class="border-2 border-dashed border-slate-300 dark:border-white/15 hover:border-indigo-500 dark:hover:border-indigo-400 rounded-2xl p-4 text-center cursor-pointer transition-colors bg-slate-50 dark:bg-white/5"
                    >
                        <svg class="w-6 h-6 mx-auto text-indigo-600 dark:text-indigo-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p class="text-xs font-bold text-slate-800 dark:text-white">برای انتخاب فایل کلیک کنید</p>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">JPG, PNG, PDF, ZIP (حداکثر ۵ فایل تا ۵ مگابایت)</p>
                        <input
                            ref="fileInputRef"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.zip,.txt"
                            class="hidden"
                            @change="onFileSelect"
                        />
                    </div>

                    <p v-if="fileError" class="text-rose-500 text-[10px] font-bold mt-1">{{ fileError }}</p>

                    <!-- Selected Files -->
                    <div v-if="form.attachments.length > 0" class="mt-2 space-y-1.5">
                        <div
                            v-for="(file, idx) in form.attachments"
                            :key="idx"
                            class="p-2 rounded-xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between text-xs"
                        >
                            <span class="text-slate-800 dark:text-white font-mono truncate max-w-[200px]" dir="ltr">{{ file.name }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-slate-500 dark:text-slate-400 font-mono">{{ formatFileSize(file.size) }}</span>
                                <button type="button" @click="removeAttachment(idx)" class="text-rose-500 hover:text-rose-700">✕</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-end gap-2">
                    <Link
                        :href="route('tickets.index')"
                        class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300"
                    >
                        انصراف
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl disabled:opacity-40"
                    >
                        {{ form.processing ? 'در حال ثبت...' : 'ارسال و ثبت تیکت' }}
                    </button>
                </div>
            </form>
        </div>
    </UserLayout>
</template>
