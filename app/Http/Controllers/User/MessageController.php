<?php

namespace App\Http\Controllers\User;

use App\Contracts\MessageServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MessageController extends Controller
{
    public function __construct(
        protected MessageServiceInterface $messageService,
        protected ?\App\Contracts\UnreadCountServiceInterface $unreadCountService = null
    ) {
        $this->unreadCountService = $unreadCountService ?? app(\App\Contracts\UnreadCountServiceInterface::class);
    }

    /**
     * Display the mobile SMS messenger & conversation interface.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. User's active allocated numbers (Line selector - Cached)
        $allocatedNumbers = app(\App\Contracts\SystemCacheServiceInterface::class)->getUserAllocatedNumbers($user);

        /** @var PhoneNumber|null $currentNumber */
        $currentNumber = null;
        if ($request->filled('phone_number_id')) {
            $currentNumber = $allocatedNumbers->firstWhere('id', (int) $request->input('phone_number_id'));
        }

        if (! $currentNumber && $allocatedNumbers->isNotEmpty()) {
            $currentNumber = $allocatedNumbers->first();
        }

        $conversations = collect();
        $activePeer = $request->filled('peer')
            ? \App\Services\PhoneNumberNormalizer::resolveToStoredNumber($request->input('peer'))
            : null;
        $activeMessages = collect();
        $activePeerDetails = null;

        if ($currentNumber) {
            $search = $request->input('search');

            // 1. If an active conversation is open, mark messages as read first
            if ($activePeer) {
                $activeMessages = $this->messageService->getConversationMessages($currentNumber, $activePeer);

                $defaultPrefix = \App\Models\PhonePrefix::getDefaultPrefixString();
                $peerRecord = PhoneNumber::with('user')->where('number', $activePeer)->first();
                $isCommercial = $peerRecord ? $peerRecord->type->isService() : false;
                $peerTitle = $peerRecord?->display_title ?: ($peerRecord?->user?->name ?: ($peerRecord?->effective_title ?? "{$defaultPrefix} {$activePeer}"));

                $canReply = true;
                $canReplyReason = null;
                if ($peerRecord) {
                    if (! $peerRecord->accepts_incoming_sms) {
                        $canReply = false;
                        $canReplyReason = 'این سرشماره تجاری صرفاً یک‌طرفه بوده و امکان دریافت پیامک یا پاسخ را ندارد.';
                    } else {
                        $check = $peerRecord->canReceiveMessagesFrom($currentNumber, $user);
                        if (! $check['allowed']) {
                            $canReply = false;
                            $canReplyReason = $check['reason'];
                        }
                    }
                }

                $activePeerDetails = [
                    'number' => $activePeer,
                    'formatted_number' => $peerRecord ? $peerRecord->formatted_number : "{$defaultPrefix} {$activePeer}",
                    'title' => $peerTitle,
                    'type' => $peerRecord?->type->value,
                    'type_label' => $peerRecord?->type->label(),
                    'badge_class' => $peerRecord?->type->badgeClasses(),
                    'is_own_number' => $peerRecord && $peerRecord->user_id === $user->id,
                    'is_commercial' => $isCommercial,
                    'accepts_incoming_sms' => $peerRecord ? (bool) $peerRecord->accepts_incoming_sms : true,
                    'can_reply' => $canReply,
                    'can_reply_reason' => $canReplyReason,
                ];
            }

            // 2. Fetch conversations after read state has been updated
            $conversations = $this->messageService->getConversations($currentNumber, $search);
        }

        $unreadCounts = Message::whereIn('phone_number_id', $allocatedNumbers->pluck('id'))
            ->where('direction', MessageDirection::Inbound)
            ->where('processing_status', MessageProcessingStatus::Received)
            ->selectRaw('phone_number_id, count(*) as count')
            ->groupBy('phone_number_id')
            ->pluck('count', 'phone_number_id');

        $stats = [
            'total_messages' => $user->messages()->count(),
            'today_messages' => $user->messages()->whereDate('messages.server_received_at', today())->count(),
            'allocated_lines_count' => $allocatedNumbers->count(),
        ];

        return Inertia::render('User/Messages/Index', [
            'allocatedNumbers' => $allocatedNumbers->map(fn (PhoneNumber $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'effective_title' => $p->effective_title,
                'type' => $p->type->value,
                'type_label' => $p->type->label(),
                'badge_class' => $p->type->badgeClasses(),
                'unread_count' => (int) ($unreadCounts[$p->id] ?? 0),
            ]),
            'currentNumber' => $currentNumber ? [
                'id' => $currentNumber->id,
                'number' => $currentNumber->number,
                'formatted_number' => $currentNumber->formatted_number,
                'effective_title' => $currentNumber->effective_title,
                'type' => $currentNumber->type->value,
                'type_label' => $currentNumber->type->label(),
                'badge_class' => $currentNumber->type->badgeClasses(),
                'unread_count' => (int) ($unreadCounts[$currentNumber->id] ?? 0),
            ] : null,
            'conversations' => $conversations,
            'activePeer' => $activePeer,
            'activePeerDetails' => $activePeerDetails,
            'activeMessages' => $activeMessages->map(fn (Message $m) => [
                'id' => $m->id,
                'phone_number_id' => $m->phone_number_id,
                'destination_number' => $m->destination_number,
                'sender' => $m->sender,
                'peer_number' => $m->peer_number,
                'direction' => $m->direction->value,
                'body' => $m->body,
                'server_received_at' => $m->server_received_at->toIso8601String(),
                'server_received_at_formatted' => $m->server_received_at->diffForHumans(),
                'processing_status' => $m->processing_status->value,
            ]),
            'stats' => $stats,
            'smsTariff' => [
                'rate_per_segment' => (string) \App\Models\SystemSetting::get('sms_price_per_segment', '0.0500'),
                'currency' => 'COIN',
            ],
            'search' => $request->input('search', ''),
        ]);
    }

    /**
     * Send SMS (Single or Bulk) from user's allocated line to one or more recipients.
     */
    public function send(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_number_id' => ['required', 'integer', 'exists:phone_numbers,id'],
            'destinations' => ['required'],
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'from_number_id.required' => 'انتخاب خط فرستنده الزامی است.',
            'destinations.required' => 'حداقل یک شماره مقصد الزامی است.',
            'body.required' => 'متن پیامک نمی‌تواند خالی باشد.',
            'body.max' => 'متن پیامک حداکثر ۲۰۰۰ کاراکتر می‌باشد.',
        ]);

        /** @var PhoneNumber $fromNumber */
        $fromNumber = PhoneNumber::findOrFail($validated['from_number_id']);

        if (! $user->isAdmin() && $fromNumber->user_id !== $user->id) {
            throw new AccessDeniedHttpException('شما مالک این خط نبوده و مجاز به ارسال پیامک از طریق آن نمی‌باشید.');
        }

        // Parse destinations (accepts array or comma/newline delimited string)
        $rawDest = $validated['destinations'];
        if (is_string($rawDest)) {
            $destArray = preg_split('/[\r\n,]+/', $rawDest);
        } else {
            $destArray = (array) $rawDest;
        }

        $result = $this->messageService->sendSms(
            fromNumber: $fromNumber,
            destinationNumbers: $destArray,
            body: $validated['body'],
            senderUser: $user
        );

        if ($request->wantsJson()) {
            $createdMessage = null;
            if ($result['sent_count'] > 0 && ! empty($result['results'])) {
                $firstSuccess = collect($result['results'])->firstWhere('status', 'success');
                if ($firstSuccess && isset($firstSuccess['message_id'])) {
                    $m = Message::find($firstSuccess['message_id']);
                    if ($m) {
                        $createdMessage = [
                            'id' => $m->id,
                            'phone_number_id' => $m->phone_number_id,
                            'destination_number' => $m->destination_number,
                            'sender' => $m->sender,
                            'peer_number' => $m->peer_number,
                            'direction' => $m->direction->value,
                            'body' => $m->body,
                            'server_received_at' => $m->server_received_at->toIso8601String(),
                            'server_received_at_formatted' => $m->server_received_at->diffForHumans(),
                            'processing_status' => $m->processing_status->value,
                        ];
                    }
                }
            }

            return response()->json([
                'success' => $result['sent_count'] > 0,
                'sent_count' => $result['sent_count'],
                'failed_count' => $result['failed_count'],
                'results' => $result['results'],
                'client_message_id' => $request->input('client_message_id'),
                'created_message' => $createdMessage,
                'message' => $result['sent_count'] > 0
                    ? "پیامک با موفقیت به {$result['sent_count']} مقصد ارسال گردید."
                    : 'ارسال پیامک با خطا مواجه شد.',
            ]);
        }

        if ($result['sent_count'] > 0) {
            $flash = "پیامک با موفقیت به {$result['sent_count']} مقصد ارسال گردید.";
            if ($result['failed_count'] > 0) {
                $flash .= " ({$result['failed_count']} شماره به دلیل عدم پذیرش پیامک ناموفق بود)";
            }
            return back()->with('success', $flash);
        }

        $failedReason = $result['results'][0]['reason'] ?? 'خط مقصد آماده دریافت پیامک نمی‌باشد.';
        return back()->with('error', "ارسال پیامک ناموفق بود: {$failedReason}");
    }

    /**
     * Dedicated asynchronous bulk SMS dispatch endpoint.
     * Enqueues batch and returns tracking batch_id and status URL.
     */
    public function sendBulk(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_number_id' => ['required', 'exists:phone_numbers,id'],
            'destinations' => ['required'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        /** @var PhoneNumber $fromNumber */
        $fromNumber = PhoneNumber::findOrFail($validated['from_number_id']);

        if (! $user->isAdmin() && $fromNumber->user_id !== $user->id) {
            throw new AccessDeniedHttpException('شما مالک این خط نبوده و مجاز به ارسال پیامک از طریق آن نمی‌باشید.');
        }

        $rawDest = $validated['destinations'];
        $destArray = is_string($rawDest) ? preg_split('/[\r\n,]+/', $rawDest) : (array) $rawDest;

        $batch = $this->messageService->queueBulkSms(
            fromNumber: $fromNumber,
            destinationNumbers: $destArray,
            body: $validated['body'],
            senderUser: $user
        );

        return response()->json([
            'success' => true,
            'batch_id' => $batch->batch_id,
            'total_count' => $batch->total_count,
            'status' => $batch->status,
            'status_url' => route('messages.batch.status', $batch->batch_id),
            'message' => "بسته ارسال انبوه با موفقیت در صف پردازش پس‌زمینه قرار گرفت (شناسه: {$batch->batch_id}).",
        ], 202);
    }

    /**
     * Check progress and statistics of a bulk message batch.
     */
    public function batchStatus(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->messageService->getBatchStatus($batchId, $request->user());

        return response()->json([
            'success' => true,
            'batch_id' => $batch->batch_id,
            'status' => $batch->status,
            'total_count' => $batch->total_count,
            'processed_count' => $batch->processed_count,
            'success_count' => $batch->success_count,
            'failed_count' => $batch->failed_count,
            'progress_percentage' => $batch->progressPercentage(),
            'is_finished' => $batch->isFinished(),
            'created_at' => $batch->created_at->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
        ]);
    }

    /**
     * Authoritative server-side SMS segment & cost estimation endpoint.
     */
    public function estimateCost(Request $request, \App\Services\SmsSegmentCalculatorService $calculator): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'destinations_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $body = (string) ($validated['body'] ?? '');
        $destCount = (int) ($validated['destinations_count'] ?? 1);

        $calculation = $calculator->calculate($body, $destCount);

        return response()->json([
            'success' => true,
            'data' => $calculation,
        ]);
    }

    /**
     * Resynchronize lost/missed messages for client after WebSocket disconnection or sleep.
     */
    public function resync(Request $request): JsonResponse
    {
        $user = $request->user();
        $phoneNumberId = (int) $request->input('phone_number_id');
        $afterId = $request->filled('after_id') ? (int) $request->input('after_id') : null;
        $peerNumber = $request->input('peer');

        if (! $phoneNumberId) {
            $phoneNumberId = (int) $user->phoneNumbers()
                ->where('status', PhoneNumberStatus::Allocated)
                ->value('id');
        }

        if (! $phoneNumberId) {
            return response()->json(['success' => false, 'message' => 'شماره خط نامشخص است.'], 400);
        }

        /** @var PhoneNumber|null $phoneNumber */
        $phoneNumber = PhoneNumber::where('id', $phoneNumberId)->first();
        if (! $phoneNumber || (! $user->isAdmin() && $phoneNumber->user_id !== $user->id)) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
        }

        $resyncData = $this->messageService->resyncMessages(
            phoneNumberId: $phoneNumber->id,
            afterId: $afterId,
            peerNumber: $peerNumber,
            limit: min(100, max(1, (int) $request->input('limit', 50)))
        );

        return response()->json([
            'success' => true,
            ...$resyncData,
        ]);
    }

    /**
     * Fetch conversation messages asynchronously for fast chat switching.
     */
    public function conversation(Request $request): JsonResponse
    {
        $user = $request->user();
        $phoneNumberId = (int) $request->input('phone_number_id');
        $peerNumber = trim((string) $request->input('peer'));
        $afterId = $request->filled('after_id') ? (int) $request->input('after_id') : null;
        $beforeId = $request->filled('before_id') ? (int) $request->input('before_id') : null;

        /** @var PhoneNumber $phoneNumber */
        $phoneNumber = PhoneNumber::findOrFail($phoneNumberId);

        if (! $user->isAdmin() && $phoneNumber->user_id !== $user->id) {
            throw new AccessDeniedHttpException('عدم دسترسی به این خط.');
        }

        $messages = $this->messageService->getConversationMessages($phoneNumber, $peerNumber, $afterId, $beforeId);

        $summary = $this->unreadCountService->getUnreadSummaryForUser($user);

        return response()->json([
            'success' => true,
            'count' => $messages->count(),
            'messages' => $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'phone_number_id' => $m->phone_number_id,
                'destination_number' => $m->destination_number,
                'sender' => $m->sender,
                'peer_number' => $m->peer_number,
                'direction' => $m->direction->value,
                'body' => $m->body,
                'server_received_at' => $m->server_received_at->toIso8601String(),
                'server_received_at_formatted' => $m->server_received_at->diffForHumans(),
                'processing_status' => $m->processing_status->value,
            ]),
            'unread_total' => $summary['unread_total'],
            'unread_by_line' => $summary['unread_by_line'],
        ]);
    }

    /**
     * Silent real-time polling endpoint (Runs automatically every 4 seconds).
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $phoneNumberId = (int) $request->input('phone_number_id');
        $activePeer = $request->input('peer');
        $afterId = max(0, (int) $request->input('after_id', 0));

        if (! $phoneNumberId) {
            $phoneNumberId = (int) $user->phoneNumbers()
                ->where('status', PhoneNumberStatus::Allocated)
                ->value('id');
        }

        if (! $phoneNumberId) {
            return response()->json(['success' => false, 'message' => 'شماره خط نامشخص است.']);
        }

        /** @var PhoneNumber|null $phoneNumber */
        $phoneNumber = PhoneNumber::where('id', $phoneNumberId)->first();
        if (! $phoneNumber || (! $user->isAdmin() && $phoneNumber->user_id !== $user->id)) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز.']);
        }

        // Fast Delta Detection: check cached latest message ID (avoids database polling query)
        $cacheService = app(\App\Contracts\SystemCacheServiceInterface::class);
        $latestId = $cacheService->getLineLatestMessageId($phoneNumber->id);
        $hasNewMessages = ($latestId > $afterId);

        // 1. Refreshed conversations list (Directly from Cache, auto-refreshed on message arrival)
        $conversations = $this->messageService->getConversations($phoneNumber, $request->input('search'));

        // 2. New messages in active conversation if open, or general delta if no activePeer
        $newMessages = collect();
        if ($hasNewMessages || $afterId === 0) {
            $newMessages = $activePeer
                ? $this->messageService->getConversationMessages($phoneNumber, $activePeer, $afterId)
                : $this->messageService->pollUserMessages($user, $afterId, $phoneNumber->id);
        }

        $mappedMessages = $newMessages->map(fn (Message $m) => [
            'id' => $m->id,
            'phone_number_id' => $m->phone_number_id,
            'destination_number' => $m->destination_number,
            'sender' => $m->sender,
            'peer_number' => $m->peer_number,
            'direction' => $m->direction->value,
            'body' => $m->body,
            'server_received_at' => $m->server_received_at->toIso8601String(),
            'server_received_at_formatted' => $m->server_received_at->diffForHumans(),
            'processing_status' => $m->processing_status->value,
        ]);

        $statuses = [];
        if ($activePeer && ($hasNewMessages || $afterId === 0)) {
            $cleanPeer = \App\Services\PhoneNumberNormalizer::resolveToStoredNumber($activePeer);
            $statuses = Message::where('phone_number_id', $phoneNumber->id)
                ->where(function ($q) use ($cleanPeer, $activePeer) {
                    $q->where('peer_number', $cleanPeer)
                        ->orWhere('peer_number', $activePeer);
                })
                ->where('direction', \App\Enums\MessageDirection::Outbound)
                ->latest('id')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Message $m) => [$m->id => $m->processing_status->value])
                ->all();
        }

        $unreadCountsByLine = $this->unreadCountService->getUnreadSummaryForUser($user)['unread_by_line'];

        return response()->json([
            'success' => true,
            'count' => $newMessages->count(),
            'data' => $mappedMessages,
            'new_messages' => $mappedMessages,
            'statuses' => $statuses,
            'conversations' => $conversations,
            'unread_counts_by_line' => $unreadCountsByLine,
        ]);
    }

    /**
     * Global lightweight unread counter & real-time sync summary for user layouts and dashboards.
     */
    public function unreadSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $summary = $this->unreadCountService->getUnreadSummaryForUser($user);

        $allocatedLineIds = $user->phoneNumbers()
            ->where('status', PhoneNumberStatus::Allocated)
            ->pluck('id');

        $latestMessage = $allocatedLineIds->isNotEmpty()
            ? Message::whereIn('phone_number_id', $allocatedLineIds)
                ->where('direction', MessageDirection::Inbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->latest('id')
                ->first()
            : null;

        return response()->json([
            'success' => true,
            'unread_total' => $summary['unread_total'],
            'unread_by_line' => $summary['unread_by_line'],
            'latest_message' => $latestMessage ? [
                'id' => $latestMessage->id,
                'phone_number_id' => $latestMessage->phone_number_id,
                'destination_number' => $latestMessage->destination_number,
                'sender' => $latestMessage->sender,
                'peer_number' => $latestMessage->peer_number,
                'direction' => $latestMessage->direction->value,
                'processing_status' => $latestMessage->processing_status->value,
                'body' => $latestMessage->body,
                'server_received_at' => $latestMessage->server_received_at?->toIso8601String(),
                'server_received_at_formatted' => $latestMessage->server_received_at?->diffForHumans(),
            ] : null,
        ]);
    }

    /**
     * Show details of a specific message.
     */
    public function show(Request $request, Message $message): JsonResponse
    {
        if (! $request->user()->isAdmin() && $message->phoneNumber->user_id !== $request->user()->id) {
            abort(403, 'شما اجازه دسترسی به این پیام را ندارید.');
        }

        return response()->json([
            'success' => true,
            'message' => $message->load('phoneNumber'),
        ]);
    }
}
