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
     * Inbound message receiver API endpoint.
     * Accessible by client API tokens (for their allocated numbers) or admin API tokens (for any number).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        if (! $token->can('*') && ! $token->can('messages:write')) {
            return response()->json([
                'success' => false,
                'message' => 'کلید ارتباطی فاقد مجوز ثبت پیام (messages:write) می‌باشد.',
                'code' => 'INSUFFICIENT_TOKEN_ABILITIES',
            ], 403);
        }

        $validated = $request->validate([
            'destination_number' => ['required', 'string', 'max:30'],
            'sender' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:5000'],
            'source_time' => ['nullable', 'date'],
            'source_system_id' => ['nullable', 'string', 'max:100'],
            'client_message_id' => ['nullable', 'string', 'max:100'],
        ], [
            'destination_number.required' => 'شماره مقصد الزامی است.',
            'sender.required' => 'شناسه فرستنده الزامی است.',
            'body.required' => 'متن پیام الزامی است.',
            'body.max' => 'متن پیام نمی‌تواند بیش از ۵۰۰۰ کاراکتر باشد.',
        ]);

        $result = $this->messageService->recordInboundMessage(
            data: $validated,
            actor: $request->user(),
            token: $token
        );

        $message = $result['message'];
        $isDuplicate = $result['is_duplicate'];

        return response()->json([
            'success' => true,
            'message' => $isDuplicate
                ? 'پیام پیش‌تر در سامانه ثبت شده است (درخواست تکراری / Idempotent).'
                : 'پیام ورودی با موفقیت در سامانه ثبت گردید.',
            'data' => [
                'id' => $message->id,
                'destination_number' => $message->destination_number,
                'destination_title' => $message->phoneNumber?->effective_title ?? (\App\Models\PhonePrefix::getDefaultPrefixString() . $message->destination_number),
                'sender' => $message->sender,
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
