<?php

namespace App\Services;

use App\Contracts\MessageArchiveServiceInterface;
use App\Models\ArchivedMessage;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageArchiveService implements MessageArchiveServiceInterface
{
    public function archiveOlderThan(int $days = 90, int $chunkSize = 500, bool $dryRun = false): array
    {
        $cutoffDate = now()->subDays($days);

        $eligibleQuery = Message::where('created_at', '<', $cutoffDate);
        $totalEligible = $eligibleQuery->count();

        if ($dryRun || $totalEligible === 0) {
            return [
                'dry_run' => $dryRun,
                'days_threshold' => $days,
                'cutoff_date' => $cutoffDate->toIso8601String(),
                'eligible_count' => $totalEligible,
                'archived_count' => 0,
            ];
        }

        $archivedCount = 0;

        // Process in controlled atomic chunks to avoid lock contention
        while (true) {
            $messages = Message::where('created_at', '<', $cutoffDate)
                ->limit($chunkSize)
                ->get();

            if ($messages->isEmpty()) {
                break;
            }

            $ids = $messages->pluck('id')->all();
            $records = $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'phone_number_id' => $m->phone_number_id,
                'destination_number' => $m->destination_number,
                'sender' => $m->sender,
                'peer_number' => $m->peer_number,
                'body' => $m->body,
                'direction' => $m->direction->value,
                'processing_status' => $m->processing_status->value,
                'source_time' => $m->source_time,
                'server_received_at' => $m->server_received_at,
                'source_system_id' => $m->source_system_id,
                'client_message_id' => $m->client_message_id,
                'original_created_at' => $m->created_at,
                'original_updated_at' => $m->updated_at,
                'archived_at' => now(),
            ])->all();

            DB::transaction(function () use ($records, $ids) {
                ArchivedMessage::insert($records);
                Message::whereIn('id', $ids)->delete();
            });

            $archivedCount += count($ids);
        }

        Log::info("MessageArchiveService: Successfully moved {$archivedCount} historical messages to cold storage.");

        return [
            'dry_run' => false,
            'days_threshold' => $days,
            'cutoff_date' => $cutoffDate->toIso8601String(),
            'eligible_count' => $totalEligible,
            'archived_count' => $archivedCount,
        ];
    }

    public function restoreArchivedMessage(int $messageId): bool
    {
        $archived = ArchivedMessage::find($messageId);
        if (! $archived) {
            return false;
        }

        return DB::transaction(function () use ($archived) {
            DB::table('messages')->insert([
                'id' => $archived->id,
                'phone_number_id' => $archived->phone_number_id,
                'destination_number' => $archived->destination_number,
                'sender' => $archived->sender,
                'peer_number' => $archived->peer_number,
                'body' => $archived->body,
                'direction' => $archived->direction->value,
                'processing_status' => $archived->processing_status->value,
                'source_time' => $archived->source_time,
                'server_received_at' => $archived->server_received_at,
                'source_system_id' => $archived->source_system_id,
                'client_message_id' => $archived->client_message_id,
                'created_at' => $archived->original_created_at,
                'updated_at' => $archived->original_updated_at,
            ]);

            $archived->delete();

            return true;
        });
    }

    public function getArchiveStats(): array
    {
        $activeCount = Message::count();
        $archivedCount = ArchivedMessage::count();

        $oldestActive = Message::oldest('id')->value('created_at');
        $oldestArchived = ArchivedMessage::oldest('id')->value('original_created_at');

        return [
            'active_messages_count' => $activeCount,
            'archived_messages_count' => $archivedCount,
            'total_messages_count' => $activeCount + $archivedCount,
            'oldest_active_at' => $oldestActive ? Carbon::parse($oldestActive)->toIso8601String() : null,
            'oldest_archived_at' => $oldestArchived ? Carbon::parse($oldestArchived)->toIso8601String() : null,
        ];
    }

    public function searchArchived(int $phoneNumberId, ?string $peerNumber = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = ArchivedMessage::where('phone_number_id', $phoneNumberId);

        if (! empty($peerNumber)) {
            $cleanPeer = PhoneNumberNormalizer::resolveToStoredNumber($peerNumber);
            $query->where(function ($q) use ($cleanPeer, $peerNumber) {
                $q->where('peer_number', $cleanPeer)
                    ->orWhere('peer_number', $peerNumber);
            });
        }

        return $query->orderBy('original_created_at', 'desc')->paginate($perPage);
    }
}
