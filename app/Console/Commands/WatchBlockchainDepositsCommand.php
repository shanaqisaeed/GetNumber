<?php

namespace App\Console\Commands;

use App\Contracts\BlockchainWatcherServiceInterface;
use Illuminate\Console\Command;

class WatchBlockchainDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blockchain:watch-deposits {--limit=50 : Maximum pending invoices to inspect per run}';

    /**
     * The console command description.
     */
    protected $description = 'Automatically scan blockchain incoming transactions and match uncredited deposits without manual hash entry';

    /**
     * Execute the console command.
     */
    public function handle(BlockchainWatcherServiceInterface $watcherService): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Scanning blockchain networks and payment gateways (limit: {$limit})...");

        $stats = $watcherService->scanAndMatchIncomingDeposits($limit);
        $progressStats = $watcherService->progressPendingConfirmations($limit);

        $this->table(
            ['Blockchain Watcher Metric', 'Value'],
            [
                ['Gateways Scanned', $stats['scanned_gateways']],
                ['Incoming Transactions Detected', $stats['incoming_transactions_detected']],
                ['Auto-Matched Deposits', $stats['auto_matched_deposits']],
                ['Confirmed and Credited', $stats['completed'] + ($progressStats['completed'] ?? 0)],
                ['Awaiting Confirmation Threshold', $stats['confirming'] + ($progressStats['still_pending'] ?? 0)],
                ['Watcher Errors', $stats['errors']],
            ]
        );

        return Command::SUCCESS;
    }
}
