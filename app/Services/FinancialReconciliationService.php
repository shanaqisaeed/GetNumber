<?php

namespace App\Services;

use App\Contracts\FinancialReconciliationServiceInterface;
use App\Contracts\LedgerServiceInterface;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

class FinancialReconciliationService implements FinancialReconciliationServiceInterface
{
    protected const DECIMAL_SCALE = 4;

    public function __construct(
        protected LedgerServiceInterface $ledgerService
    ) {}

    public function reconcileUser(User $user, bool $autoFix = false): array
    {
        /** @var Wallet $wallet */
        $wallet = $user->getOrCreateWallet();
        $cachedBalance = number_format((float) $wallet->balance, self::DECIMAL_SCALE, '.', '');
        $ledgerBalance = $this->ledgerService->calculateUserBalance($user);

        // Check if ledger entries exist for this user
        $hasLedgerEntries = \App\Models\LedgerEntry::where('user_id', $user->id)->exists();

        // If no double-entry ledger entries exist yet (legacy wallet), reconcile from wallet_transactions
        $isTxReconciled = $wallet->reconcileBalanceFromLedger();

        $diff = bcsub($cachedBalance, $ledgerBalance, self::DECIMAL_SCALE);
        $isLedgerMatched = bccomp($diff, '0.0000', self::DECIMAL_SCALE) === 0;

        $hasDiscrepancy = $hasLedgerEntries ? (! $isLedgerMatched) : (! $isTxReconciled);

        if ($hasDiscrepancy) {
            Log::warning("Financial Reconciliation discrepancy detected for User #{$user->id}", [
                'user_id' => $user->id,
                'cached_balance' => $cachedBalance,
                'ledger_balance' => $ledgerBalance,
                'difference' => $diff,
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'financial.reconciliation.discrepancy',
                'entity_type' => Wallet::class,
                'entity_id' => $wallet->id,
                'payload' => [
                    'cached_balance' => $cachedBalance,
                    'ledger_balance' => $ledgerBalance,
                    'difference' => $diff,
                    'is_tx_reconciled' => $isTxReconciled,
                ],
                'ip_address' => '127.0.0.1',
            ]);

            if ($autoFix && $hasLedgerEntries) {
                // Adjust cached wallet balance to strictly match verified ledger
                $wallet->update(['balance' => $ledgerBalance]);
            }
        }

        return [
            'user_id' => $user->id,
            'is_matched' => ! $hasDiscrepancy,
            'cached_balance' => $cachedBalance,
            'ledger_balance' => $ledgerBalance,
            'difference' => $diff,
            'transactions_consistent' => $isTxReconciled,
            'has_ledger_entries' => $hasLedgerEntries,
            'fixed' => $autoFix && $hasDiscrepancy && $hasLedgerEntries,
        ];
    }

    public function reconcileGlobal(bool $autoFix = false): array
    {
        $globalBalance = $this->ledgerService->checkGlobalBalance();
        $totalCachedWallets = number_format((float) Wallet::sum('balance'), self::DECIMAL_SCALE, '.', '');

        $walletAccount = \App\Models\LedgerAccount::findByCode('2010_USER_WALLETS');
        $totalLedgerLiability = '0.0000';

        if ($walletAccount) {
            $credits = (string) \App\Models\LedgerEntry::where('ledger_account_id', $walletAccount->id)->sum('credit');
            $debits = (string) \App\Models\LedgerEntry::where('ledger_account_id', $walletAccount->id)->sum('debit');
            $totalLedgerLiability = number_format((float) bcsub($credits, $debits, self::DECIMAL_SCALE), self::DECIMAL_SCALE, '.', '');
        }

        $walletLiabilityDifference = bcsub($totalCachedWallets, $totalLedgerLiability, self::DECIMAL_SCALE);
        $isWalletsMatched = bccomp($walletLiabilityDifference, '0.0000', self::DECIMAL_SCALE) === 0;

        $discrepantUsers = [];
        $users = User::has('wallet')->with('wallet')->get();

        foreach ($users as $user) {
            $userResult = $this->reconcileUser($user, $autoFix);
            if (! $userResult['is_matched']) {
                $discrepantUsers[] = $userResult;
            }
        }

        $isSystemHealthy = $globalBalance['is_balanced'] && empty($discrepantUsers);

        return [
            'is_healthy' => $isSystemHealthy,
            'global_ledger' => $globalBalance,
            'total_wallets_balance' => $totalCachedWallets,
            'total_ledger_liability' => $totalLedgerLiability,
            'wallets_liability_diff' => $walletLiabilityDifference,
            'is_wallets_matched' => $isWalletsMatched,
            'total_users_checked' => $users->count(),
            'discrepant_users_count' => count($discrepantUsers),
            'discrepant_users' => $discrepantUsers,
        ];
    }
}

