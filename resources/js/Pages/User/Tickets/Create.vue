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
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-base font-bold text-slate-900 dark:text-white">ثبت تیکت پشتیبانی جدید</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">درخواست راهنمایی، پیگیری و گزارش موارد به کارشناسان سامانه</p>
                </div>
                <Link
                    :href="route('tickets.index')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>بازگشت به لیست تیکت‌ها</span>
                </Link>
            </div>
        </template>

        <div class="max-w-3xl mx-auto">
            <form @submit.prevent="submit" class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-6">
                <!-- Subject -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        موضوع تیکت <span class="text-rose-500">*</span>
                    </label>
                    <input
                        v-model="form.subject"
                        type="text"
                        placeholder="عنوان خلاصه و گویای تیکت (مثال: اختلال در دریافت وب‌هوک خط ۹ رقمی)"
                        class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 py-2.5 px-3.5"
                        required
                    />
                    <p v-if="form.errors.subject" class="text-rose-500 text-[11px] font-bold mt-1">{{ form.errors.subject }}</p>
                </div>

                <!-- Department & Priority -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            دپارتمان مربوطه <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model="form.department"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 py-2.5"
                        >
                            <option v-for="d in departments" :key="d.value" :value="d.value">{{ d.label }}</option>
                        </select>
                        <p v-if="form.errors.department" class="text-rose-500 text-[11px] font-bold mt-1">{{ form.errors.department }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            اولویت بررسی <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model="form.priority"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 py-2.5"
                        >
                            <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                        <p v-if="form.errors.priority" class="text-rose-500 text-[11px] font-bold mt-1">{{ form.errors.priority }}</p>
                    </div>
                </div>

                <!-- Optional Linked Phone Number -->
                <div v-if="userNumbers.length > 0">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        ارتباط با خط سازمانی (اختیاری)
                    </label>
                    <select
                        v-model="form.phone_number_id"
                        class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 py-2.5"
                    >
                        <option :value="null">بدون اتصال به خط مشخص</option>
                        <option v-for="num in userNumbers" :key="num.id" :value="num.id">
                            {{ num.formatted_number }} - {{ num.display_title }}
                        </option>
                    </select>
                </div>

                <!-- Message Textarea -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        متن پیام و شرح کامل مسئله <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        v-model="form.message"
                        rows="6"
                        placeholder="توضیحات دقیق، کدهای خطا یا رفتار مشاهده‌شده را در این بخش تشریح نمایید..."
                        class="w-full text-xs rounded-2xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 p-3.5 leading-relaxed"
                        required
                    ></textarea>
                    <p v-if="form.errors.message" class="text-rose-500 text-[11px] font-bold mt-1">{{ form.errors.message }}</p>
                </div>

                <!-- Attachments Dropzone -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                        فایل‌های پیوست (اختیاری)
                    </label>

                    <div
                        @click="fileInputRef?.click()"
                        class="border-2 border-dashed border-slate-300 dark:border-slate-700 hover:border-indigo-500 dark:hover:border-indigo-400 rounded-2xl p-5 text-center cursor-pointer transition-colors bg-slate-50/50 dark:bg-slate-800/30"
                    >
                        <svg class="w-8 h-8 mx-auto text-slate-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-300">برای بارگذاری فایل کلیک کنید یا فایل را بکشید و رها کنید</p>
                        <p class="text-[11px] text-slate-400 mt-1">فرمت‌های مجاز: JPG, PNG, PDF, ZIP, TXT (حداکثر ۵ فایل، هر کدام تا سقف ۵ مگابایت)</p>
                        <input
                            ref="fileInputRef"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.zip,.txt"
                            class="hidden"
                            @change="onFileSelect"
                        />
                    </div>

                    <p v-if="fileError" class="text-rose-500 text-[11px] font-bold mt-1">{{ fileError }}</p>
                    <p v-if="form.errors.attachments" class="text-rose-500 text-[11px] font-bold mt-1">{{ form.errors.attachments }}</p>

                    <!-- Selected Files List -->
                    <div v-if="form.attachments.length > 0" class="mt-3 space-y-2">
                        <div
                            v-for="(file, idx) in form.attachments"
                            :key="idx"
                            class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-between text-xs"
                        >
                            <div class="flex items-center gap-2 truncate">
                                <svg class="w-4 h-4 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                </svg>
                                <span class="font-medium text-slate-800 dark:text-slate-200 truncate" dir="ltr">{{ file.name }}</span>
                                <span class="text-slate-400 text-[10px] font-mono shrink-0">({{ formatFileSize(file.size) }})</span>
                            </div>
                            <button
                                type="button"
                                @click="removeAttachment(idx)"
                                class="p-1 text-slate-400 hover:text-rose-500 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Form Action Buttons -->
                <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-3">
                    <Link
                        :href="route('tickets.index')"
                        class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        انصراف
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-bold shadow-md shadow-indigo-600/20 transition-all cursor-pointer"
                    >
                        <span v-if="form.processing">در حال ارسال...</span>
                        <span v-else>ارسال و ثبت تیکت</span>
                    </button>
                </div>
            </form>
        </div>
    </UserLayout>
</template>
