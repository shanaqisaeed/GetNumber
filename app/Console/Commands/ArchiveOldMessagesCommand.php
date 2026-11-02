<?php

namespace App\Console\Commands;

use App\Contracts\MessageArchiveServiceInterface;
use Illuminate\Console\Command;

class ArchiveOldMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'messages:archive
                            {--days=90 : Retention period in days (older messages are moved to cold storage)}
                            {--chunk=500 : Maximum number of records to process per batch}
                            {--dry-run : Simulate archiving without actually moving database records}';

    /**
     * The console command description.
     */
    protected $description = 'Safely archive historical messages to cold storage to optimize database performance';

    /**
     * Execute the console command.
     */
    public function handle(MessageArchiveServiceInterface $archiveService): int
    {
        $days = (int) $this->option('days');
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Checking and archiving messages older than {$days} days to cold storage...");

        $result = $archiveService->archiveOlderThan($days, $chunk, $dryRun);

        $this->table(
            ['Archival Metric', 'Value'],
            [
                ['Retention Threshold (Days)', $result['days_threshold']],
                ['Archival Cutoff Date', $result['cutoff_date']],
                ['Eligible Messages Found', $result['eligible_count']],
                ['Messages Moved to Cold Storage', $dryRun ? 'Dry run (0 records moved)' : $result['archived_count']],
                ['Dry Run Mode', $dryRun ? 'Active' : 'Disabled'],
            ]
        );

        if ($dryRun) {
            $this->warn('Execution completed in dry-run mode. No database records were modified.');
        } else {
            $this->info("Successfully moved {$result['archived_count']} messages to archived storage with integrity verified.");
        }

        return Command::SUCCESS;
    }
}
