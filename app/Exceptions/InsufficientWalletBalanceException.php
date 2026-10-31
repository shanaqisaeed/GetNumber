<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $requiredAmount = '0.0000',
        public readonly string $currentBalance = '0.0000',
        string $message = 'موجودی کیف پول شما برای انجام این عملیات کافی نمی‌باشد.'
    ) {
        parent::__construct($message);
    }
}

