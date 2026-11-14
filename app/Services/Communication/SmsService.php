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
        $costInfo = $this->calculateCost($body, $fromNumber, $destinationsList->count());
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
            $cleanDest = PhoneNumberNormalizer::resolveToStoredNumber((string) $rawDest);
            /** @var PhoneNumber|null $destPhone */
            $destPhone = PhoneNumber::where('number', $cleanDest)->first();

            if (! $destPhone) {
                $results[] = [
                    'destination' => $rawDest,
                    'status' => 'failed',
                    'reason' => __('services.messages.dest_not_found_network'),
                ];
                $failedCount++;
                continue;
            }

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

            // Deliver atomically
            $messagePair = DB::transaction(function () use ($fromNumber, $destPhone, $body) {
                $outbound = Message::create([
                    'phone_number_id' => $fromNumber->id,
                    'destination_number' => $destPhone->number,
                    'sender' => $fromNumber->number,
                    'peer_number' => $destPhone->number,
                    'body' => $body,
                    'server_received_at' => now(),
                    'processing_status' => MessageProcessingStatus::Received,
                    'direction' => MessageDirection::Outbound,
                ]);

                $inbound = Message::create([
                    'phone_number_id' => $destPhone->id,
                    'destination_number' => $destPhone->number,
                    'sender' => $fromNumber->number,
                    'peer_number' => $fromNumber->number,
                    'body' => $body,
                    'server_received_at' => now(),
                    'processing_status' => MessageProcessingStatus::Received,
                    'direction' => MessageDirection::Inbound,
                ]);

                return [$outbound, $inbound];
            });

            try {
                broadcast(new MessageReceived($messagePair[0]))->toOthers();
                broadcast(new MessageReceived($messagePair[1]))->toOthers();
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

    public function calculateCost(string $body, ?PhoneNumber $fromNumber = null, int $recipientCount = 1): array
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

        // Tariff from prefix or system setting
        $prefixTariff = $fromNumber?->prefix?->sms_tariff;
        $tariffPerPart = $prefixTariff !== null && (float) $prefixTariff > 0
            ? (float) $prefixTariff
            : (float) SystemSetting::get('default_sms_tariff', 0.0500);

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

