<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MessageArchiveServiceInterface
{
    /**
     * Archive messages older than specified days to cold storage table.
     * Moves records atomically and verifies integrity before pruning active table.
     */
    public function archiveOlderThan(int $days = 90, int $chunkSize = 500, bool $dryRun = false): array;

    /**
     * Restore a specific archived message back to the active messages table.
     */
    public function restoreArchivedMessage(int $messageId): bool;

    /**
     * Retrieve global data retention statistics (active vs archived row counts).
     */
    public function getArchiveStats(): array;

    /**
     * Search and view historical messages from cold storage.
     */
    public function searchArchived(int $phoneNumberId, ?string $peerNumber = null, int $perPage = 25): LengthAwarePaginator;
}

