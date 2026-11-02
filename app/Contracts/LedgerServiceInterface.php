<?php

namespace App\Contracts;

use App\Models\JournalEntry;
use App\Models\User;

interface LedgerServiceInterface
{
    /**
     * Record a balanced double-entry journal with debit and credit lines.
     * Throws UnbalancedJournalEntryException if sum(debit) != sum(credit).
     */
    public function recordJournal(
        string $description,
        array $lines,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $actor = null,
        array $metadata = [],
        string $currency = 'COIN'
    ): JournalEntry;

    /**
     * Record deposit journal entry:
     * Debit: 1010_PLATFORM_TREASURY (Asset +)
     * Credit: 2010_USER_WALLETS (Liability + for user)
     */
    public function recordDeposit(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry;

    /**
     * Record number purchase journal entry:
     * Debit: 2010_USER_WALLETS (Liability - for user)
     * Credit: 4010_NUMBER_REVENUE (Revenue +)
     */
    public function recordNumberPurchase(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry;

    /**
     * Record refund journal entry:
     * Debit: 4010_NUMBER_REVENUE (Revenue -)
     * Credit: 2010_USER_WALLETS (Liability + for user)
     */
    public function recordRefund(
        User $user,
        string $amount,
        string $referenceId,
        string $description,
        array $metadata = []
    ): JournalEntry;

    /**
     * Record admin adjustment journal entry (Credit or Debit to user wallet vs 9010_SUSPENSE).
     */
    public function recordAdminAdjustment(
        User $user,
        string $amount,
        bool $isCredit,
        User $admin,
        string $reason,
        string $referenceId
    ): JournalEntry;

    /**
     * Calculate user balance directly from the double-entry ledger:
     * User Wallet Balance = Sum(Credit to 2010_USER_WALLETS for user) - Sum(Debit to 2010_USER_WALLETS for user)
     */
    public function calculateUserBalance(User $user): string;

    /**
     * Check if total debits equal total credits across the entire system.
     */
    public function checkGlobalBalance(): array;
}

