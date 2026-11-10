<?php

namespace App\Http\Controllers\User;

use App\Contracts\TicketServiceInterface;
use App\Enums\TicketDepartment;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\Ticket;
use App\Models\TicketAttachment;
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
     * Display a listing of tickets created by the authenticated user.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = Ticket::where('user_id', $user->id)
            ->with(['assignedAgent', 'phoneNumber']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('department')) {
            $query->where('department', $request->input('department'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $tickets = $query->latest('last_reply_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Ticket $t) => [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'subject' => $t->subject,
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
                    'name' => $t->assignedAgent->name,
                ] : null,
                'last_reply_at' => $t->last_reply_at?->format('Y/m/d H:i'),
                'last_reply_human' => $t->last_reply_at?->diffForHumans(),
                'created_at' => $t->created_at->format('Y/m/d H:i'),
            ]);

        $stats = [
            'total' => Ticket::where('user_id', $user->id)->count(),
            'open' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::Open)->count(),
            'answered' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::Answered)->count(),
            'in_progress' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::InProgress)->count(),
            'closed' => Ticket::where('user_id', $user->id)->where('status', TicketStatus::Closed)->count(),
        ];

        return Inertia::render('User/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['status', 'department', 'search']),
            'stats' => $stats,
            'departments' => array_map(fn ($d) => ['value' => $d->value, 'label' => $d->label()], TicketDepartment::cases()),
            'statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], TicketStatus::cases()),
        ]);
    }

    /**
     * Show the ticket creation form.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();
        $userNumbers = PhoneNumber::where('user_id', $user->id)
            ->where('status', \App\Enums\PhoneNumberStatus::Allocated)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->effective_title,
            ]);

        return Inertia::render('User/Tickets/Create', [
            'departments' => array_map(fn ($d) => ['value' => $d->value, 'label' => $d->label()], TicketDepartment::cases()),
            'priorities' => array_map(fn ($p) => ['value' => $p->value, 'label' => $p->label()], TicketPriority::cases()),
            'userNumbers' => $userNumbers,
        ]);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:5', 'max:255'],
            'department' => ['required', Rule::enum(TicketDepartment::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'phone_number_id' => ['nullable', 'exists:phone_numbers,id'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,zip,txt'],
        ], [
            'subject.required' => __('controllers.user.tickets.val_subject_required'),
            'subject.min' => __('controllers.user.tickets.val_subject_min'),
            'department.required' => __('controllers.user.tickets.val_department_required'),
            'priority.required' => __('controllers.user.tickets.val_priority_required'),
            'message.required' => __('controllers.user.tickets.val_message_required'),
            'message.min' => __('controllers.user.tickets.val_message_min'),
            'attachments.max' => __('controllers.user.tickets.val_attachments_max'),
            'attachments.*.max' => __('controllers.user.tickets.val_attachments_file_max'),
            'attachments.*.mimes' => __('controllers.user.tickets.val_attachments_mimes'),
        ]);

        $attachments = $request->file('attachments', []);

        $ticket = $this->ticketService->createTicket(
            user: $request->user(),
            data: $validated,
            attachments: is_array($attachments) ? $attachments : [$attachments]
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('success', __('controllers.user.tickets.created'));
    }

    /**
     * Display a specific ticket with its conversation thread.
     */
    public function show(Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'user',
            'assignedAgent',
            'phoneNumber',
            'messages.user',
            'messages.attachments',
        ]);

        return Inertia::render('User/Tickets/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
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
                        'download_url' => route('tickets.attachments.download', [$ticket->id, $a->id]),
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * Post a reply to the ticket.
     */
    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,zip,txt'],
        ], [
            'message.required' => __('controllers.user.tickets.val_reply_required'),
            'attachments.max' => __('controllers.user.tickets.val_attachments_max'),
            'attachments.*.max' => __('controllers.user.tickets.val_attachments_file_max'),
            'attachments.*.mimes' => __('controllers.user.tickets.val_attachments_mimes'),
        ]);

        $attachments = $request->file('attachments', []);

        $this->ticketService->replyToTicket(
            ticket: $ticket,
            author: $request->user(),
            message: $validated['message'],
            attachments: is_array($attachments) ? $attachments : [$attachments]
        );

        return back()->with('success', __('controllers.user.tickets.reply_sent'));
    }

    /**
     * Close the ticket by user.
     */
    public function close(Ticket $ticket): RedirectResponse
    {
        $this->authorize('close', $ticket);

        $this->ticketService->closeTicket($ticket, request()->user());

        return back()->with('success', __('controllers.user.tickets.closed'));
    }

    /**
     * Download attachment securely.
     */
    public function downloadAttachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        $this->authorize('downloadAttachment', $ticket);

        if ($attachment->ticketMessage->ticket_id !== $ticket->id) {
            abort(404, __('controllers.user.tickets.attachment_not_found_ticket'));
        }

        if (! Storage::disk('local')->exists($attachment->file_path)) {
            abort(404, __('controllers.user.tickets.attachment_not_found_server'));
        }

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->file_name
        );
    }
}