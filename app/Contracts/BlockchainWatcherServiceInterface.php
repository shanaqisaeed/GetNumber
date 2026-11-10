<?php

namespace App\Contracts;

interface BlockchainWatcherServiceInterface
{
    /**
     * Scan active blockchain gateways and match incoming transfers with pending deposit invoices.
     * Automatically claims verified deposits without requiring manual TXID submission.
     */
    public function scanAndMatchIncomingDeposits(int $limit = 50): array;

    /**
     * Poll and progress pending/confirming deposits toward block finality.
     */
    public function progressPendingConfirmations(int $limit = 50): array;
}