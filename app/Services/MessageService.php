<?php

namespace App\Services;

use App\Contracts\MessageServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\ApiToken;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;
use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MessageService implements MessageServiceInterface
{
    /**
     * Unified message dispatcher supporting single or bulk destinations,
     * inbound intake and outbound delivery, with full idempotency and auditing.
     */
    public function dispatchUnifiedMessage(array $payload, ?User $actor = null, ?ApiToken $token = null): array
    {
        $effectiveActor = $actor ?? $token?->user;

        // 1. Normalize sender
        $rawSender = (string) ($payload['sender'] ?? ($payload['from_number'] ?? ($payload['from'] ?? '')));
        if (empty($rawSender) && ! empty($payload['from_number_id'])) {
            $senderPhoneById = PhoneNumber::find($payload['from_number_id']);
            if ($senderPhoneById) {
                $rawSender = $senderPhoneById->number;
            }
        }

        // 2. Normalize destinations
        $destinations = [];
        if (isset($payload['destinations'])) {
            if (is_array($payload['destinations'])) {
                $destinations = $payload['destinations'];
            } elseif (is_string($payload['destinations'])) {
                $destinations = preg_split('/[\r\n,]+/', $payload['destinations']);
            }
        } elseif (isset($payload['destination_number'])) {
            $destinations = [$payload['destination_number']];
        } elseif (isset($payload['to'])) {
            if (is_array($payload['to'])) {
                $destinations = $payload['to'];
            } else {
                $destinations = preg_split('/[\r\n,]+/', (string) $payload['to']);
            }
        }

        $destinations = collect($destinations)
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => ! empty($d))
            ->unique()
            ->values()
            ->all();

        // 3. Normalize body
        $body = trim((string) ($payload['body'] ?? ($payload['message'] ?? ($payload['text'] ?? ''))));

        // 4. Determine single vs bulk mode
        $isBulk = count($destinations) > 1;

        if (! $isBulk) {
            // Single destination mode: delegating to existing recordInboundMessage logic for maximum consistency
            $singleDest = $destinations[0] ?? (string) ($payload['destination_number'] ?? '');
            $normalizedPayload = [
                ...$payload,
                'sender' => $rawSender,
                'destination_number' => $singleDest,
                'body' => $body,
            ];

            $result = $this->recordInboundMessage($normalizedPayload, $actor, $token);
            $message = $result['message'];
            $isDuplicate = $result['is_duplicate'];

            // Log audit trail
            \App\Models\AuditLog::create([
                'user_id' => $effectiveActor?->id,
                'action' => ($message->direction === MessageDirection::Outbound || $message->direction->value === 'outbound')
                    ? 'api.message.sent'
                    : 'api.message.inbound_recorded',
                'entity_type' => Message::class,
                'entity_id' => $message->id,
                'payload' => [
                    'direction' => $message->direction->value,
                    'sender' => $message->sender,
                    'destination' => $message->destination_number,
                    'client_message_id' => $message->client_message_id,
                    'is_duplicate' => $isDuplicate,
                    'token_id' => $token?->id,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
            ]);

            return [
                'mode' => 'single',
                'message' => $message,
                'is_duplicate' => $isDuplicate,
                'sent_count' => 1,
                'failed_count' => 0,
                'results' => [
                    [
                        'destination' => $singleDest,
                        'status' => 'sent',
                        'message_id' => $message->id,
                        'reason' => null,
                    ],
                ],
            ];
        }

        // Multiple destinations mode (Bulk Dispatch)
        // 5. Validation for Bulk Dispatch
        if (empty($rawSender)) {
            throw ValidationException::withMessages([
                'sender' => 'خط فرستنده جهت ارسال چندگانه الزامی است.',
            ]);
        }

        $cleanSender = PhoneNumberNormalizer::resolveToStoredNumber($rawSender);
        $fromPhone = PhoneNumber::where('number', $cleanSender)->first();

        if (! $fromPhone) {
            throw ValidationException::withMessages([
                'sender' => 'خط فرستنده در سامانه یافت نشد.',
            ]);
        }

        if ($fromPhone->status !== PhoneNumberStatus::Allocated) {
            throw ValidationException::withMessages([
                'sender' => "خط فرستنده در وضعیت «{$fromPhone->status->label()}» بوده و آماده ارسال پیام نمی‌باشد.",
            ]);
        }

        if (! $effectiveActor?->isAdmin() && $fromPhone->user_id !== $effectiveActor?->id) {
            throw new AccessDeniedHttpException('شما مالک این خط نبوده و مجاز به ارسال پیامک از طریق آن نمی‌باشید.');
        }

        if ($fromPhone->type === PhoneNumberType::PlatformService && ! $effectiveActor?->isAdmin()) {
            throw new AccessDeniedHttpException('ارسال پیامک از سرشماره اختصاصی سامانه منحصراً در اختیار راهبر سیستم است.');
        }

        if (empty($body)) {
            throw ValidationException::withMessages([
                'body' => 'متن پیام نمی‌تواند خالی باشد.',
            ]);
        }

        // 6. Check bulk idempotency
        $sourceSystemId = ! empty($payload['source_system_id']) ? trim((string) $payload['source_system_id']) : null;
        $clientMessageId = ! empty($payload['client_message_id']) ? trim((string) $payload['client_message_id']) : null;

        if ($clientMessageId !== null && $sourceSystemId === null) {
            $sourceSystemId = $token ? ('token:' . $token->id) : ('actor:' . ($effectiveActor?->id ?? 'system'));
        }

        if ($sourceSystemId !== null && $clientMessageId !== null) {
            $existing = Message::where('source_system_id', $sourceSystemId)
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existing) {
                return [
                    'mode' => 'bulk',
                    'sender' => $fromPhone->number,
                    'message' => $existing,
                    'is_duplicate' => true,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'results' => [],
                ];
            }
        }

        // 7. Dispatch each destination
        $results = [];
        $sentCount = 0;
        $failedCount = 0;
        $createdMessages = [];

        foreach ($destinations as $dest) {
            $cleanDest = PhoneNumberNormalizer::resolveToStoredNumber((string) $dest);
            $destPhone = PhoneNumber::where('number', $cleanDest)->first();

            if ($destPhone) {
                // Check receiving line permissions
                $permission = $destPhone->canReceiveMessagesFrom($fromPhone, $effectiveActor);
                if (! $permission['allowed']) {
                    $results[] = [
                        'destination' => $dest,
                        'status' => 'failed',
                        'message_id' => null,
                        'reason' => $permission['reason'],
                    ];
                    $failedCount++;
                    continue;
                }
            }

            try {
                $outboundPair = DB::transaction(function () use ($fromPhone, $destPhone, $cleanDest, $body, $sourceSystemId, $clientMessageId) {
                    $msg = Message::create([
                        'phone_number_id' => $fromPhone->id,
                        'destination_number' => $cleanDest,
                        'sender' => $fromPhone->number,
                        'peer_number' => $cleanDest,
                        'body' => $body,
                        'server_received_at' => now(),
                        'source_system_id' => $sourceSystemId,
                        'client_message_id' => $clientMessageId ? ($clientMessageId . ':' . $cleanDest) : null,
                        'processing_status' => MessageProcessingStatus::Received,
                        'direction' => MessageDirection::Outbound,
                    ]);

                    $inboundMsg = null;
                    if ($destPhone && $destPhone->status === PhoneNumberStatus::Allocated && $destPhone->accepts_incoming_sms) {
                        $inboundMsg = Message::create([
                            'phone_number_id' => $destPhone->id,
                            'destination_number' => $destPhone->number,
                            'sender' => $fromPhone->number,
                            'peer_number' => $fromPhone->number,
                            'body' => $body,
                            'server_received_at' => now(),
                            'source_system_id' => $sourceSystemId ? ($sourceSystemId . ':in') : null,
                            'client_message_id' => $clientMessageId ? ($clientMessageId . ':in:' . $cleanDest) : null,
                            'processing_status' => MessageProcessingStatus::Received,
                            'direction' => MessageDirection::Inbound,
                        ]);
                    }

                    return [$msg, $inboundMsg];
                });

                [$outbound, $inboundMsg] = $outboundPair;
                $this->safeBroadcast(new MessageReceived($outbound));
                if ($inboundMsg) {
                    $this->safeBroadcast(new MessageReceived($inboundMsg));
                }

                $results[] = [
                    'destination' => $dest,
                    'status' => 'sent',
                    'message_id' => $outbound->id,
                    'reason' => null,
                ];
                $sentCount++;
                $createdMessages[] = $outbound;
            } catch (\Throwable $e) {
                $results[] = [
                    'destination' => $dest,
                    'status' => 'failed',
                    'message_id' => null,
                    'reason' => 'خطای سیستمی در ثبت پیام: ' . $e->getMessage(),
                ];
                $failedCount++;
            }
        }

        // 8. Record bulk audit log
        \App\Models\AuditLog::create([
            'user_id' => $effectiveActor?->id,
            'action' => 'api.messages.bulk_sent',
            'entity_type' => Message::class,
            'entity_id' => $createdMessages[0]->id ?? null,
            'payload' => [
                'sender' => $fromPhone->number,
                'total' => count($destinations),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'client_message_id' => $clientMessageId,
                'token_id' => $token?->id,
            ],
            'ip_address' => request()->ip() ?? '127.0.0.1',
        ]);

        return [
            'mode' => 'bulk',
            'sender' => $fromPhone->number,
            'is_duplicate' => false,
            'total' => count($destinations),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'results' => $results,
            'message' => $createdMessages[0] ?? null,
        ];
    }
    /**
     * Records an inbound or outbound message received via API or external source.
     */
    public function recordInboundMessage(array $data, ?User $actor = null, ?ApiToken $token = null): array
    {
        $rawDestination = (string) ($data['destination_number'] ?? '');
        $rawSender = (string) ($data['sender'] ?? ($data['from_number'] ?? ''));
        $requestedDirection = $data['direction'] ?? null;
        $effectiveActor = $actor ?? $token?->user;

        // Resolve clean numbers
        $cleanSender = PhoneNumberNormalizer::resolveToStoredNumber($rawSender);
        $senderPhone = ! empty($cleanSender) ? PhoneNumber::where('number', $cleanSender)->first() : null;

        $cleanDest = PhoneNumberNormalizer::resolveToStoredNumber($rawDestination);
        $destPhone = ! empty($cleanDest) ? PhoneNumber::where('number', $cleanDest)->first() : null;

        // Determine direction:
        if ($requestedDirection === 'outbound' || $requestedDirection === MessageDirection::Outbound->value) {
            $isOutbound = true;
        } elseif ($requestedDirection === 'inbound' || $requestedDirection === MessageDirection::Inbound->value) {
            $isOutbound = false;
        } else {
            // Auto-detect direction intelligently:
            if (! empty($data['from']) || ! empty($data['from_number']) || ! empty($data['from_number_id'])) {
                $isOutbound = true;
            } elseif ($senderPhone) {
                // If sender matches an internal allocated/system line, caller is attempting an outbound send
                $isOutbound = true;
            } elseif ($destPhone && ! $senderPhone) {
                $isOutbound = false;
            } else {
                $isOutbound = false;
            }
        }

        if ($isOutbound) {
            // Outbound message: The allocated line in our platform is the sender
            $phoneNumber = $senderPhone;

            // If not found by sender, check if destination was passed as the allocated line
            if (! $phoneNumber && $destPhone) {
                $phoneNumber = $destPhone;
                $peerNumber = $cleanSender ?: trim($rawSender);
            } else {
                $peerNumber = $cleanDest ?: trim($rawDestination);
            }

            if (! $phoneNumber) {
                throw ValidationException::withMessages([
                    'sender' => 'خط فرستنده در سامانه یافت نشد.',
                ]);
            }

            if ($phoneNumber->status !== PhoneNumberStatus::Allocated) {
                throw ValidationException::withMessages([
                    'sender' => "خط فرستنده در وضعیت «{$phoneNumber->status->label()}» بوده و آماده ارسال پیام نمی‌باشد.",
                ]);
            }

            // Note: accepts_incoming_sms is NOT checked for outbound messages (sending is allowed)
        } else {
            // Inbound message: Strict validation of destination number with full active prefix and valid length
            $numberValidation = PhoneNumberNormalizer::validateFullDestinationNumber($rawDestination);

            if (! $numberValidation['valid']) {
                throw ValidationException::withMessages([
                    'destination_number' => $numberValidation['error'],
                ]);
            }

            $cleanNumber = $numberValidation['clean_number'];

            /** @var PhoneNumber|null $phoneNumber */
            $phoneNumber = PhoneNumber::where('number', $cleanNumber)->first();

            if (! $phoneNumber) {
                throw ValidationException::withMessages([
                    'destination_number' => 'شماره مقصد در سامانه یافت نشد.',
                ]);
            }

            if ($phoneNumber->status !== PhoneNumberStatus::Allocated) {
                throw ValidationException::withMessages([
                    'destination_number' => "شماره مقصد در وضعیت «{$phoneNumber->status->label()}» بوده و آماده دریافت پیام نمی‌باشد.",
                ]);
            }

            if (! $phoneNumber->accepts_incoming_sms) {
                throw ValidationException::withMessages([
                    'destination_number' => 'این سرشماره برای عدم دریافت پیامک ورودی (صرفاً یک‌طرفه/اطلاع‌رسانی) پیکربندی شده است.',
                ]);
            }

            $peerNumber = $cleanSender ?: trim($rawSender);
        }

        // 2. Authorization check
        $effectiveActor = $actor ?? $token?->user;

        if ($phoneNumber->type === PhoneNumberType::PlatformService) {
            if (! $effectiveActor || ! $effectiveActor->isAdmin()) {
                throw new AccessDeniedHttpException('دسترسی غیرمجاز. ثبت پیام برای سرشماره‌های اختصاصی سامانه منحصراً در اختیار راهبر سامانه است.');
            }
        } elseif ($effectiveActor && ! $effectiveActor->isAdmin()) {
            if ($phoneNumber->user_id !== $effectiveActor->id) {
                throw new AccessDeniedHttpException('دسترسی غیرمجاز. ثبت پیام منحصراً برای خطوط فعال اختصاص‌یافته به حساب خودتان مجاز است.');
            }
        }

        // 3. Idempotency verification if keys provided
        $sourceSystemId = ! empty($data['source_system_id']) ? trim((string) $data['source_system_id']) : null;
        $clientMessageId = ! empty($data['client_message_id']) ? trim((string) $data['client_message_id']) : null;

        // If client_message_id is provided without source_system_id, bind to token or actor to enforce database unique constraint
        if ($clientMessageId !== null && $sourceSystemId === null) {
            $sourceSystemId = $token ? ('token:' . $token->id) : ('actor:' . ($effectiveActor?->id ?? 'system'));
        }

        if ($sourceSystemId !== null && $clientMessageId !== null) {
            $existing = Message::with('phoneNumber')
                ->where('source_system_id', $sourceSystemId)
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existing) {
                return [
                    'message' => $existing,
                    'is_duplicate' => true,
                ];
            }
        }

        // 4. Atomic message persistence with concurrent duplicate safety
        $result = DB::transaction(function () use ($data, $phoneNumber, $sourceSystemId, $clientMessageId, $isOutbound, $peerNumber) {
            // Re-check idempotency under lock if keys are present
            if ($sourceSystemId !== null && $clientMessageId !== null) {
                $existing = Message::where('source_system_id', $sourceSystemId)
                    ->where('client_message_id', $clientMessageId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return [
                        'message' => $existing->load('phoneNumber'),
                        'is_duplicate' => true,
                    ];
                }
            }

            $sourceTime = null;
            if (! empty($data['source_time'])) {
                try {
                    $sourceTime = Carbon::parse($data['source_time']);
                } catch (\Throwable) {
                    $sourceTime = null;
                }
            }

            try {
                $message = Message::create([
                    'phone_number_id' => $phoneNumber->id,
                    'destination_number' => $isOutbound ? $peerNumber : $phoneNumber->number,
                    'sender' => $isOutbound ? $phoneNumber->number : trim((string) ($data['sender'] ?? '')),
                    'peer_number' => $peerNumber,
                    'body' => trim((string) $data['body']),
                    'source_time' => $sourceTime,
                    'server_received_at' => now(),
                    'source_system_id' => $sourceSystemId,
                    'client_message_id' => $clientMessageId,
                    'processing_status' => MessageProcessingStatus::Received,
                    'direction' => $isOutbound ? MessageDirection::Outbound : MessageDirection::Inbound,
                ]);

                $inboundCopy = null;
                // If outbound and recipient is an internal line that accepts incoming SMS, deliver inbound copy
                if ($isOutbound) {
                    $destPhone = PhoneNumber::where('number', $peerNumber)->first();
                    if ($destPhone && $destPhone->status === PhoneNumberStatus::Allocated && $destPhone->accepts_incoming_sms) {
                        $inboundCopy = Message::create([
                            'phone_number_id' => $destPhone->id,
                            'destination_number' => $destPhone->number,
                            'sender' => $phoneNumber->number,
                            'peer_number' => $phoneNumber->number,
                            'body' => trim((string) $data['body']),
                            'source_time' => $sourceTime,
                            'server_received_at' => now(),
                            'source_system_id' => $sourceSystemId ? ($sourceSystemId . ':in') : null,
                            'client_message_id' => $clientMessageId ? ($clientMessageId . ':in') : null,
                            'processing_status' => MessageProcessingStatus::Received,
                            'direction' => MessageDirection::Inbound,
                        ]);
                    }
                }

                return [
                    'message' => $message->load('phoneNumber'),
                    'inbound_copy' => $inboundCopy ? $inboundCopy->load('phoneNumber') : null,
                    'is_duplicate' => false,
                ];
            } catch (\Illuminate\Database\UniqueConstraintViolationException|\Illuminate\Database\QueryException $e) {
                // In case of concurrent race condition on unique (source_system_id, client_message_id)
                if ($sourceSystemId !== null && $clientMessageId !== null) {
                    $existing = Message::where('source_system_id', $sourceSystemId)
                        ->where('client_message_id', $clientMessageId)
                        ->first();

                    if ($existing) {
                        return [
                            'message' => $existing->load('phoneNumber'),
                            'is_duplicate' => true,
                        ];
                    }
                }

                throw $e;
            }
        });

        if (isset($result['is_duplicate']) && $result['is_duplicate'] === false) {
            if (! empty($result['message'])) {
                $this->safeBroadcast(new MessageReceived($result['message']));
            }
            if (! empty($result['inbound_copy'])) {
                $this->safeBroadcast(new MessageReceived($result['inbound_copy']));
            }
        }

        return $result;
    }

    /**
     * Retrieve paginated messages for a user with optional filtering.
     */
    public function getUserMessages(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Message::with('phoneNumber')->forUser($user);

        if (! empty($filters['phone_number_id'])) {
            $query->where('phone_number_id', $filters['phone_number_id']);
        }

        if (! empty($filters['destination_number'])) {
            $query->where('destination_number', $filters['destination_number']);
        }

        if (! empty($filters['sender'])) {
            $query->where('sender', 'like', "%{$filters['sender']}%");
        }

        if (! empty($filters['status'])) {
            $query->where('processing_status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('body', 'like', "%{$search}%")
                    ->orWhere('sender', 'like', "%{$search}%")
                    ->orWhere('destination_number', 'like', "%{$search}%")
                    ->orWhere('client_message_id', 'like', "%{$search}%");
            });
        }

        return $query->latestReceived()->paginate($perPage)->withQueryString();
    }

    /**
     * Poll new messages arrived after a specific message ID for a user.
     */
    public function pollUserMessages(User $user, int $afterMessageId, ?int $phoneNumberId = null, int $limit = 30): Collection
    {
        $query = Message::with('phoneNumber')
            ->forUser($user)
            ->where('id', '>', $afterMessageId);

        if ($phoneNumberId) {
            $query->where('phone_number_id', $phoneNumberId);
        }

        return $query->oldest('id')->limit($limit)->get();
    }

    /**
     * Retrieve paginated messages for administration overview.
     */
    public function getAllMessages(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Message::with(['phoneNumber.user']);

        if (! empty($filters['user_id'])) {
            $query->whereHas('phoneNumber', fn ($q) => $q->where('user_id', $filters['user_id']));
        }

        if (! empty($filters['phone_number_id'])) {
            $query->where('phone_number_id', $filters['phone_number_id']);
        }

        if (! empty($filters['destination_number'])) {
            $query->where('destination_number', 'like', "%{$filters['destination_number']}%");
        }

        if (! empty($filters['sender'])) {
            $query->where('sender', 'like', "%{$filters['sender']}%");
        }

        if (! empty($filters['status'])) {
            $query->where('processing_status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('body', 'like', "%{$search}%")
                    ->orWhere('sender', 'like', "%{$search}%")
                    ->orWhere('destination_number', 'like', "%{$search}%")
                    ->orWhere('client_message_id', 'like', "%{$search}%")
                    ->orWhereHas('phoneNumber.user', function ($uq) use ($search) {
                        $uq->where('username', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->latestReceived()->paginate($perPage)->withQueryString();
    }

    /**
     * Poll new messages for administration.
     */
    public function pollAllMessages(int $afterMessageId, array $filters = [], int $limit = 30): Collection
    {
        $query = Message::with(['phoneNumber.user'])
            ->where('id', '>', $afterMessageId);

        if (! empty($filters['user_id'])) {
            $query->whereHas('phoneNumber', fn ($q) => $q->where('user_id', $filters['user_id']));
        }

        if (! empty($filters['destination_number'])) {
            $query->where('destination_number', 'like', "%{$filters['destination_number']}%");
        }

        return $query->oldest('id')->limit($limit)->get();
    }

    /**
     * Send SMS from an allocated phone number to one or more recipient numbers.
     * Supports single SMS, bulk SMS, and enforces non-9-digit service line rules.
     */
    public function sendSms(PhoneNumber $fromNumber, array $destinationNumbers, string $body, User $senderUser): array
    {
        if (! $senderUser->isAdmin() && $fromNumber->user_id !== $senderUser->id) {
            throw new AccessDeniedHttpException('شما مالک این خط نبوده و مجاز به ارسال پیامک از طریق آن نمی‌باشید.');
        }

        if ($fromNumber->type === PhoneNumberType::PlatformService && ! $senderUser->isAdmin()) {
            throw new AccessDeniedHttpException('ارسال پیامک از سرشماره اختصاصی سامانه منحصراً در اختیار راهبر سیستم است.');
        }

        if ($fromNumber->status !== PhoneNumberStatus::Allocated) {
            throw ValidationException::withMessages([
                'from_number_id' => 'خط فرستنده انتخابی در وضعیت فعال و تخصیص‌یافته نمی‌باشد.',
            ]);
        }

        $body = trim($body);
        if (empty($body)) {
            throw ValidationException::withMessages([
                'body' => 'متن پیامک نمی‌تواند خالی باشد.',
            ]);
        }

        // Clean & unique destinations
        $destinations = collect($destinationNumbers)
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => ! empty($d))
            ->unique()
            ->values();

        if ($destinations->isEmpty()) {
            throw ValidationException::withMessages([
                'destinations' => 'حداقل یک شماره مقصد معتبر الزامی است.',
            ]);
        }

        // 1. Content Security & Anti-Phishing Inspection (Phase 5.5)
        app(\App\Services\Security\ContentSecurityFilterService::class)->validateContent($body, $senderUser, $fromNumber->id);

        // 2. Tiered Rate Limiting Enforcement (Phase 5.2 & 5.3)
        app(\App\Services\Security\TieredRateLimiterService::class)->enforceLimits($senderUser, $destinations->count());

        // 3. Suspicious Behavior Evaluation & Risk Scoring (Phase 5.4)
        app(\App\Services\Security\SuspiciousBehaviorEngine::class)->evaluateSend($senderUser, $destinations->all(), $body);

        $results = [];
        $sentCount = 0;

        foreach ($destinations as $rawDest) {
            $cleanDest = PhoneNumberNormalizer::resolveToStoredNumber((string) $rawDest);

            /** @var PhoneNumber|null $destPhone */
            $destPhone = PhoneNumber::where('number', $cleanDest)->first();

            if (! $destPhone) {
                $results[] = [
                    'destination' => $rawDest,
                    'status' => 'failed',
                    'reason' => 'شماره مقصد در شبکه و سامانه یافت نشد.',
                ];
                continue;
            }

            // Check non-9-digit rules & line permissions
            $permission = $destPhone->canReceiveMessagesFrom($fromNumber, $senderUser);
            if (! $permission['allowed']) {
                $results[] = [
                    'destination' => $rawDest,
                    'status' => 'failed',
                    'reason' => $permission['reason'],
                ];
                continue;
            }

            // Deliver atomically: Outbound for sender line, Inbound for recipient line
            $messagePair = DB::transaction(function () use ($fromNumber, $destPhone, $body) {
                $outbound = Message::create([
                    'phone_number_id' => $fromNumber->id,
                    'destination_number' => $destPhone->number,
                    'sender' => $fromNumber->number,
                    'peer_number' => $destPhone->number,
                    'body' => $body,
                    'server_received_at' => now(),
                    'processing_status' => MessageProcessingStatus::Received,
                    'direction' => \App\Enums\MessageDirection::Outbound,
                ]);

                $inbound = Message::create([
                    'phone_number_id' => $destPhone->id,
                    'destination_number' => $destPhone->number,
                    'sender' => $fromNumber->number,
                    'peer_number' => $fromNumber->number,
                    'body' => $body,
                    'server_received_at' => now(),
                    'processing_status' => MessageProcessingStatus::Received,
                    'direction' => \App\Enums\MessageDirection::Inbound,
                ]);

                return [$outbound, $inbound];
            });

            $this->safeBroadcast(new MessageReceived($messagePair[0]));
            $this->safeBroadcast(new MessageReceived($messagePair[1]));

            $results[] = [
                'destination' => $rawDest,
                'status' => 'success',
                'message_id' => $messagePair[0]->id,
            ];
            $sentCount++;
        }

        return [
            'sent_count' => $sentCount,
            'failed_count' => count($results) - $sentCount,
            'results' => $results,
        ];
    }

    /**
     * Queue bulk SMS dispatch to background workers for scalable, non-blocking execution.
     */
    public function queueBulkSms(PhoneNumber $fromNumber, array $destinationNumbers, string $body, User $senderUser): \App\Models\MessageBatch
    {
        if (! $senderUser->isAdmin() && $fromNumber->user_id !== $senderUser->id) {
            throw new AccessDeniedHttpException('شما مالک این خط نبوده و مجاز به ارسال پیامک از طریق آن نمی‌باشید.');
        }

        if ($fromNumber->type === PhoneNumberType::PlatformService && ! $senderUser->isAdmin()) {
            throw new AccessDeniedHttpException('ارسال پیامک از سرشماره اختصاصی سامانه منحصراً در اختیار راهبر سیستم است.');
        }

        if ($fromNumber->status !== PhoneNumberStatus::Allocated) {
            throw ValidationException::withMessages([
                'from_number_id' => 'خط فرستنده انتخابی در وضعیت فعال و تخصیص‌یافته نمی‌باشد.',
            ]);
        }

        $body = trim($body);
        if (empty($body)) {
            throw ValidationException::withMessages([
                'body' => 'متن پیامک نمی‌تواند خالی باشد.',
            ]);
        }

        $destinations = collect($destinationNumbers)
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => ! empty($d))
            ->unique()
            ->values();

        if ($destinations->isEmpty()) {
            throw ValidationException::withMessages([
                'destinations' => 'حداقل یک شماره مقصد معتبر الزامی است.',
            ]);
        }

        // 1. Content Security & Anti-Phishing Inspection (Phase 5.5)
        app(\App\Services\Security\ContentSecurityFilterService::class)->validateContent($body, $senderUser, $fromNumber->id);

        // 2. Tiered Rate Limiting Enforcement (Phase 5.2 & 5.3)
        app(\App\Services\Security\TieredRateLimiterService::class)->enforceLimits($senderUser, $destinations->count());

        // 3. Suspicious Behavior Evaluation & Risk Scoring (Phase 5.4)
        app(\App\Services\Security\SuspiciousBehaviorEngine::class)->evaluateSend($senderUser, $destinations->all(), $body);

        $batchId = 'BATCH-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(10));

        return DB::transaction(function () use ($batchId, $senderUser, $fromNumber, $body, $destinations) {
            $batch = \App\Models\MessageBatch::create([
                'batch_id' => $batchId,
                'user_id' => $senderUser->id,
                'phone_number_id' => $fromNumber->id,
                'sender' => $fromNumber->number,
                'body' => $body,
                'total_count' => $destinations->count(),
                'status' => \App\Models\MessageBatch::STATUS_PENDING,
            ]);

            $itemsData = $destinations->map(fn ($dest) => [
                'message_batch_id' => $batch->id,
                'destination' => (string) $dest,
                'status' => \App\Models\MessageBatchItem::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            \App\Models\MessageBatchItem::insert($itemsData);

            \App\Jobs\ProcessMessageBatchJob::dispatch($batch->id);

            return $batch;
        });
    }

    public function getBatchStatus(string $batchId, ?User $user = null): \App\Models\MessageBatch
    {
        $query = \App\Models\MessageBatch::where('batch_id', $batchId);
        if ($user && ! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return $query->with('items')->firstOrFail();
    }

    public function resyncMessages(int $phoneNumberId, ?int $afterId = null, ?string $peerNumber = null, int $limit = 50): array
    {
        $query = Message::where('phone_number_id', $phoneNumberId);

        if ($afterId) {
            $query->where('id', '>', $afterId);
        }

        if (! empty($peerNumber)) {
            $cleanPeer = PhoneNumberNormalizer::resolveToStoredNumber($peerNumber);
            $query->where(function ($q) use ($cleanPeer, $peerNumber) {
                $q->where('peer_number', $cleanPeer)
                    ->orWhere('peer_number', $peerNumber);
            });
        }

        $newMessages = $query->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        $latestId = $newMessages->isNotEmpty()
            ? $newMessages->last()->id
            : ($afterId ?? (Message::where('phone_number_id', $phoneNumberId)->max('id') ?? 0));

        $phone = PhoneNumber::find($phoneNumberId);
        $user = $phone?->user;

        $unreadService = app()->bound(\App\Contracts\UnreadCountServiceInterface::class)
            ? app(\App\Contracts\UnreadCountServiceInterface::class)
            : null;

        $unreadUser = ($user && $unreadService) ? $unreadService->getUserUnreadCount($user->id) : 0;
        $unreadLine = $unreadService ? $unreadService->getLineUnreadCount($phoneNumberId) : 0;

        return [
            'messages' => $newMessages->map(fn (Message $m) => [
                'id' => $m->id,
                'phone_number_id' => $m->phone_number_id,
                'destination_number' => $m->destination_number,
                'sender' => $m->sender,
                'peer_number' => $m->peer_number,
                'direction' => $m->direction->value,
                'body' => $m->body,
                'server_received_at' => $m->server_received_at?->toIso8601String() ?? now()->toIso8601String(),
                'server_received_at_formatted' => $m->server_received_at?->diffForHumans() ?? 'هم‌اکنون',
                'processing_status' => $m->processing_status->value,
            ]),
            'count' => $newMessages->count(),
            'latest_id' => $latestId,
            'unread_user' => $unreadUser,
            'unread_line' => $unreadLine,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Retrieve conversation threads for a specific allocated line (Mobile SMS style).
     * Cached with real-time invalidation & instant refresh on new messages.
     */
    public function getConversations(PhoneNumber $phoneNumber, ?string $search = null): Collection
    {
        return app(\App\Contracts\SystemCacheServiceInterface::class)->getLineConversations($phoneNumber, $search);
    }

    /**
     * Retrieve all messages in a specific conversation thread between this line and a peer.
     */
    public function getConversationMessages(
        PhoneNumber $phoneNumber,
        string $peerNumber,
        ?int $afterId = null,
        ?int $beforeId = null,
        int $limit = 50
    ): Collection {
        $cleanPeer = PhoneNumberNormalizer::resolveToStoredNumber($peerNumber);

        $query = Message::where('phone_number_id', $phoneNumber->id)
            ->where(function ($q) use ($cleanPeer, $peerNumber) {
                $q->where('peer_number', $cleanPeer)
                    ->orWhere('peer_number', $peerNumber);
            });

        // When a user explicitly opens the conversation, mark inbound messages as processed (read)
        $inboundToUpdate = Message::where('phone_number_id', $phoneNumber->id)
            ->where(function ($q) use ($cleanPeer, $peerNumber) {
                $q->where('peer_number', $cleanPeer)
                    ->orWhere('peer_number', $peerNumber);
            })
            ->where('direction', \App\Enums\MessageDirection::Inbound)
            ->where('processing_status', MessageProcessingStatus::Received)
            ->get();

        if ($inboundToUpdate->isNotEmpty()) {
            Message::whereIn('id', $inboundToUpdate->pluck('id'))
                ->update(['processing_status' => MessageProcessingStatus::Processed]);

            foreach ($inboundToUpdate as $upMsg) {
                $upMsg->processing_status = MessageProcessingStatus::Processed;
                $this->safeBroadcast(new MessageStatusUpdated($upMsg));
            }
        }

        // Also if peer is an internal allocated line, mark that line's outbound messages to this line as processed (read)
        $peerPhone = PhoneNumber::where('number', $cleanPeer)->first();
        if ($peerPhone) {
            $peerOutbounds = Message::where('phone_number_id', $peerPhone->id)
                ->where('peer_number', $phoneNumber->number)
                ->where('direction', \App\Enums\MessageDirection::Outbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->get();

            if ($peerOutbounds->isNotEmpty()) {
                Message::whereIn('id', $peerOutbounds->pluck('id'))
                    ->update(['processing_status' => MessageProcessingStatus::Processed]);

                foreach ($peerOutbounds as $upMsg) {
                    $upMsg->processing_status = MessageProcessingStatus::Processed;
                    $this->safeBroadcast(new MessageStatusUpdated($upMsg));
                }
            }
        }

        if ($afterId) {
            return $query->where('id', '>', $afterId)
                ->oldest('server_received_at')
                ->oldest('id')
                ->limit(100)
                ->get();
        }

        if ($beforeId) {
            return $query->where('id', '<', $beforeId)
                ->latest('server_received_at')
                ->latest('id')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();
        }

        return $query->latest('server_received_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Retrieve all conversation threads for administration oversight (system-wide dialogues).
     */
    public function getAdminConversations(array $filters = []): Collection
    {
        $query = Message::query();

        if (! empty($filters['phone_number_id'])) {
            $query->where('phone_number_id', $filters['phone_number_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->whereHas('phoneNumber', fn ($q) => $q->where('user_id', $filters['user_id']));
        }

        $pairs = $query->selectRaw('phone_number_id, peer_number, MAX(id) as max_id, COUNT(*) as messages_count')
            ->whereNotNull('peer_number')
            ->where('peer_number', '!=', '')
            ->groupBy('phone_number_id', 'peer_number')
            ->orderByDesc('max_id')
            ->limit(150)
            ->get();

        $conversations = collect();
        $seenPairs = [];
        $search = ! empty($filters['search']) ? trim($filters['search']) : null;

        $phoneIds = $pairs->pluck('phone_number_id')->unique()->filter()->values();
        $peerNumbers = $pairs->pluck('peer_number')->unique()->filter()->values();

        $loadedPhoneNumbers = PhoneNumber::with('user')
            ->whereIn('id', $phoneIds)
            ->orWhereIn('number', $peerNumbers)
            ->get()
            ->keyBy(fn ($p) => (string) $p->number);

        $loadedById = PhoneNumber::with('user')
            ->whereIn('id', $phoneIds)
            ->get()
            ->keyBy('id');

        $latestMessageIds = $pairs->pluck('max_id')->unique()->filter()->values();
        $loadedMessages = Message::whereIn('id', $latestMessageIds)->get()->keyBy('id');

        foreach ($pairs as $pair) {
            $currentLineA = $loadedById->get($pair->phone_number_id);
            if (! $currentLineA) {
                continue;
            }

            $peerStr = (string) $pair->peer_number;
            $currentLineB = $loadedPhoneNumbers->get($peerStr);

            // Deduplicate two-way internal conversations if not filtering by specific phone_number_id
            if (empty($filters['phone_number_id']) && $currentLineB) {
                $pairKey = min($currentLineA->number, $currentLineB->number) . '::' . max($currentLineA->number, $currentLineB->number);
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;

                // Canonicalize so line_a is the smaller number
                if ($currentLineA->number > $currentLineB->number) {
                    $lineA = $currentLineB;
                    $lineB = $currentLineA;
                    $peerStr = (string) $lineB->number;
                } else {
                    $lineA = $currentLineA;
                    $lineB = $currentLineB;
                }
            } else {
                $lineA = $currentLineA;
                $lineB = $currentLineB;
            }

            $latestMessage = $loadedMessages->get($pair->max_id);
            if (! $latestMessage) {
                continue;
            }

            $lineAName = $lineA->user ? $lineA->user->name : 'سیستم';
            $lineAUsername = $lineA->user ? $lineA->user->username : 'system';
            $lineATitle = $lineA->effective_title;

            $lineBName = $lineB ? ($lineB->user ? $lineB->user->name : 'سیستم') : 'شماره خارجی';
            $lineBUsername = $lineB?->user ? $lineB->user->username : null;
            $lineBTitle = $lineB ? $lineB->effective_title : (PhonePrefix::getDefaultPrefixString() . " {$peerStr}");

            $senderIsLineA = ($latestMessage->sender === $lineA->number);
            $senderLabel = $senderIsLineA ? $lineAName : $lineBName;
            $receiverLabel = $senderIsLineA ? $lineBName : $lineAName;

            // Search filter
            if ($search) {
                $matchesSearch = str_contains($lineA->number, $search)
                    || str_contains($lineAName, $search)
                    || str_contains($lineAUsername, $search)
                    || str_contains($lineATitle, $search)
                    || str_contains($peerStr, $search)
                    || str_contains($lineBName, $search)
                    || ($lineBUsername && str_contains($lineBUsername, $search))
                    || str_contains($lineBTitle, $search)
                    || str_contains($latestMessage->body, $search);

                if (! $matchesSearch) {
                    $matchesSearch = Message::where('phone_number_id', $lineA->id)
                        ->where('peer_number', $peerStr)
                        ->where('body', 'like', "%{$search}%")
                        ->exists();
                }

                if (! $matchesSearch) {
                    continue;
                }
            }

            $unreadCount = Message::where('phone_number_id', $lineA->id)
                ->where('peer_number', $peerStr)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->count();

            $failedCount = Message::where('phone_number_id', $lineA->id)
                ->where('peer_number', $peerStr)
                ->where('processing_status', MessageProcessingStatus::Failed)
                ->count();

            $conversations->push([
                'id' => "{$lineA->id}_{$peerStr}",
                'phone_number_id' => $lineA->id,
                'peer_number' => $peerStr,
                'line_a' => [
                    'id' => $lineA->id,
                    'number' => $lineA->number,
                    'formatted' => $lineA->formatted_number,
                    'display_title' => $lineA->display_title,
                    'effective_title' => $lineA->effective_title,
                    'type' => $lineA->type->value,
                    'type_label' => $lineA->type->label(),
                    'type_badge' => $lineA->type->badgeClasses(),
                    'user' => $lineA->user ? [
                        'id' => $lineA->user->id,
                        'name' => $lineA->user->name,
                        'username' => $lineA->user->username,
                    ] : null,
                ],
                'line_b' => [
                    'id' => $lineB?->id,
                    'number' => $peerStr,
                    'formatted' => $lineB ? $lineB->formatted_number : (PhonePrefix::getDefaultPrefixString() . " {$peerStr}"),
                    'display_title' => $lineB?->display_title,
                    'effective_title' => $lineB ? $lineB->effective_title : (PhonePrefix::getDefaultPrefixString() . " {$peerStr}"),
                    'is_internal' => (bool) $lineB,
                    'type' => $lineB?->type->value,
                    'type_label' => $lineB?->type->label(),
                    'type_badge' => $lineB?->type->badgeClasses(),
                    'user' => $lineB?->user ? [
                        'id' => $lineB->user->id,
                        'name' => $lineB->user->name,
                        'username' => $lineB->user->username,
                    ] : null,
                ],
                'latest_message' => [
                    'id' => $latestMessage->id,
                    'body' => $latestMessage->body,
                    'sender_number' => $latestMessage->sender,
                    'sender_name' => $senderLabel,
                    'receiver_name' => $receiverLabel,
                    'sender_is_line_a' => $senderIsLineA,
                    'destination_number' => $latestMessage->destination_number,
                    'direction' => $latestMessage->direction->value,
                    'direction_label' => $latestMessage->direction->label(),
                    'processing_status' => $latestMessage->processing_status->value,
                    'status_label' => $latestMessage->processing_status->label(),
                    'status_badge' => $latestMessage->processing_status->badgeClass(),
                    'server_received_at' => $latestMessage->server_received_at->toIso8601String(),
                    'server_received_at_formatted' => $latestMessage->server_received_at->diffForHumans(),
                ],
                'messages_count' => (int) $pair->messages_count,
                'unread_count' => $unreadCount,
                'failed_count' => $failedCount,
            ]);
        }

        return $conversations->values();
    }

    /**
     * Retrieve messages for a specific conversation in administration.
     */
    public function getAdminConversationMessages(int $phoneNumberId, string $peerNumber, ?int $afterId = null): Collection
    {
        $lineA = PhoneNumber::with('user')->find($phoneNumberId);
        if (! $lineA) {
            return collect();
        }

        $cleanPeer = PhoneNumberNormalizer::resolveToStoredNumber($peerNumber);
        $lineB = PhoneNumber::with('user')->where('number', $cleanPeer)->first();

        $query = Message::with(['phoneNumber.user'])
            ->where('phone_number_id', $phoneNumberId)
            ->where(function ($q) use ($cleanPeer, $peerNumber) {
                $q->where('peer_number', $cleanPeer)
                    ->orWhere('peer_number', $peerNumber);
            });

        if ($afterId) {
            $query->where('id', '>', $afterId)->oldest('server_received_at')->oldest('id')->limit(100);
            $messages = $query->get();
        } else {
            $messages = $query->latest('server_received_at')->latest('id')->limit(200)->get()->reverse()->values();
        }

        return $messages->map(function (Message $m) use ($lineA, $lineB, $cleanPeer) {
            $isOutbound = ($m->direction === MessageDirection::Outbound || $m->direction->value === 'outbound');

            $defaultPrefix = PhonePrefix::getDefaultPrefixString();
            $senderNumber = $isOutbound ? $lineA->number : $cleanPeer;
            $senderFormatted = $isOutbound ? $lineA->formatted_number : ($lineB ? $lineB->formatted_number : "{$defaultPrefix} {$cleanPeer}");
            $senderName = $isOutbound
                ? ($lineA->user ? $lineA->user->name : 'سیستم')
                : ($lineB ? ($lineB->user ? $lineB->user->name : 'سیستم') : 'فرستنده خارجی');
            $senderUsername = $isOutbound
                ? ($lineA->user ? $lineA->user->username : null)
                : ($lineB?->user ? $lineB->user->username : null);

            $receiverNumber = $isOutbound ? $cleanPeer : $lineA->number;
            $receiverFormatted = $isOutbound ? ($lineB ? $lineB->formatted_number : "{$defaultPrefix} {$cleanPeer}") : $lineA->formatted_number;
            $receiverName = $isOutbound
                ? ($lineB ? ($lineB->user ? $lineB->user->name : 'سیستم') : 'شماره خارجی')
                : ($lineA->user ? $lineA->user->name : 'سیستم');

            return [
                'id' => $m->id,
                'phone_number_id' => $m->phone_number_id,
                'body' => $m->body,
                'direction' => $m->direction->value,
                'direction_label' => $m->direction->label(),
                'is_line_a' => $isOutbound,
                'sender' => [
                    'number' => $senderNumber,
                    'formatted' => $senderFormatted,
                    'name' => $senderName,
                    'username' => $senderUsername,
                ],
                'receiver' => [
                    'number' => $receiverNumber,
                    'formatted' => $receiverFormatted,
                    'name' => $receiverName,
                ],
                'processing_status' => $m->processing_status->value,
                'status_label' => $m->processing_status->label(),
                'status_badge' => $m->processing_status->badgeClass(),
                'client_message_id' => $m->client_message_id,
                'source_system_id' => $m->source_system_id,
                'server_received_at' => $m->server_received_at->toIso8601String(),
                'server_received_at_formatted' => $m->server_received_at->diffForHumans(),
                'server_received_time' => $m->server_received_at->format('H:i'),
            ];
        });
    }

    /**
     * Delete a message (Admin action).
     */
    public function deleteMessage(Message $message): void
    {
        $message->delete();
    }

    /**
     * Safely broadcast an event without interrupting caller if broadcast driver or websocket server is unreachable.
     */
    protected function safeBroadcast(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::debug('Real-time broadcast skipped: '.$e->getMessage());
        }
    }
}
