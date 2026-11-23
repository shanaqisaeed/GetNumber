<?php

namespace App\Console\Commands;

use App\Contracts\ConferenceServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredConferenceRoomsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conference:cleanup-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired conference rooms older than 7 days and soft delete or archive them';

    /**
     * Execute the console command.
     */
    public function handle(ConferenceServiceInterface $conferenceService): int
    {
        $this->info('Starting cleanup of expired conference rooms (7-day lifecycle)...');

        $cleanedCount = $conferenceService->cleanupExpiredRooms();

        Log::info("Conference cleanup cron executed: {$cleanedCount} expired rooms processed.");

        $this->info(__('conference.cleanup.cron_success', ['count' => $cleanedCount]));

        return Command::SUCCESS;
    }
}
