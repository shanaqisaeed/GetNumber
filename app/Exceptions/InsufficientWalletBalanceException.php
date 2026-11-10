<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $requiredAmount = '0.0000',
        public readonly string $currentBalance = '0.0000',
        ?string $message = null
    ) {
        parent::__construct($message ?? __('exceptions.insufficient_wallet_balance'));
    }
}

