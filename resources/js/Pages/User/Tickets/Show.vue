<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface AttachmentItem {
    id: number;
    file_name: string;
    file_size: string;
    mime_type: string;
    is_image: boolean;
    download_url: string;
}

interface MessageUser {
    id: number;
    name: string;
    username: string;
    role: string;
    role_label: string;
}

interface MessageItem {
    id: number;
    message: string;
    is_staff_reply: boolean;
    user: MessageUser;
    created_at: string;
    created_at_human: string;
    attachments: AttachmentItem[];
}

interface TicketData {
    id: number;
    ticket_number: string;
    subject: string;
    department: string;
    department_label: string;
    department_badge: string;
    priority: string;
    priority_label: string;
    priority_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    is_closed: boolean;
    phone_number: {
        id: number;
        number: string;
        formatted_number: string;
        display_title: string;
    } | null;
    created_at: string;
    messages: MessageItem[];
}

const props = defineProps<{
    ticket: TicketData;
}>();

const replyForm = useForm({
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
    if (replyForm.attachments.length + files.length > 5) {
        fileError.value = 'حداکثر می‌توانید تا ۵ فایل پیوست نمایید.';
        return;
    }

    const allowed = ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'txt'];
    for (const f of files) {
        const ext = f.name.split('.').pop()?.toLowerCase();
        if (!ext || !allowed.includes(ext)) {
            fileError.value = `پسوند «${f.name}» غیرمجاز است. فرمت‌های مجاز: JPG, PNG, PDF, ZIP, TXT.`;
            return;
        }
        if (f.size > 5 * 1024 * 1024) {
            fileError.value = `حجم فایل «${f.name}» بیش از سقف ۵ مگابایت است.`;
            return;
        }
        replyForm.attachments.push(f);
    }

    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
}

function removeAttachment(index: number) {
    replyForm.attachments.splice(index, 1);
}

function submitReply() {
    replyForm.post(route('tickets.reply', props.ticket.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            replyForm.reset();
            replyForm.attachments = [];
        },
    });
}

function closeTicket() {
    if (!confirm('آیا از بستن این تیکت اطمینان دارید؟')) return;
    router.post(route('tickets.close', props.ticket.id), {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`تیکت #${ticket.ticket_number}: ${ticket.subject}`" />

    <UserLayout>
        <div class="space-y-4">
            <!-- 1. Header Card with Back Button & Actions -->
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
                        <div class="flex items-center gap-1.5">
                            <span class="font-mono text-xs font-black text-indigo-600 dark:text-indigo-400">#{{ ticket.ticket_number }}</span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shadow-xs" :class="ticket.status_badge">
                                {{ ticket.status_label }}
                            </span>
                        </div>
                        <h1 class="text-xs font-black text-slate-900 dark:text-white truncate mt-0.5">
                            {{ ticket.subject }}
                        </h1>
                    </div>
                </div>

                <div class="shrink-0">
                    <button
                        v-if="!ticket.is_closed"
                        type="button"
                        @click="closeTicket"
                        class="text-[10px] font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300 border border-rose-300 dark:border-rose-500/30 px-2.5 py-1 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors"
                    >
                        بستن تیکت
                    </button>
                    <span
                        v-else
                        class="text-[10px] font-bold text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 px-2.5 py-1 rounded-xl"
                    >
                        بسته‌شده
                    </span>
                </div>
            </div>

            <!-- 2. Meta Pills Card -->
            <div class="glass-card !p-3 flex flex-wrap items-center justify-between gap-2 text-[11px] bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shadow-xs" :class="ticket.department_badge">
                        {{ ticket.department_label }}
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shadow-xs" :class="ticket.priority_badge">
                        {{ ticket.priority_label }}
                    </span>
                    <span v-if="ticket.phone_number" class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 font-bold bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-full border border-indigo-200 dark:border-indigo-500/20" dir="ltr">
                        {{ ticket.phone_number.formatted_number }}
                    </span>
                </div>

                <span class="text-[10px] text-slate-500 dark:text-slate-400 font-mono" dir="ltr">
                    {{ ticket.created_at }}
                </span>
            </div>

            <!-- 3. Message Stream -->
            <div class="space-y-3">
                <div
                    v-for="msg in ticket.messages"
                    :key="msg.id"
                    class="glass-card !p-3.5 space-y-2.5"
                    :class="[
                        msg.is_staff_reply
                            ? 'bg-indigo-50/70 dark:bg-indigo-950/25 border-indigo-200/90 dark:border-indigo-500/30'
                            : 'bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10'
                    ]"
                >
                    <!-- Author Row -->
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200/60 dark:border-white/5">
                        <div class="flex items-center gap-2">
                            <div
                                class="w-7 h-7 rounded-xl flex items-center justify-center font-bold text-[10px]"
                                :class="[
                                    msg.is_staff_reply
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'bg-slate-100 dark:bg-white/10 text-slate-800 dark:text-white'
                                ]"
                            >
                                {{ msg.is_staff_reply ? 'تیم' : 'شما' }}
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-black text-slate-900 dark:text-white">{{ msg.user.name }}</span>
                                <span
                                    v-if="msg.is_staff_reply"
                                    class="text-[9px] px-1.5 py-0.2 rounded-md font-bold bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 border border-indigo-500/30"
                                >
                                    {{ msg.user.role_label }}
                                </span>
                            </div>
                        </div>

                        <span class="text-[10px] font-mono text-slate-400 dark:text-slate-500" dir="ltr">
                            {{ msg.created_at_human }}
                        </span>
                    </div>

                    <!-- Body -->
                    <p class="text-xs text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
                        {{ msg.message }}
                    </p>

                    <!-- Attachments -->
                    <div v-if="msg.attachments && msg.attachments.length > 0" class="pt-2 border-t border-slate-200/60 dark:border-white/5 space-y-1.5">
                        <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">پیوست‌ها:</span>
                        <div class="flex flex-wrap gap-1.5">
                            <a
                                v-for="att in msg.attachments"
                                :key="att.id"
                                :href="att.download_url"
                                class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 border border-slate-200 dark:border-white/10 text-xs font-mono text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1.5 transition-colors"
                                dir="ltr"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>{{ att.file_name }}</span>
                                <span class="text-[9px] text-slate-400">({{ att.file_size }})</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Reply Box (If not closed) -->
            <div v-if="!ticket.is_closed" class="glass-card !p-3.5 space-y-3 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
                <span class="text-xs font-black text-slate-900 dark:text-white block">ارسال پاسخ جدید:</span>

                <form @submit.prevent="submitReply" class="space-y-3">
                    <textarea
                        v-model="replyForm.message"
                        rows="3"
                        placeholder="متن پاسخ یا توضیحات تکمیلی را بنویسید..."
                        class="glass-input !p-3 !text-xs w-full resize-none leading-relaxed bg-slate-100/90 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                        required
                    ></textarea>
                    <p v-if="replyForm.errors.message" class="text-rose-500 text-[10px] font-bold">{{ replyForm.errors.message }}</p>

                    <!-- Attachment button & Send -->
                    <div class="flex items-center justify-between gap-2 pt-1 border-t border-slate-200/80 dark:border-white/5">
                        <button
                            type="button"
                            @click="fileInputRef?.click()"
                            class="glass-pill !py-1.5 !px-3 !text-xs flex items-center gap-1.5 text-slate-700 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-white"
                        >
                            <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                            </svg>
                            <span>پیوست فایل</span>
                        </button>

                        <input
                            ref="fileInputRef"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.zip,.txt"
                            class="hidden"
                            @change="onFileSelect"
                        />

                        <button
                            type="submit"
                            :disabled="replyForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl disabled:opacity-40"
                        >
                            {{ replyForm.processing ? 'در حال ارسال...' : 'ارسال پاسخ' }}
                        </button>
                    </div>

                    <p v-if="fileError" class="text-rose-500 text-[10px] font-bold">{{ fileError }}</p>

                    <!-- Attachments preview -->
                    <div v-if="replyForm.attachments.length > 0" class="flex flex-wrap gap-1.5 pt-1">
                        <div
                            v-for="(f, idx) in replyForm.attachments"
                            :key="idx"
                            class="px-2 py-1 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-200 dark:border-indigo-500/20 text-indigo-700 dark:text-indigo-300 text-[11px] flex items-center gap-1.5"
                        >
                            <span class="font-mono truncate max-w-[150px]" dir="ltr">{{ f.name }}</span>
                            <button type="button" @click="removeAttachment(idx)" class="text-rose-500 hover:text-rose-700">✕</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Closed Notice -->
            <div v-else class="glass-card !p-4 text-center text-xs text-slate-500 dark:text-slate-400 space-y-1 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <p class="font-bold text-slate-800 dark:text-slate-200">این تیکت بسته‌شده است.</p>
                <p>جهت پیگیری موضوعات جدید، لطفاً یک تیکت پشتیبانی جدید ایجاد نمایید.</p>
            </div>
        </div>
    </UserLayout>
</template>
