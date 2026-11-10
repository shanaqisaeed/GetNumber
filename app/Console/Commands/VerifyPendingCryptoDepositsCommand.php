<?php

namespace App\Console\Commands;

use App\Contracts\PaymentServiceInterface;
use Illuminate\Console\Command;

class VerifyPendingCryptoDepositsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:verify-pending {--limit=50 : Maximum number of transactions to query per run}';

    /**
     * The console command description.
     */
    protected $description = 'Periodically verify pending crypto deposit transactions from blockchain networks and auto-credit wallets';

    /**
     * Execute the console command.
     */
    public function handle(PaymentServiceInterface $paymentService): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Checking pending crypto deposits (limit: {$limit})...");

        $stats = $paymentService->processPendingDeposits($limit);
        $expiredCount = $paymentService->expireStaleDeposits();

        $this->info("Transactions inspected: {$stats['checked']}");
        $this->info("Confirmed and credited: {$stats['completed']}");
        $this->info("Rejected or failed: {$stats['failed']}");
        $this->info("Still awaiting confirmations: {$stats['still_pending']}");

        if ($expiredCount > 0) {
            $this->warn("Expired transactions: {$expiredCount}");
        }

        return Command::SUCCESS;
    }
}