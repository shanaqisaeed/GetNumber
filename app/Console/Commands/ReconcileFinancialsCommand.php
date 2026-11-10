<?php

namespace App\Console\Commands;

use App\Contracts\FinancialReconciliationServiceInterface;
use App\Models\User;
use Illuminate\Console\Command;

class ReconcileFinancialsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'financial:reconcile 
                            {--user= : Specific user ID to audit and reconcile}
                            {--fix : Automatically fix and align cached wallet balance with ledger entries}';

    /**
     * The console command description.
     */
    protected $description = 'Audit and reconcile user wallet balances against double-entry general ledger entries';

    /**
     * Execute the console command.
     */
    public function handle(FinancialReconciliationServiceInterface $reconciliationService): int
    {
        $userId = $this->option('user');
        $autoFix = (bool) $this->option('fix');

        $this->info('Starting financial reconciliation and ledger audit...');

        if ($userId) {
            $user = User::find($userId);
            if (! $user) {
                $this->error("User with ID #{$userId} was not found.");
                return Command::FAILURE;
            }

            $result = $reconciliationService->reconcileUser($user, $autoFix);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['User ID', $result['user_id']],
                    ['Reconciliation Status', $result['is_matched'] ? 'MATCHED / HEALTHY' : 'DISCREPANCY DETECTED'],
                    ['Cached Wallet Balance', $result['cached_balance']],
                    ['General Ledger Balance', $result['ledger_balance']],
                    ['Calculated Difference', $result['difference']],
                    ['Auto-Fixed', $result['fixed'] ? 'Yes' : 'No'],
                ]
            );

            return $result['is_matched'] ? Command::SUCCESS : Command::FAILURE;
        }

        $report = $reconciliationService->reconcileGlobal($autoFix);

        $this->line('');
        $this->info('Global General Ledger Audit Report:');
        $this->table(
            ['Global Metric', 'Value'],
            [
                ['Double-Entry Balanced (Debit = Credit)', $report['global_ledger']['is_balanced'] ? 'BALANCED' : 'UNBALANCED!'],
                ['Total Global Debit', $report['global_ledger']['total_debit']],
                ['Total Global Credit', $report['global_ledger']['total_credit']],
                ['Total Journal Entries Count', $report['global_ledger']['journal_count']],
                ['Total Ledger Lines Count', $report['global_ledger']['entry_count']],
                ['Sum of All Wallet Balances', $report['total_wallets_balance']],
                ['Total Ledger User Liabilities', $report['total_ledger_liability']],
                ['Total Users Checked', $report['total_users_checked']],
                ['Users with Discrepancies', $report['discrepant_users_count']],
            ]
        );

        if (! $report['is_healthy']) {
            $this->error("Warning: Detected {$report['discrepant_users_count']} discrepancy cases in the system!");
            return Command::FAILURE;
        }

        $this->info('All financial records and user wallets are 100% balanced and reconciled.');
        return Command::SUCCESS;
    }
}