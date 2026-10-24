<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\TicketServiceInterface;
use App\Enums\TicketDepartment;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    public function __construct(
        protected TicketServiceInterface $ticketService
    ) {}

    /**
     * Display a listing of all support tickets in the system.
     */
    public function index(Request $request): Response
    {
        $query = Ticket::with(['user', 'assignedAgent', 'phoneNumber']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('assigned_to')) {
            $assignedTo = $request->input('assigned_to');
            if ($assignedTo === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', (int) $assignedTo);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('username', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        $tickets = $query->latest('last_reply_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Ticket $t) => [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'subject' => $t->subject,
                'user' => [
                    'id' => $t->user->id,
                    'name' => $t->user->name,
                    'username' => $t->user->username,
                ],
                'department' => $t->department->value,
                'department_label' => $t->department->label(),
                'department_badge' => $t->department->badgeClasses(),
                'priority' => $t->priority->value,
                'priority_label' => $t->priority->label(),
                'priority_badge' => $t->priority->badgeClasses(),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'status_badge' => $t->status->badgeClasses(),
                'phone_number' => $t->phoneNumber ? [
                    'id' => $t->phoneNumber->id,
                    'number' => $t->phoneNumber->number,
                    'formatted_number' => $t->phoneNumber->formatted_number,
                    'display_title' => $t->phoneNumber->display_title,
                ] : null,
                'assigned_agent' => $t->assignedAgent ? [
                    'id' => $t->assignedAgent->id,
                    'name' => $t->assignedAgent->name,
                    'username' => $t->assignedAgent->username,
                ] : null,
                'last_reply_at' => $t->last_reply_at?->format('Y/m/d H:i'),
                'last_reply_human' => $t->last_reply_at?->diffForHumans(),
                'created_at' => $t->created_at->format('Y/m/d H:i'),
            ]);

        $stats = [
            'total' => Ticket::count(),
            'open' => Ticket::where('status', TicketStatus::Open)->count(),
            'in_progress' => Ticket::where('status', TicketStatus::InProgress)->count(),
            'answered' => Ticket::where('status', TicketStatus::Answered)->count(),
            'closed' => Ticket::where('status', TicketStatus::Closed)->count(),
            'unassigned' => Ticket::whereNull('assigned_to')->where('status', '!=', TicketStatus::Closed)->count(),
        ];

        // List of support staff (Admins and Agents)
        $staffMembers = User::whereIn('role', [UserRole::Admin, UserRole::Agent])
            ->select('id', 'name', 'username', 'role')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role_label' => $u->role->label(),
            ]);

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['status', 'department', 'priority', 'assigned_to', 'search']),
            'stats' => $stats,
            'staffMembers' => $staffMembers,
            'departments' => array_map(fn ($d) => ['value' => $d->value, 'label' => $d->label()], TicketDepartment::cases()),
            'priorities' => array_map(fn ($p) => ['value' => $p->value, 'label' => $p->label()], TicketPriority::cases()),
            'statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], TicketStatus::cases()),
        ]);
    }

    /**
     * Show ticket conversation thread in staff console.
     */
    public function show(Ticket $ticket): Response
    {
        $ticket->load([
            'user',
            'assignedAgent',
            'phoneNumber',
            'messages.user',
            'messages.attachments',
        ]);

        $staffMembers = User::whereIn('role', [UserRole::Admin, UserRole::Agent])
            ->select('id', 'name', 'username', 'role')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role_label' => $u->role->label(),
            ]);

        return Inertia::render('Admin/Tickets/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'user' => [
                    'id' => $ticket->user->id,
                    'name' => $ticket->user->name,
                    'username' => $ticket->user->username,
                    'created_at' => $ticket->user->created_at->format('Y/m/d'),
                ],
                'department' => $ticket->department->value,
                'department_label' => $ticket->department->label(),
                'department_badge' => $ticket->department->badgeClasses(),
                'priority' => $ticket->priority->value,
                'priority_label' => $ticket->priority->label(),
                'priority_badge' => $ticket->priority->badgeClasses(),
                'status' => $ticket->status->value,
                'status_label' => $ticket->status->label(),
                'status_badge' => $ticket->status->badgeClasses(),
                'is_closed' => $ticket->isClosed(),
                'assigned_agent' => $ticket->assignedAgent ? [
                    'id' => $ticket->assignedAgent->id,
                    'name' => $ticket->assignedAgent->name,
                    'username' => $ticket->assignedAgent->username,
                ] : null,
                'phone_number' => $ticket->phoneNumber ? [
                    'id' => $ticket->phoneNumber->id,
                    'number' => $ticket->phoneNumber->number,
                    'formatted_number' => $ticket->phoneNumber->formatted_number,
                    'display_title' => $ticket->phoneNumber->effective_title,
                ] : null,
                'created_at' => $ticket->created_at->format('Y/m/d H:i'),
                'messages' => $ticket->messages->map(fn ($m) => [
                    'id' => $m->id,
                    'message' => $m->message,
                    'is_staff_reply' => $m->is_staff_reply,
                    'user' => [
                        'id' => $m->user->id,
                        'name' => $m->user->name,
                        'username' => $m->user->username,
                        'role' => $m->user->role->value,
                        'role_label' => $m->user->role->label(),
                    ],
                    'created_at' => $m->created_at->format('Y/m/d H:i'),
                    'created_at_human' => $m->created_at->diffForHumans(),
                    'attachments' => $m->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'file_name' => $a->file_name,
                        'file_size' => $a->formatted_size,
                        'mime_type' => $a->mime_type,
                        'is_image' => $a->isImage(),
                        'download_url' => route('admin.tickets.attachments.download', [$ticket->id, $a->id]),
                    ]),
                ]),
            ],
            'staffMembers' => $staffMembers,
            'statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], TicketStatus::cases()),
        ]);
    }

    /**
     * Send staff reply to ticket.
     */
    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,zip,txt'],
        ], [
            'message.required' => 'متن پاسخ کارشناس الزامی است.',
            'attachments.max' => 'حداکثر ۵ فایل پیوست مجاز است.',
            'attachments.*.max' => 'حجم فایل نباید بیش از ۵ مگابایت باشد.',
            'attachments.*.mimes' => 'فرمت مجاز فایل‌های پیوست: JPG, PNG, PDF, ZIP, TXT.',
        ]);

        $attachments = $request->file('attachments', []);

        $this->ticketService->replyToTicket(
            ticket: $ticket,
            author: $request->user(),
            message: $validated['message'],
            attachments: is_array($attachments) ? $attachments : [$attachments]
        );

        return back()->with('success', 'پاسخ کارشناس پشتیبانی با موفقیت در تیکت ثبت و ارسال گردید.');
    }

    /**
     * Update ticket status from staff console.
     */
    public function updateStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(TicketStatus::class)],
        ]);

        $this->ticketService->updateStatus(
            ticket: $ticket,
            status: TicketStatus::from($validated['status']),
            actor: $request->user()
        );

        return back()->with('success', 'وضعیت تیکت با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Assign ticket to a staff member or unassign.
     */
    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $agent = ! empty($validated['assigned_to'])
            ? User::findOrFail($validated['assigned_to'])
            : null;

        $this->ticketService->assignTicket(
            ticket: $ticket,
            agent: $agent,
            actor: $request->user()
        );

        $msg = $agent ? "تیکت با موفقیت به کارشناس «{$agent->name}» واگذار شد." : 'تیکت از کارشناس تخصیص‌یافته خارج گردید.';

        return back()->with('success', $msg);
    }

    /**
     * Download attachment by staff member.
     */
    public function downloadAttachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        if ($attachment->ticketMessage->ticket_id !== $ticket->id) {
            abort(404, 'فایل پیوست در این تیکت یافت نشد.');
        }

        if (! Storage::disk('local')->exists($attachment->file_path)) {
            abort(404, 'فایل مورد نظر در سرور موجود نمی‌باشد.');
        }

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->file_name
        );
    }
}
