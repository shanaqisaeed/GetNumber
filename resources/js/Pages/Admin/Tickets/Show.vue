<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
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

interface StaffMember {
    id: number;
    name: string;
    username: string;
    role_label: string;
}

interface TicketData {
    id: number;
    ticket_number: string;
    subject: string;
    user: {
        id: number;
        name: string;
        username: string;
        created_at: string;
    };
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
    assigned_agent: {
        id: number;
        name: string;
        username: string;
    } | null;
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
    staffMembers: StaffMember[];
    statuses: Array<{ value: string; label: string }>;
}>();

const replyForm = useForm({
    message: '',
    attachments: [] as File[],
});

const selectedStatus = ref(props.ticket.status);
const selectedAgent = ref(props.ticket.assigned_agent ? props.ticket.assigned_agent.id : '');

const fileInputRef = ref<HTMLInputElement | null>(null);
const fileError = ref<string | null>(null);

function onFileSelect(e: Event) {
    fileError.value = null;
    const target = e.target as HTMLInputElement;
    if (!target.files) return;

    const files = Array.from(target.files);
    if (replyForm.attachments.length + files.length > 5) {
        fileError.value = 'حداکثر تا ۵ فایل می‌توانید پیوست نمایید.';
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
            fileError.value = `حجم فایل «${f.name}» بیش از ۵ مگابایت است.`;
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

function submitStaffReply() {
    replyForm.post(route('admin.tickets.reply', props.ticket.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            replyForm.reset();
        },
    });
}

function changeStatus() {
    router.patch(route('admin.tickets.status', props.ticket.id), {
        status: selectedStatus.value,
    }, {
        preserveScroll: true,
    });
}

function changeAssignment() {
    router.patch(route('admin.tickets.assign', props.ticket.id), {
        assigned_to: selectedAgent.value ? selectedAgent.value : null,
    }, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`رسیدگی به تیکت: ${ticket.ticket_number}`" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <Link
                        :href="route('admin.tickets.index')"
                        class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition-colors"
                        title="بازگشت به فهرست تیکت‌ها"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-xs px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" dir="ltr">
                                {{ ticket.ticket_number }}
                            </span>
                            <h1 class="text-base font-bold text-slate-900 dark:text-white truncate max-w-lg">
                                {{ ticket.subject }}
                            </h1>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Left 2 Cols: Conversation Stream & Reply Box -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Messages Stream -->
                <div class="space-y-4">
                    <div
                        v-for="msg in ticket.messages"
                        :key="msg.id"
                        class="rounded-3xl p-5 transition-all"
                        :class="[
                            msg.is_staff_reply
                                ? 'bg-gradient-to-tr from-indigo-50/70 to-purple-50/70 dark:from-indigo-950/30 dark:to-slate-900 border border-indigo-200 dark:border-indigo-900/60 shadow-sm'
                                : 'bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm'
                        ]"
                    >
                        <!-- Author Header -->
                        <div class="flex items-center justify-between pb-3 border-b" :class="msg.is_staff_reply ? 'border-indigo-100 dark:border-indigo-900/40' : 'border-slate-100 dark:border-slate-800'">
                            <div class="flex items-center gap-2.5">
                                <div
                                    class="w-8 h-8 rounded-xl flex items-center justify-center font-bold text-xs"
                                    :class="[
                                        msg.is_staff_reply
                                            ? 'bg-indigo-600 text-white shadow-sm'
                                            : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                                    ]"
                                >
                                    {{ msg.is_staff_reply ? 'کارشناس' : 'کاربر' }}
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-slate-900 dark:text-white">{{ msg.user.name }}</span>
                                        <span class="font-mono text-[10px] text-slate-400" dir="ltr">(@{{ msg.user.username }})</span>
                                        <span
                                            v-if="msg.is_staff_reply"
                                            class="px-2 py-0.2 rounded-md text-[10px] font-bold bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30"
                                        >
                                            {{ msg.user.role_label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <span class="text-[11px] text-slate-400 font-mono" dir="ltr">
                                {{ msg.created_at_human }} ({{ msg.created_at }})
                            </span>
                        </div>

                        <!-- Message Text -->
                        <div class="py-3.5 text-xs text-slate-800 dark:text-slate-200 leading-relaxed whitespace-pre-wrap">
                            {{ msg.message }}
                        </div>

                        <!-- Attachments Cards -->
                        <div v-if="msg.attachments && msg.attachments.length > 0" class="pt-3 border-t" :class="msg.is_staff_reply ? 'border-indigo-100 dark:border-indigo-900/40' : 'border-slate-100 dark:border-slate-800'">
                            <p class="text-[11px] font-bold text-slate-500 dark:text-slate-400 mb-2">فایل‌های پیوست:</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <a
                                    v-for="att in msg.attachments"
                                    :key="att.id"
                                    :href="att.download_url"
                                    class="p-2.5 rounded-xl bg-white/80 dark:bg-slate-800/80 hover:bg-white dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-between gap-2 transition-all group"
                                >
                                    <div class="flex items-center gap-2 truncate">
                                        <svg class="w-4 h-4 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                        </svg>
                                        <span class="text-xs font-medium text-slate-700 dark:text-slate-200 truncate group-hover:text-indigo-600" dir="ltr">
                                            {{ att.file_name }}
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-mono shrink-0">
                                        {{ att.file_size }}
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Staff Reply Box -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-3">ارسال پاسخ کارشناس به مشترک</h3>

                    <form @submit.prevent="submitStaffReply" class="space-y-4">
                        <textarea
                            v-model="replyForm.message"
                            rows="5"
                            placeholder="متن پاسخ کارشناسی، راهنمایی یا اقدام انجام‌شده را در این بخش درج فرمایید..."
                            class="w-full text-xs rounded-2xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 p-3.5 leading-relaxed"
                            required
                        ></textarea>
                        <p v-if="replyForm.errors.message" class="text-rose-500 text-[11px] font-bold">{{ replyForm.errors.message }}</p>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="fileInputRef?.click()"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold transition-colors cursor-pointer"
                                >
                                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                    </svg>
                                    <span>پیوست فایل</span>
                                </button>
                                <span class="text-[11px] text-slate-400">JPG, PNG, PDF, ZIP (حداکثر ۵MB)</span>
                                <input
                                    ref="fileInputRef"
                                    type="file"
                                    multiple
                                    accept=".jpg,.jpeg,.png,.pdf,.zip,.txt"
                                    class="hidden"
                                    @change="onFileSelect"
                                />
                            </div>

                            <button
                                type="submit"
                                :disabled="replyForm.processing"
                                class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-bold shadow-md shadow-indigo-600/20 transition-all cursor-pointer"
                            >
                                <span v-if="replyForm.processing">در حال ارسال...</span>
                                <span v-else>ارسال پاسخ و اطلاع مشترک</span>
                            </button>
                        </div>

                        <p v-if="fileError" class="text-rose-500 text-[11px] font-bold">{{ fileError }}</p>

                        <div v-if="replyForm.attachments.length > 0" class="flex flex-wrap gap-2">
                            <div
                                v-for="(f, idx) in replyForm.attachments"
                                :key="idx"
                                class="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 text-[11px] flex items-center gap-1.5 border border-indigo-200 dark:border-indigo-900"
                            >
                                <span class="font-medium truncate max-w-[150px]" dir="ltr">{{ f.name }}</span>
                                <button type="button" @click="removeAttachment(idx)" class="text-rose-500 hover:text-rose-700">
                                    ✕
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right 1 Col: Management Sidebar -->
            <div class="space-y-6">
                <!-- Status & Assignment Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-5">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white pb-3 border-b border-slate-100 dark:border-slate-800">
                        مدیریت تیکت و واگذاری
                    </h3>

                    <!-- Status Change -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">وضعیت تیکت</label>
                        <select
                            v-model="selectedStatus"
                            @change="changeStatus"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 py-2.5"
                        >
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>

                    <!-- Assign Agent -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">کارشناس مسئول</label>
                        <select
                            v-model="selectedAgent"
                            @change="changeAssignment"
                            class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-500 py-2.5"
                        >
                            <option value="">بدون کارشناس (ارجاع به صف عمومی)</option>
                            <option v-for="staff in staffMembers" :key="staff.id" :value="staff.id">
                                {{ staff.name }} ({{ staff.role_label }})
                            </option>
                        </select>
                    </div>

                    <!-- Department & Priority Badges -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2 text-xs">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-400">دپارتمان:</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="ticket.department_badge">
                                {{ ticket.department_label }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-400">اولویت:</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="ticket.priority_badge">
                                {{ ticket.priority_label }}
                            </span>
                        </div>
                        <div v-if="ticket.phone_number" class="flex justify-between items-center">
                            <span class="text-slate-400">خط مرتبط:</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" dir="ltr">
                                {{ ticket.phone_number.formatted_number }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Customer Details Card -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white pb-3 border-b border-slate-100 dark:border-slate-800">
                        مشخصات مشترک
                    </h3>

                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-400">نام مشترک:</span>
                            <span class="font-bold text-slate-900 dark:text-white">{{ ticket.user.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">شناسه کاربری:</span>
                            <span class="font-mono text-slate-700 dark:text-slate-300" dir="ltr">@{{ ticket.user.username }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">تاریخ عضویت:</span>
                            <span class="font-mono text-slate-500" dir="ltr">{{ ticket.user.created_at }}</span>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex gap-2">
                        <Link
                            :href="route('admin.audit-logs.index', { user_id: ticket.user.id })"
                            class="w-full text-center py-2 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[11px] font-bold transition-colors"
                        >
                            مشاهده لاگ‌های امنیتی کاربر
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
