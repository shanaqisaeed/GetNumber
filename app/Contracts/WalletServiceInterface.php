<?php

namespace App\Contracts;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;

interface WalletServiceInterface
{
    /**
     * Get or initialize the user's wallet.
     */
    public function getWallet(User $user): Wallet;

    /**
     * Deposit funds (e.g. from payment gateway or external transfer).
     */
    public function deposit(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction;

    /**
     * Charge user wallet for purchasing a virtual number.
     * Throws InsufficientWalletBalanceException if balance is below required amount.
     */
    public function chargeForNumberPurchase(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction;

    /**
     * Refund coins to user wallet (e.g. if allocation failed).
     */
    public function refund(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction;

    /**
     * Block/hold funds for a service number request.
     */
    public function holdForNumberRequest(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction;

    /**
     * Finalize held funds upon admin approval.
     */
    public function finalizeNumberRequestHold(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): void;

    /**
     * Release held funds upon admin rejection or user cancellation.
     */
    public function releaseNumberRequestHold(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction;

    /**
     * Admin credits user wallet with an audit reason.
     */
    public function adminCredit(
        User $user,
        string $amount,
        User $admin,
        string $reason,
        ?string $referenceId = null
    ): WalletTransaction;

    /**
     * Admin debits user wallet with an audit reason.
     */
    public function adminDebit(
        User $user,
        string $amount,
        User $admin,
        string $reason,
        ?string $referenceId = null
    ): WalletTransaction;

    /**
     * Reversal of an existing transaction by admin.
     */
    public function reverseTransaction(
        WalletTransaction $originalTransaction,
        User $admin,
        string $reason
    ): WalletTransaction;

    /**
     * Charge user wallet for active service communication usage (SMS segments or Voice call duration).
     */
    public function chargeForUsage(
        User $user,
        string $amount,
        string $referenceId,
        \App\Enums\WalletTransactionType $type,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction;

    /**
     * Reconcile user wallet balance with its ledger history.
     */
    public function reconcile(User $user): bool;
}