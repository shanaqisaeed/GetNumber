<?php

namespace App\Services\Communication;

use App\Contracts\Communication\SmsServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\WalletTransactionType;
use App\Events\MessageReceived;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SmsService implements SmsServiceInterface
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    public function send(PhoneNumber $fromNumber, array|string $destinations, string $body, User $senderUser): array
    {
        if (! $senderUser->isAdmin() && $fromNumber->user_id !== $senderUser->id) {
            throw new AccessDeniedHttpException(__('services.messages.not_owner'));
        }

        if ($fromNumber->status !== PhoneNumberStatus::Allocated) {
            throw new AccessDeniedHttpException(__('services.messages.sender_not_active'));
        }

        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException(__('services.messages.body_empty'));
        }

        // Parse destinations
        if (is_string($destinations)) {
            $destArray = preg_split('/[\r\n,]+/', $destinations) ?: [];
        } else {
            $destArray = $destinations;
        }

        $destinationsList = collect($destArray)
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => ! empty($d))
            ->unique()
            ->values();

        if ($destinationsList->isEmpty()) {
            throw new \InvalidArgumentException(__('services.messages.at_least_one_dest'));
        }

        // Calculate Cost per recipient
        $sampleDest = $destinationsList->first();
        $costInfo = $this->calculateCost($body, $fromNumber, $destinationsList->count(), $sampleDest);
        $totalRequiredAmount = number_format($costInfo['total_cost'], 4, '.', '');

        // Verify & Deduct Wallet if amount > 0
        $txReference = 'SMS-' . Str::upper(Str::random(12));
        if (bccomp($totalRequiredAmount, '0.0000', 4) > 0) {
            $wallet = $this->walletService->getWallet($senderUser);
            if (bccomp((string) $wallet->balance, $totalRequiredAmount, 4) < 0) {
                throw new InsufficientWalletBalanceException(
                    requiredAmount: $totalRequiredAmount,
                    currentBalance: (string) $wallet->balance,
                    message: "اعتبار کیف‌پول شما جهت ارسال این پیامک (مبلغ {$totalRequiredAmount} کوین) کافی نمی‌باشد."
                );
            }

            $this->walletService->chargeForUsage(
                user: $senderUser,
                amount: $totalRequiredAmount,
                referenceId: $txReference,
                type: WalletTransactionType::ServiceUsage,
                description: "ارسال پیامک از خط {$fromNumber->number} ({$destinationsList->count()} مقصد - {$costInfo['parts_count']} پارت)",
                metadata: [
                    'phone_number_id' => $fromNumber->id,
                    'parts_count' => $costInfo['parts_count'],
                    'recipients_count' => $destinationsList->count(),
                    'cost_info' => $costInfo,
                ]
            );
        }

        $results = [];
        $sentCount = 0;
        $failedCount = 0;

        foreach ($destinationsList as $rawDest) {
            $destIdentifier = trim((string) $rawDest);
            $isCustomIdDest = str_starts_with($destIdentifier, '@');

            $destPhone = null;
            $destAllocation = null;

            if ($isCustomIdDest) {
                $cleanHandle = ltrim($destIdentifier, '@');
                $destAllocation = \App\Models\ServiceResourceAllocation::with('user', 'phoneNumber')
                    ->where('service_key', 'custom_id')
                    ->where('status', 'active')
                    ->where(function ($q) use ($destIdentifier, $cleanHandle) {
                        $q->where('resource_identifier', $destIdentifier)
                            ->orWhere('resource_identifier', '@' . $cleanHandle)
                            ->orWhere('resource_identifier', $cleanHandle);
                    })
                    ->first();

                if (! $destAllocation) {
                    $results[] = [
                        'destination' => $rawDest,
                        'status' => 'failed',
                        'reason' => 'آیدی اختصاصی مقصد یافت نشد یا در وضعیت فعال نمی‌باشد.',
                    ];
                    $failedCount++;
                    continue;
                }

                $destPhone = $destAllocation->phoneNumber
                    ?? PhoneNumber::where('user_id', $destAllocation->user_id)->where('status', PhoneNumberStatus::Allocated)->first();
            } else {
                $cleanDest = PhoneNumberNormalizer::resolveToStoredNumber($destIdentifier);
                $destPhone = PhoneNumber::where('number', $cleanDest)->first();
            }

            if (! $destPhone && ! $destAllocation) {
                $results[] = [
                    'destination' => $rawDest,
                    'status' => 'failed',
                    'reason' => __('services.messages.dest_not_found_network'),
                ];
                $failedCount++;
                continue;
            }

            if ($destPhone) {
                // Check receive permission
                $permission = $destPhone->canReceiveMessagesFrom($fromNumber, $senderUser);
                if (! $permission['allowed']) {
                    $results[] = [
                        'destination' => $rawDest,
                        'status' => 'failed',
                        'reason' => $permission['reason'],
                    ];
                    $failedCount++;
                    continue;
                }
            }

            // Deliver atomically
            $messagePair = DB::transaction(function () use ($fromNumber, $destPhone, $destAllocation, $destIdentifier, $body) {
                $destNum = $destAllocation ? $destAllocation->resource_identifier : $destPhone->number;
                $peerNum = $destAllocation ? $destAllocation->resource_identifier : $destPhone->number;

                $outbound = Message::create([
                    'phone_number_id' => $fromNumber->id,
                    'destination_number' => $destNum,
                    'sender' => $fromNumber->number,
                    'peer_number' => $peerNum,
                    'body' => $body,
                    'server_received_at' => now(),
                    'processing_status' => MessageProcessingStatus::Received,
                    'direction' => MessageDirection::Outbound,
                ]);

                $inbound = null;
                $recipientPhoneId = $destPhone?->id;
                if ($recipientPhoneId) {
                    $inbound = Message::create([
                        'phone_number_id' => $recipientPhoneId,
                        'destination_number' => $destNum,
                        'sender' => $fromNumber->number,
                        'peer_number' => $fromNumber->number,
                        'body' => $body,
                        'server_received_at' => now(),
                        'processing_status' => MessageProcessingStatus::Received,
                        'direction' => MessageDirection::Inbound,
                    ]);
                }

                return [$outbound, $inbound];
            });

            try {
                broadcast(new MessageReceived($messagePair[0]))->toOthers();
                if ($messagePair[1]) {
                    broadcast(new MessageReceived($messagePair[1]))->toOthers();
                }
            } catch (\Throwable) {
            }

            $results[] = [
                'destination' => $rawDest,
                'status' => 'success',
                'message_id' => $messagePair[0]->id,
            ];
            $sentCount++;
        }

        return [
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'total_cost' => (float) $totalRequiredAmount,
            'results' => $results,
        ];
    }

    public function getDeliveryStatus(int $messageId): array
    {
        $message = Message::find($messageId);
        if (! $message) {
            return [
                'message_id' => $messageId,
                'status' => 'not_found',
                'delivered_at' => null,
                'details' => [],
            ];
        }

        return [
            'message_id' => $message->id,
            'status' => $message->processing_status->value,
            'delivered_at' => $message->server_received_at?->toIso8601String(),
            'details' => [
                'sender' => $message->sender,
                'destination' => $message->destination_number,
                'direction' => $message->direction->value,
            ],
        ];
    }

    public function calculateCost(string $body, ?PhoneNumber $fromNumber = null, int $recipientCount = 1, ?string $sampleDestination = null): array
    {
        $recipientCount = max(1, $recipientCount);
        $charLength = mb_strlen($body, 'UTF-8');

        // Check if message has Unicode / Persian characters
        $isUnicode = (bool) preg_match('/[^\x20-\x7E\r\n\t]/', $body);
        $encoding = $isUnicode ? 'UTF-16/Persian' : 'GSM-7';

        if ($isUnicode) {
            $parts = $charLength <= 70 ? 1 : (int) ceil($charLength / 67);
        } else {
            $parts = $charLength <= 160 ? 1 : (int) ceil($charLength / 153);
        }
        $parts = max(1, $parts);

        // Determine tariff per part based on destination and prefix:
        $fromNumber?->loadMissing('prefix');
        $fromPrefix = $fromNumber?->prefix;

        if ($sampleDestination !== null && (str_starts_with($sampleDestination, '@') || ! preg_match('/^\+?[0-9]+$/', $sampleDestination))) {
            // ID message tariff (from system settings, defaults to 0.0000)
            $tariffPerPart = (float) SystemSetting::get('id_sms_rate', '0.0000');
        } else {
            // If sample destination is provided, check if intra or inter
            $isIntra = true;
            if ($sampleDestination !== null && $fromPrefix) {
                $cleanDest = preg_replace('/^\+/', '', trim($sampleDestination));
                $cleanPrefix = preg_replace('/^\+/', '', $fromPrefix->prefix);
                $isIntra = $cleanPrefix !== '' && str_starts_with($cleanDest, $cleanPrefix);
            }

            if ($isIntra) {
                $tariffPerPart = (float) ($fromPrefix?->getIntraSmsRate() ?? SystemSetting::get('sms_intra_prefix_rate', '0.0000'));
            } else {
                $tariffPerPart = (float) ($fromPrefix?->getInterSmsRate() ?? SystemSetting::get('sms_inter_prefix_rate', '0.1200'));
            }
        }

        $totalCost = (float) bcmul((string) ($parts * $recipientCount), (string) $tariffPerPart, 4);

        return [
            'parts_count' => $parts,
            'characters_count' => $charLength,
            'encoding' => $encoding,
            'tariff_per_part' => $tariffPerPart,
            'total_cost' => $totalCost,
            'currency' => 'COIN',
        ];
    }
}

