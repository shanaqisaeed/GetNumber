<?php

namespace App\Contracts;

use App\Models\User;

interface FinancialReconciliationServiceInterface
{
    /**
     * Reconcile a single user's wallet against both the double-entry ledger
     * and wallet_transactions. Detects any discrepancies.
     */
    public function reconcileUser(User $user, bool $autoFix = false): array;

    /**
     * Perform global financial reconciliation:
     * 1. Total debits == Total credits across all ledger entries.
     * 2. Sum of all user wallet balances == Balance of 2010_USER_WALLETS ledger liability.
     * 3. Detect any orphaned or unbalanced transactions.
     */
    public function reconcileGlobal(bool $autoFix = false): array;
}

