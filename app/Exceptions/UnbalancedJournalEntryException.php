<?php

namespace App\Exceptions;

use RuntimeException;

class UnbalancedJournalEntryException extends RuntimeException
{
    public function __construct(
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly string $entryNumber,
        string $message = 'سند حسابداری نامتوازن است: مجموع بدهکار با مجموع بستانکار برابر نیست.'
    ) {
        parent::__construct("{$message} [سند: {$entryNumber} | بدهکار: {$totalDebit} | بستانکار: {$totalCredit}]");
    }
}

