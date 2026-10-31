<?php

namespace App\Contracts;

use App\DTOs\PaymentAddressResult;
use App\DTOs\PaymentVerificationResult;
use App\Models\CryptoDeposit;

interface PaymentGatewayInterface
{
    /**
     * Unique driver identifier for this gateway (e.g. 'nobitex', 'tron_grid', 'mock').
     */
    public function getIdentifier(): string;

    /**
     * Human-readable display name.
     */
    public function getName(): string;

    /**
     * Generate or retrieve a dedicated deposit address for the user's payment request.
     */
    public function generateDepositAddress(CryptoDeposit $deposit): PaymentAddressResult;

    /**
     * Verify whether a submitted TX Hash on the blockchain or exchange has successfully deposited the required funds.
     */
    public function verifyTransaction(CryptoDeposit $deposit, string $txHash): PaymentVerificationResult;

    /**
     * Retrieve recent deposits detected on this gateway (for polling or background matching).
     */
    public function getRecentDeposits(?string $currency = null): array;
}

