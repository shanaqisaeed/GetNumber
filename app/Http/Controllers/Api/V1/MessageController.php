<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MessageServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        protected MessageServiceInterface $messageService
    ) {}

    /**
     * Unified inbound/outbound messaging API endpoint.
     * Supports single and bulk destinations, gateway intake, idempotency, and audit tracking.
     * Accessible by API tokens with 'messages:write', 'messages:send', or '*' abilities.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $token->can('*') && ! $token->can('messages:write') && ! $token->can('messages:send')) {
            return response()->json([
                'success' => false,
                'message' => 'کلید ارتباطی فاقد مجوز ثبت یا ارسال پیام (messages:write یا messages:send) می‌باشد.',
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ], 403);
        }

        // 1. Validate destination presence
        if (! $request->filled('destination_number') && ! $request->filled('destinations') && ! $request->filled('to')) {
            return response()->json([
                'success' => false,
                'message' => 'شماره مقصد (destination_number) الزامی است.',
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'destination_number' => ['شماره مقصد الزامی است.'],
                ],
            ], 422);
        }

        // 2. Validate sender presence
        if (! $request->filled('sender') && ! $request->filled('from_number') && ! $request->filled('from') && ! $request->filled('from_number_id')) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه فرستنده الزامی است.',
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'sender' => ['شناسه فرستنده الزامی است.'],
                ],
            ], 422);
        }

        // 3. Validate body presence and length
        if (! $request->filled('body') && ! $request->filled('message') && ! $request->filled('text')) {
            return response()->json([
                'success' => false,
                'message' => 'متن پیام الزامی است.',
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'body' => ['متن پیام الزامی است.'],
                ],
            ], 422);
        }

        $bodyValue = (string) ($request->input('body') ?? ($request->input('message') ?? $request->input('text')));
        if (mb_strlen($bodyValue) > 5000) {
            return response()->json([
                'success' => false,
                'message' => 'متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد.',
                'code' => 'VALIDATION_ERROR',
                'errors' => [
                    'body' => ['متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد.'],
                ],
            ], 422);
        }

        // 4. Validate optional fields
        $request->validate([
            'source_time' => ['nullable', 'date'],
            'source_system_id' => ['nullable', 'string', 'max:100'],
            'client_message_id' => ['nullable', 'string', 'max:100'],
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
        ]);

        $result = $this->messageService->dispatchUnifiedMessage(
            payload: $request->all(),
            actor: $request->user(),
            token: $token
        );

        if ($result['mode'] === 'bulk') {
            $isDuplicate = $result['is_duplicate'] ?? false;
            $sentCount = $result['sent_count'] ?? 0;
            $failedCount = $result['failed_count'] ?? 0;

            return response()->json([
                'success' => $sentCount > 0,
                'message' => $isDuplicate
                    ? 'پیام‌های این بسته پیش‌تر در سامانه ثبت شده‌اند (درخواست تکراری / Idempotent).'
                    : ($sentCount > 0 ? "تعداد {$sentCount} پیام با موفقیت ارسال گردید." : 'ارسال پیام به تمامی مقاصد ناموفق بود.'),
                'data' => [
                    'sender' => $result['sender'] ?? null,
                    'total' => $result['total'] ?? 0,
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                    'is_duplicate' => $isDuplicate,
                    'results' => $result['results'] ?? [],
                ],
            ], $isDuplicate ? 200 : ($sentCount > 0 ? 201 : 422));
        }

        // Single destination mode
        $message = $result['message'];
        $isDuplicate = $result['is_duplicate'];
        $isOutbound = ($message->direction === \App\Enums\MessageDirection::Outbound || $message->direction->value === 'outbound');

        return response()->json([
            'success' => true,
            'message' => $isDuplicate
                ? 'پیام پیش‌تر در سامانه ثبت شده است (درخواست تکراری / Idempotent).'
                : ($isOutbound ? 'پیام خروجی با موفقیت در سامانه ثبت گردید.' : 'پیام ورودی با موفقیت در سامانه ثبت گردید.'),
            'data' => [
                'id' => $message->id,
                'destination_number' => $message->destination_number,
                'destination_title' => $message->phoneNumber?->effective_title ?? (\App\Models\PhonePrefix::getDefaultPrefixString() . $message->destination_number),
                'sender' => $message->sender,
                'direction' => $message->direction->value,
                'body' => $message->body,
                'source_time' => $message->source_time?->toIso8601String(),
                'server_received_at' => $message->server_received_at->toIso8601String(),
                'source_system_id' => $message->source_system_id,
                'client_message_id' => $message->client_message_id,
                'processing_status' => $message->processing_status->value,
                'is_duplicate' => $isDuplicate,
            ],
        ], $isDuplicate ? 200 : 201);
    }

    /**
     * List messages via API.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $token->can('*') && ! $token->can('messages:read')) {
            return response()->json([
                'success' => false,
                'message' => 'کلید ارتباطی فاقد مجوز مشاهده پیام‌ها (messages:read) می‌باشد.',
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ], 403);
        }

        $isAdmin = $user->isAdmin() && ($token->can('*') || $token->can('messages:manage'));

        $messages = $isAdmin
            ? $this->messageService->getAllMessages($request->all(), (int) $request->input('per_page', 15))
            : $this->messageService->getUserMessages($user, $request->all(), (int) $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'فهرست پیام‌ها با موفقیت دریافت شد.',
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
            'data' => $messages->items(),
        ]);
    }

    /**
     * Fast polling endpoint for new messages arriving after a given ID.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $token->can('*') && ! $token->can('messages:read')) {
            return response()->json([
                'success' => false,
                'message' => 'کلید ارتباطی فاقد مجوز استعلام پیام‌ها می‌باشد.',
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ], 403);
        }

        $afterId = max(0, (int) $request->input('after_id', 0));
        $phoneId = $request->filled('phone_number_id') ? (int) $request->input('phone_number_id') : null;

        $isAdmin = $user->isAdmin() && ($token->can('*') || $token->can('messages:manage'));

        $newMessages = $isAdmin
            ? $this->messageService->pollAllMessages($afterId, $request->all(), 50)
            : $this->messageService->pollUserMessages($user, $afterId, $phoneId, 50);

        return response()->json([
            'success' => true,
            'count' => $newMessages->count(),
            'after_id' => $afterId,
            'data' => $newMessages->map(fn ($m) => [
                'id' => $m->id,
                'destination_number' => $m->destination_number,
                'sender' => $m->sender,
                'body' => $m->body,
                'source_time' => $m->source_time?->toIso8601String(),
                'server_received_at' => $m->server_received_at->toIso8601String(),
                'processing_status' => $m->processing_status->value,
            ]),
        ]);
    }
}
