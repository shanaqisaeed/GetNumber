<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidWalletTransactionException extends RuntimeException
{
    public function __construct(string $message = 'تراکنش مالی نامعتبر است.')
    {
        parent::__construct($message);
    }
}

