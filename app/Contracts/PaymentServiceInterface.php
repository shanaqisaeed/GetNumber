<?php

namespace App\Contracts;

use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;
use App\Models\User;

interface PaymentServiceInterface
{
    /**
     * Create a new deposit request for user and generate deposit address.
     */
    public function createDepositRequest(
        User $user,
        string $trxAmount,
        string $network = 'TRON',
        ?string $gatewayDriver = null
    ): CryptoDeposit;

    /**
     * Submit a transaction hash (TX Hash) for a pending deposit.
     */
    public function submitTxHash(
        CryptoDeposit $deposit,
        string $txHash,
        ?User $actor = null
    ): CryptoDeposit;

    /**
     * Verify a single deposit against the blockchain / gateway.
     */
    public function verifyDeposit(
        CryptoDeposit $deposit,
        ?User $actor = null
    ): PaymentVerificationResult;

    /**
     * Atomically process a verified deposit, crediting the user's wallet with COIN.
     */
    public function processVerifiedDeposit(
        CryptoDeposit $deposit,
        PaymentVerificationResult $verification,
        ?User $actor = null
    ): void;

    /**
     * Batch process pending deposits that are due for re-check.
     */
    public function processPendingDeposits(int $limit = 50): array;

    /**
     * Expire stale deposit requests that exceeded their validity window.
     */
    public function expireStaleDeposits(): int;
}

