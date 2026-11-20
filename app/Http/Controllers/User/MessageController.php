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
        $userCustomIds = $user->activeServiceAllocations()
            ->where('service_key', 'custom_id')
            ->get();

        $lineKeyParam = $request->input('line_key') ?: session('active_line_key');
        $selectedCidParam = $request->input('custom_id');
        $selectedCustomId = null;
        /** @var PhoneNumber|null $currentNumber */
        $currentNumber = null;

        // Resolve active line from line_key composite if provided
        if ($lineKeyParam) {
            if (str_starts_with($lineKeyParam, 'custom_')) {
                $cidId = (int) substr($lineKeyParam, 7);
                $selectedCustomId = $userCustomIds->firstWhere('id', $cidId);
            } elseif (str_starts_with($lineKeyParam, 'phone_')) {
                $pId = (int) substr($lineKeyParam, 6);
                $currentNumber = $allocatedNumbers->firstWhere('id', $pId);
            }
        }

        // Resolve active line from custom_id param
        if (! $selectedCustomId && ! $currentNumber && $selectedCidParam) {
            $cleanParam = ltrim(trim($selectedCidParam), '@');
            $selectedCustomId = $userCustomIds->first(function ($c) use ($selectedCidParam, $cleanParam) {
                return $c->resource_identifier === $selectedCidParam
                    || ltrim($c->resource_identifier, '@') === $cleanParam;
            });
        }

        // Fallback to phone_number_id param if line_key was not used
        if (! $selectedCustomId && ! $currentNumber && $request->filled('phone_number_id')) {
            $reqLineId = (int) $request->input('phone_number_id');
            $currentNumber = $allocatedNumbers->firstWhere('id', $reqLineId);
            if (! $currentNumber) {
                $selectedCustomId = $userCustomIds->firstWhere('id', $reqLineId);
            }
        }

        // Default to first available line
        if (! $currentNumber && ! $selectedCustomId) {
            if ($allocatedNumbers->isNotEmpty()) {
                $currentNumber = $allocatedNumbers->first();
            } elseif ($userCustomIds->isNotEmpty()) {
                $selectedCustomId = $userCustomIds->first();
            }
        }

        $conversations = collect();
        // Only set activePeer if user explicitly asked for a peer conversation
        $rawPeer = $request->input('peer') ?: $request->input('to') ?: $request->input('destination') ?: $request->input('number');
        $activePeer = null;
        if (! empty($rawPeer)) {
            $trimmed = trim((string) $rawPeer);
            if (str_starts_with($trimmed, '@') || ! preg_match('/^\+?[0-9]+$/', $trimmed)) {
                $activePeer = str_starts_with($trimmed, '@') ? $trimmed : '@' . $trimmed;
            } else {
                $activePeer = \App\Services\PhoneNumberNormalizer::resolveToStoredNumber($trimmed)
                    ?: preg_replace('/[^\d]/', '', $trimmed);
            }
        }
        $activeMessages = collect();
        $activePeerDetails = null;

        if ($selectedCustomId) {
            $cidHandle = $selectedCustomId->resource_identifier;
            $search = $request->input('search');

            $rawMessages = Message::where(function ($q) use ($cidHandle) {
                $q->where('sender', $cidHandle)
                    ->orWhere('destination_number', $cidHandle)
                    ->orWhere('peer_number', $cidHandle);
            })
            ->whereNull('user_deleted_at')
            ->latest('server_received_at')
            ->get();

            if ($search) {
                $rawMessages = $rawMessages->filter(fn ($m) => str_contains($m->body, $search) || str_contains($m->peer_number, $search));
            }

            $conversations = $rawMessages->groupBy('peer_number')->map(function ($msgs, $peer) use ($cidHandle) {
                $latest = $msgs->first();
                return [
                    'peer_number' => $peer,
                    'peer_formatted' => $peer,
                    'peer_title' => $peer,
                    'peer_type' => 'custom_id',
                    'peer_type_label' => __('controllers.user.messages.peer_type_contact'),
                    'peer_badge_class' => 'bg-blue-500/15 text-blue-600',
                    'latest_message' => [
                        'id' => $latest->id,
                        'body' => $latest->body,
                        'direction' => $latest->direction->value,
                        'server_received_at' => $latest->server_received_at->toIso8601String(),
                        'server_received_at_formatted' => $latest->server_received_at->diffForHumans(),
                    ],
                    'messages_count' => $msgs->count(),
                    'unread_count' => $msgs->where('direction', MessageDirection::Inbound)->where('processing_status', MessageProcessingStatus::Received)->count(),
                    'is_own_number' => false,
                    'is_commercial' => false,
                    'accepts_incoming_sms' => true,
                    'can_reply' => true,
                    'can_reply_reason' => null,
                ];
            })->values();

            if ($activePeer) {
                $activeMessages = $rawMessages->where('peer_number', $activePeer)->sortBy('id')->values();
                $activePeerDetails = [
                    'number' => $activePeer,
                    'formatted_number' => $activePeer,
                    'title' => $activePeer,
                    'type' => 'custom_id',
                    'type_label' => __('controllers.user.messages.type_label_handle'),
                    'badge_class' => 'bg-blue-500/15 text-blue-600',
                    'is_own_number' => false,
                    'is_commercial' => false,
                    'accepts_incoming_sms' => true,
                    'can_reply' => true,
                    'can_reply_reason' => null,
                ];
            }
        } elseif ($currentNumber) {
            $search = $request->input('search');

            // 1. If an active conversation is open, mark messages as read first
            if ($activePeer) {
                if (str_starts_with($activePeer, '@')) {
                    $activeMessages = Message::where(function ($q) use ($activePeer, $currentNumber) {
                        $q->where(function ($sub) use ($activePeer, $currentNumber) {
                            $sub->where('phone_number_id', $currentNumber->id)
                                ->where('destination_number', $activePeer);
                        })->orWhere(function ($sub) use ($activePeer, $currentNumber) {
                            $sub->where('phone_number_id', $currentNumber->id)
                                ->where('sender', $activePeer);
                        });
                    })
                    ->whereNull('user_deleted_at')
                    ->latest('server_received_at')->get()->sortBy('id')->values();

                    $activePeerDetails = [
                        'number' => $activePeer,
                        'formatted_number' => $activePeer,
                        'title' => $activePeer,
                        'type' => 'custom_id',
                        'type_label' => __('controllers.user.messages.type_label_handle'),
                        'badge_class' => 'bg-blue-500/15 text-blue-600',
                        'is_own_number' => false,
                        'is_commercial' => false,
                        'accepts_incoming_sms' => true,
                        'can_reply' => true,
                        'can_reply_reason' => null,
                    ];
                } else {
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
                            $canReplyReason = __('controllers.user.messages.one_way_prefix_notice');
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
                        'type' => $peerRecord?->type->value ?? 'contact',
                        'type_label' => $peerRecord?->type->label() ?? __('controllers.user.messages.peer_type_contact'),
                        'badge_class' => $peerRecord?->type->badgeClasses() ?? 'bg-slate-500/10 text-slate-500',
                        'is_own_number' => $peerRecord && $peerRecord->user_id === $user->id,
                        'is_commercial' => $isCommercial,
                        'accepts_incoming_sms' => $peerRecord ? (bool) $peerRecord->accepts_incoming_sms : true,
                        'can_reply' => $canReply,
                        'can_reply_reason' => $canReplyReason,
                    ];
                }
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

        $userCustomIds = $user->activeServiceAllocations()
            ->where('service_key', 'custom_id')
            ->get();

        $customIdLines = $userCustomIds->map(fn ($cid) => [
            'id' => $cid->id,
            'key' => 'custom_' . $cid->id,
            'is_custom_id' => true,
            'number' => $cid->resource_identifier,
            'formatted_number' => $cid->resource_identifier,
            'effective_title' => $cid->display_title ?: __('controllers.user.messages.custom_id_with_handle', ['handle' => $cid->resource_identifier]),
            'type' => 'custom_id',
            'type_label' => __('controllers.user.messages.type_label_custom_id'),
            'badge_class' => 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30',
            'unread_count' => 0,
            'phone_prefix_id' => null,
            'prefix_code' => '@',
            'sms_rate_per_segment' => (string) \App\Models\SystemSetting::get('id_sms_rate', '0.0000'),
        ]);

        $allAllocatedLines = $allocatedNumbers->map(fn (PhoneNumber $p) => [
            'id' => $p->id,
            'key' => 'phone_' . $p->id,
            'is_custom_id' => false,
            'number' => $p->number,
            'formatted_number' => $p->formatted_number,
            'effective_title' => $p->effective_title,
            'type' => $p->type->value,
            'type_label' => $p->type->label(),
            'badge_class' => $p->type->badgeClasses(),
            'unread_count' => (int) ($unreadCounts[$p->id] ?? 0),
            'phone_prefix_id' => $p->phone_prefix_id,
            'prefix_code' => $p->prefix?->prefix ?? \App\Models\PhonePrefix::getDefaultPrefixString(),
            'sms_rate_per_segment' => $p->prefix?->getSmsRatePerSegment()
                ?? \App\Models\PhonePrefix::getDefaultPrefix()?->getSmsRatePerSegment()
                ?? (string) \App\Models\SystemSetting::get('sms_intra_prefix_rate', '0.0500'),
        ])->concat($customIdLines)->values();

        $currentNumberPayload = null;
        if ($currentNumber) {
            $currentNumberPayload = [
                'id' => $currentNumber->id,
                'key' => 'phone_' . $currentNumber->id,
                'number' => $currentNumber->number,
                'formatted_number' => $currentNumber->formatted_number,
                'effective_title' => $currentNumber->effective_title,
                'type' => $currentNumber->type->value,
                'type_label' => $currentNumber->type->label(),
                'badge_class' => $currentNumber->type->badgeClasses(),
                'unread_count' => (int) ($unreadCounts[$currentNumber->id] ?? 0),
                'phone_prefix_id' => $currentNumber->phone_prefix_id,
                'prefix_code' => $currentNumber->prefix?->prefix ?? \App\Models\PhonePrefix::getDefaultPrefixString(),
                'sms_rate_per_segment' => $currentNumber->prefix?->getSmsRatePerSegment()
                    ?? \App\Models\PhonePrefix::getDefaultPrefix()?->getSmsRatePerSegment()
                    ?? (string) \App\Models\SystemSetting::get('sms_intra_prefix_rate', '0.0500'),
            ];
        } elseif ($selectedCustomId) {
            $currentNumberPayload = $customIdLines->firstWhere('id', $selectedCustomId->id);
        }

        $activeLineKey = $selectedCustomId ? 'custom_' . $selectedCustomId->id : ($currentNumber ? 'phone_' . $currentNumber->id : null);
        if ($activeLineKey) {
            session(['active_line_key' => $activeLineKey]);
        }

        $contacts = \App\Models\Contact::where('user_id', $user->id)
            ->latest('id')
            ->get(['id', 'name', 'phone_number', 'avatar_color', 'notes']);

        return Inertia::render('User/Messages/Index', [
            'allocatedNumbers' => $allAllocatedLines,
            'currentNumber' => $currentNumberPayload,
            'active_line' => $currentNumberPayload,
            'lines' => $allAllocatedLines,
            'selected_line_key' => $activeLineKey,
            'contacts' => $contacts,
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
                'rate_per_segment' => $selectedCustomId
                    ? (string) \App\Models\SystemSetting::get('id_sms_rate', '0.0000')
                    : ($currentNumber?->prefix?->getSmsRatePerSegment()
                        ?? \App\Models\PhonePrefix::getDefaultPrefix()?->getSmsRatePerSegment()
                        ?? (string) \App\Models\SystemSetting::get('sms_intra_prefix_rate', '0.0500')),
                'prefix_code' => $selectedCustomId ? '@' : ($currentNumber?->prefix?->prefix ?? \App\Models\PhonePrefix::getDefaultPrefixString()),
                'currency' => 'COIN',
                'by_prefix' => \App\Models\PhonePrefix::active()->get(['id', 'prefix', 'country_name', 'flag', 'sms_price_per_segment', 'inbound_sms_price']),
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

        $fromId = $request->input('from_number_id') ?: $request->input('phone_number_id');
        $destinations = $request->input('destinations') ?: $request->input('destination');
        $body = $request->input('body');

        $validated = validator([
            'from_number_id' => $fromId,
            'destinations' => $destinations,
            'body' => $body,
        ], [
            'from_number_id' => ['required', 'integer', 'exists:phone_numbers,id'],
            'destinations' => ['required'],
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'from_number_id.required' => __('controllers.user.messages.val_from_required'),
            'destinations.required' => __('controllers.user.messages.val_destinations_required'),
            'body.required' => __('controllers.user.messages.val_body_required'),
            'body.max' => __('controllers.user.messages.val_body_max'),
        ])->validate();

        /** @var PhoneNumber $fromNumber */
        $fromNumber = PhoneNumber::findOrFail($validated['from_number_id']);

        if (! $user->isAdmin() && $fromNumber->user_id !== $user->id && $fromNumber->assigned_user_id !== $user->id) {
            throw new AccessDeniedHttpException(__('controllers.user.messages.access_denied_not_owner'));
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
                    ? __('controllers.user.messages.sent_success_count', ['count' => $result['sent_count']])
                    : __('controllers.user.messages.send_failed'),
            ]);
        }

        if ($result['sent_count'] > 0) {
            $flash = __('controllers.user.messages.sent_success_count', ['count' => $result['sent_count']]);
            if ($result['failed_count'] > 0) {
                $flash .= __('controllers.user.messages.failed_destinations_notice', ['count' => $result['failed_count']]);
            }
            return back()->with('success', $flash);
        }

        $failedReason = $result['results'][0]['reason'] ?? __('controllers.user.messages.dest_not_ready');
        return back()->with('error', __('controllers.user.messages.send_failed_reason', ['reason' => $failedReason]));
    }

    /**
     * Authoritative server-side SMS segment & cost estimation endpoint.
     */
    public function estimateCost(Request $request, \App\Services\SmsSegmentCalculatorService $calculator): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'destinations_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'from_number_id' => ['nullable', 'integer', 'exists:phone_numbers,id'],
            'prefix_id' => ['nullable', 'integer', 'exists:phone_prefixes,id'],
            'destination_number' => ['nullable', 'string', 'max:50'],
        ]);

        $body = (string) ($validated['body'] ?? '');
        $destCount = (int) ($validated['destinations_count'] ?? 1);
        $prefixId = isset($validated['prefix_id']) ? (int) $validated['prefix_id'] : null;
        $numberForPrefix = $validated['destination_number'] ?? null;

        if (! $prefixId && ! empty($validated['from_number_id'])) {
            $fromNumber = PhoneNumber::with('prefix')->find((int) $validated['from_number_id']);
            if ($fromNumber) {
                $prefixId = $fromNumber->phone_prefix_id;
                $numberForPrefix = $numberForPrefix ?: $fromNumber->number;
            }
        }

        $calculation = $calculator->calculate(
            text: $body,
            destinationsCount: $destCount,
            ratePerSegment: null,
            prefixId: $prefixId,
            numberForPrefix: $numberForPrefix
        );

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
            return response()->json(['success' => false, 'message' => __('controllers.user.messages.number_unknown')], 400);
        }

        /** @var PhoneNumber|null $phoneNumber */
        $phoneNumber = PhoneNumber::where('id', $phoneNumberId)->first();
        if (! $phoneNumber || (! $user->isAdmin() && $phoneNumber->user_id !== $user->id && $phoneNumber->assigned_user_id !== $user->id)) {
            return response()->json(['success' => false, 'message' => __('controllers.user.messages.unauthorized')], 403);
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

        if (! $user->isAdmin() && $phoneNumber->user_id !== $user->id && $phoneNumber->assigned_user_id !== $user->id) {
            throw new AccessDeniedHttpException(__('controllers.user.messages.line_access_denied'));
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
            return response()->json(['success' => false, 'message' => __('controllers.user.messages.number_unknown')]);
        }

        /** @var PhoneNumber|null $phoneNumber */
        $phoneNumber = PhoneNumber::where('id', $phoneNumberId)->first();
        if (! $phoneNumber || (! $user->isAdmin() && $phoneNumber->user_id !== $user->id && $phoneNumber->assigned_user_id !== $user->id)) {
            return response()->json(['success' => false, 'message' => __('controllers.user.messages.unauthorized')]);
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

        $allocatedLineIds = PhoneNumber::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('assigned_user_id', $user->id);
            })
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
        if (! $request->user()->isAdmin() && $message->phoneNumber->user_id !== $request->user()->id && $message->phoneNumber->assigned_user_id !== $request->user()->id) {
            abort(403, __('controllers.user.messages.message_access_denied'));
        }

        return response()->json([
            'success' => true,
            'message' => $message->load('phoneNumber'),
        ]);
    }

    /**
     * Soft-delete a single message for the user view.
     */
    public function destroy(Request $request, Message $message): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $phone = $message->phoneNumber;
        $isOwner = $phone && ($phone->user_id === $user->id || $phone->assigned_user_id === $user->id);

        if (! $user->isAdmin() && ! $isOwner && $message->sender !== $user->email && $message->destination_number !== $user->email) {
            abort(403, __('controllers.user.messages.message_access_denied'));
        }

        $message->update(['user_deleted_at' => now()]);

        if ($message->phone_number_id) {
            app(\App\Contracts\SystemCacheServiceInterface::class)->invalidateLineConversations($message->phone_number_id);
            if ($message->peer_number) {
                app(\App\Contracts\SystemCacheServiceInterface::class)->invalidateLineMessages($message->phone_number_id, $message->peer_number);
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'پیام با موفقیت حذف گردید.',
            ]);
        }

        return back()->with('success', 'پیام با موفقیت حذف گردید.');
    }

    /**
     * Soft-delete multiple messages in bulk for the user view.
     */
    public function destroyBulk(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $query = Message::whereIn('id', $validated['ids']);
        if (! $user->isAdmin()) {
            $userLineIds = $user->phoneNumbers()->pluck('id');
            $query->whereIn('phone_number_id', $userLineIds);
        }

        $messages = $query->get(['id', 'phone_number_id', 'peer_number']);
        $count = $query->update(['user_deleted_at' => now()]);

        $cache = app(\App\Contracts\SystemCacheServiceInterface::class);
        $lineIds = $messages->pluck('phone_number_id')->filter()->unique();
        foreach ($lineIds as $lId) {
            $cache->invalidateLineConversations($lId);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'deleted_count' => $count,
                'message' => "{$count} پیام انتخابی با موفقیت حذف شدند.",
            ]);
        }

        return back()->with('success', "{$count} پیام انتخابی با موفقیت حذف شدند.");
    }

    /**
     * Soft-delete an entire conversation thread for the user view.
     */
    public function destroyConversation(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'phone_number_id' => ['nullable', 'integer'],
            'custom_id' => ['nullable', 'string'],
            'peer_number' => ['required', 'string'],
        ]);

        $peer = trim($validated['peer_number']);
        $cleanPeer = \App\Services\PhoneNumberNormalizer::resolveToStoredNumber($peer) ?: $peer;

        if (! empty($validated['phone_number_id'])) {
            $pId = (int) $validated['phone_number_id'];
            $phone = PhoneNumber::find($pId);
            if (! $user->isAdmin() && (! $phone || ($phone->user_id !== $user->id && $phone->assigned_user_id !== $user->id))) {
                abort(403, __('controllers.user.messages.unauthorized'));
            }

            $count = Message::where('phone_number_id', $pId)
                ->where(function ($q) use ($cleanPeer, $peer) {
                    $q->where('peer_number', $cleanPeer)
                      ->orWhere('peer_number', $peer)
                      ->orWhere('sender', $cleanPeer)
                      ->orWhere('sender', $peer)
                      ->orWhere('destination_number', $cleanPeer)
                      ->orWhere('destination_number', $peer);
                })
                ->update(['user_deleted_at' => now()]);

            $cache = app(\App\Contracts\SystemCacheServiceInterface::class);
            $cache->invalidateLineConversations($pId);
            $cache->invalidateLineMessages($pId, $cleanPeer);
        } else {
            $cid = $validated['custom_id'] ?? '';
            $count = Message::where(function ($q) use ($cid) {
                    $q->where('sender', $cid)
                      ->orWhere('destination_number', $cid)
                      ->orWhere('peer_number', $cid);
                })
                ->where('peer_number', $peer)
                ->update(['user_deleted_at' => now()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'deleted_count' => $count ?? 0,
                'message' => 'گفتگو با موفقیت حذف گردید.',
            ]);
        }

        return back()->with('success', 'گفتگو با موفقیت حذف گردید.');
    }
}