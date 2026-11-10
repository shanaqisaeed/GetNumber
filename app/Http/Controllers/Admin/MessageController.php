<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\MessageServiceInterface;
use App\Enums\MessageProcessingStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MessageController extends Controller
{
    public function __construct(
        protected MessageServiceInterface $messageService
    ) {}

    /**
     * Display the administration overview of all inbound system messages with chat-based conversations.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['user_id', 'phone_number_id', 'destination_number', 'sender', 'search', 'status', 'peer']);

        // 1. Paginated raw messages (for table view and backward compatibility)
        $messages = $this->messageService->getAllMessages(
            filters: $filters,
            perPage: 15
        );

        // 2. Chat-based conversations across the system
        $conversations = $this->messageService->getAdminConversations($filters);

        // 3. Resolve active conversation and preload its chat stream
        $selectedConversation = null;
        $activeMessages = collect();

        if ($request->filled('phone_number_id') && $request->filled('peer')) {
            $selectedConversation = $conversations->first(function ($c) use ($request) {
                return $c['phone_number_id'] == $request->input('phone_number_id') && $c['peer_number'] == $request->input('peer');
            });

            if ($selectedConversation) {
                $activeMessages = $this->messageService->getAdminConversationMessages(
                    (int) $request->input('phone_number_id'),
                    (string) $request->input('peer')
                );
            }
        } elseif ($conversations->isNotEmpty()) {
            $firstConv = $conversations->first();
            $selectedConversation = $firstConv;
            $activeMessages = $this->messageService->getAdminConversationMessages(
                (int) $firstConv['phone_number_id'],
                (string) $firstConv['peer_number']
            );
        }

        // 4. System-wide numbers list for filtering (Cached)
        $cacheService = app(\App\Contracts\SystemCacheServiceInterface::class);
        $phoneNumbers = $cacheService->getSystemPhoneNumbersList();

        $stats = \Illuminate\Support\Facades\Cache::remember('admin:messages:stats', 120, function () use ($conversations) {
            return [
                'total_messages' => Message::count(),
                'today_messages' => Message::whereDate('server_received_at', today())->count(),
                'failed_messages' => Message::where('processing_status', MessageProcessingStatus::Failed)->count(),
                'total_conversations' => $conversations->count(),
            ];
        });

        return Inertia::render('Admin/Messages/Index', [
            'messages' => $messages,
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'activeMessages' => $activeMessages,
            'phoneNumbers' => $phoneNumbers,
            'stats' => $stats,
            'filters' => $filters,
            'statuses' => collect(MessageProcessingStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'badgeClass' => $s->badgeClass(),
            ]),
        ]);
    }

    /**
     * Fast JSON endpoint to load conversation messages dynamically.
     */
    public function conversation(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number_id' => 'required|integer|exists:phone_numbers,id',
            'peer_number' => 'required|string',
            'after_id' => 'nullable|integer',
        ]);

        $messages = $this->messageService->getAdminConversationMessages(
            (int) $request->input('phone_number_id'),
            (string) $request->input('peer_number'),
            $request->input('after_id') ? (int) $request->input('after_id') : null
        );

        return response()->json([
            'success' => true,
            'messages' => $messages,
        ]);
    }

    /**
     * Send SMS as administrator (can send on behalf of any system line).
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_number_id' => 'required|integer|exists:phone_numbers,id',
            'destinations' => 'required|array|min:1|max:50',
            'destinations.*' => 'required|string|max:32',
            'body' => 'required|string|max:1000',
        ]);

        $fromNumber = PhoneNumber::findOrFail($validated['from_number_id']);

        $adminNotice = __('controllers.admin.messages.admin_notice_prefix');
        $body = trim($validated['body']);
        if (! str_starts_with($body, $adminNotice)) {
            $body = $adminNotice . $body;
        }

        $result = $this->messageService->sendSms(
            $fromNumber,
            $validated['destinations'],
            $body,
            $request->user()
        );

        $isSuccess = ($result['sent_count'] ?? 0) > 0;

        return response()->json([
            'success' => $isSuccess,
            'message' => $isSuccess
                ? __('controllers.admin.messages.sent_success')
                : ($result['results'][0]['reason'] ?? __('controllers.admin.messages.send_failed_default')),
            'sent_count' => $result['sent_count'] ?? 0,
            'failed_count' => $result['failed_count'] ?? 0,
            'results' => $result['results'] ?? [],
        ]);
    }

    /**
     * Fast polling endpoint for administration monitoring (3-5s interval).
     */
    public function poll(Request $request): JsonResponse
    {
        $afterId = max(0, (int) $request->input('after_id', 0));
        $cacheService = app(\App\Contracts\SystemCacheServiceInterface::class);

        // Fast zero-DB check: if afterId reached global latest and no active conversation selected
        if ($afterId > 0 && ! $request->filled('active_phone_id')) {
            $globalLatest = $cacheService->getGlobalLatestMessageId();
            if ($afterId >= $globalLatest) {
                return response()->json([
                    'success' => true,
                    'count' => 0,
                    'after_id' => $afterId,
                    'active_messages' => null,
                    'data' => [],
                ]);
            }
        }

        $newMessages = $this->messageService->pollAllMessages(
            afterMessageId: $afterId,
            filters: $request->all(),
            limit: 30
        );

        // Also fetch active conversation messages if currently viewing one
        $activeMessages = null;
        if ($request->filled('active_phone_id') && $request->filled('active_peer')) {
            $activeMessages = $this->messageService->getAdminConversationMessages(
                (int) $request->input('active_phone_id'),
                (string) $request->input('active_peer'),
                $request->filled('active_after_id') ? (int) $request->input('active_after_id') : null
            );
        }

        return response()->json([
            'success' => true,
            'count' => $newMessages->count(),
            'after_id' => $afterId,
            'active_messages' => $activeMessages,
            'data' => $newMessages->map(fn (Message $m) => [
                'id' => $m->id,
                'phone_number_id' => $m->phone_number_id,
                'destination_number' => $m->destination_number,
                'sender' => $m->sender,
                'body' => $m->body,
                'user' => $m->phoneNumber?->user ? [
                    'id' => $m->phoneNumber->user->id,
                    'name' => $m->phoneNumber->user->name,
                    'username' => $m->phoneNumber->user->username,
                ] : null,
                'source_time' => $m->source_time?->toIso8601String(),
                'server_received_at' => $m->server_received_at->toIso8601String(),
                'server_received_at_formatted' => $m->server_received_at->diffForHumans(),
                'processing_status' => $m->processing_status->value,
                'status_label' => $m->processing_status->label(),
                'status_badge' => $m->processing_status->badgeClass(),
                'direction' => $m->direction->value,
                'direction_label' => $m->direction->label(),
                'client_message_id' => $m->client_message_id,
                'source_system_id' => $m->source_system_id,
            ]),
        ]);
    }

    /**
     * Delete a message record from the system.
     */
    public function destroy(Request $request, Message $message): RedirectResponse
    {
        \App\Models\AuditLog::record('message_deleted', $message, $request->user(), [
            'message_id' => $message->id,
            'source_number' => $message->source_number,
            'destination_number' => $message->destination_number,
            'body_snippet' => mb_substr($message->body, 0, 50),
        ]);

        $this->messageService->deleteMessage($message);

        return back()->with('success', __('controllers.admin.messages.deleted'));
    }
}