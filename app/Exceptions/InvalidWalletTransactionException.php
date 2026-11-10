<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidWalletTransactionException extends RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('exceptions.invalid_wallet_transaction'));
    }
}