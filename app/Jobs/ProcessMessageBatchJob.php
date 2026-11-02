<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\PhoneNumberStatus;
use App\Events\MessageReceived;
use App\Models\Message;
use App\Models\MessageBatch;
use App\Models\MessageBatchItem;
use App\Models\PhoneNumber;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMessageBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    public function __construct(
        public int $messageBatchId,
        public int $chunkSize = 50
    ) {
        $this->onQueue('messages');
    }

    public function handle(): void
    {
        /** @var MessageBatch|null $batch */
        $batch = MessageBatch::find($this->messageBatchId);
        if (! $batch || $batch->isFinished()) {
            return;
        }

        if ($batch->status === MessageBatch::STATUS_PENDING) {
            $batch->update(['status' => MessageBatch::STATUS_PROCESSING]);
        }

        $fromNumber = $batch->phoneNumber;
        $senderUser = $batch->user;
        $body = $batch->body;

        // Fetch pending items for this chunk (Resume mechanism)
        $items = $batch->items()
            ->where('status', MessageBatchItem::STATUS_PENDING)
            ->limit($this->chunkSize)
            ->get();

        if ($items->isEmpty()) {
            $this->finalizeBatch($batch);
            return;
        }

        foreach ($items as $item) {
            // Guard: If already sent, do not re-send
            if ($item->status === MessageBatchItem::STATUS_SENT && $item->message_id) {
                continue;
            }

            $item->increment('attempts');
            $cleanDest = PhoneNumberNormalizer::resolveToStoredNumber((string) $item->destination);

            /** @var PhoneNumber|null $destPhone */
            $destPhone = PhoneNumber::where('number', $cleanDest)->first();

            if (! $destPhone) {
                $item->update([
                    'status' => MessageBatchItem::STATUS_FAILED,
                    'error_reason' => 'شماره مقصد در شبکه و سامانه یافت نشد.',
                    'processed_at' => now(),
                ]);
                continue;
            }

            // Check permissions & rules
            $permission = $destPhone->canReceiveMessagesFrom($fromNumber, $senderUser);
            if (! $permission['allowed']) {
                $item->update([
                    'status' => MessageBatchItem::STATUS_FAILED,
                    'error_reason' => $permission['reason'] ?? 'خط مقصد مجاز به دریافت پیامک نمی‌باشد.',
                    'processed_at' => now(),
                ]);
                continue;
            }

            // Deliver atomically
            try {
                $pair = DB::transaction(function () use ($fromNumber, $destPhone, $body, $item) {
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

                    $inbound = null;
                    if ($destPhone->status === PhoneNumberStatus::Allocated && $destPhone->accepts_incoming_sms) {
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
                    }

                    $item->update([
                        'status' => MessageBatchItem::STATUS_SENT,
                        'message_id' => $outbound->id,
                        'error_reason' => null,
                        'processed_at' => now(),
                    ]);

                    return [$outbound, $inbound];
                });

                // Safe asynchronous broadcast
                try {
                    broadcast(new MessageReceived($pair[0]));
                    if ($pair[1]) {
                        broadcast(new MessageReceived($pair[1]));
                    }
                } catch (\Throwable $e) {
                    Log::debug('Broadcast failed in ProcessMessageBatchJob: ' . $e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::error("Failed to process batch item #{$item->id}: " . $e->getMessage());
                $item->update([
                    'status' => MessageBatchItem::STATUS_FAILED,
                    'error_reason' => 'خطای پردازش دیتابیس: ' . $e->getMessage(),
                    'processed_at' => now(),
                ]);
            }
        }

        // Update batch counts
        $this->updateBatchStatistics($batch);

        // Check if more items are pending
        $remainingCount = $batch->items()->where('status', MessageBatchItem::STATUS_PENDING)->count();
        if ($remainingCount > 0) {
            // Re-dispatch next chunk
            self::dispatch($batch->id, $this->chunkSize);
        } else {
            $this->finalizeBatch($batch);
        }
    }

    protected function updateBatchStatistics(MessageBatch $batch): void
    {
        $success = $batch->items()->where('status', MessageBatchItem::STATUS_SENT)->count();
        $failed = $batch->items()->where('status', MessageBatchItem::STATUS_FAILED)->count();

        $batch->update([
            'processed_count' => $success + $failed,
            'success_count' => $success,
            'failed_count' => $failed,
        ]);
    }

    protected function finalizeBatch(MessageBatch $batch): void
    {
        $this->updateBatchStatistics($batch);
        $batch->refresh();

        $status = MessageBatch::STATUS_COMPLETED;
        if ($batch->failed_count > 0) {
            $status = ($batch->success_count > 0)
                ? MessageBatch::STATUS_PARTIALLY_FAILED
                : MessageBatch::STATUS_FAILED;
        }

        $batch->update([
            'status' => $status,
            'completed_at' => now(),
        ]);
    }
}

