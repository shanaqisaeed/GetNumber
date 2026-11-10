<?php

namespace App\Exceptions;

use RuntimeException;

class UnbalancedJournalEntryException extends RuntimeException
{
    public function __construct(
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly string $entryNumber,
        ?string $message = null
    ) {
        $msg = $message ?? __('exceptions.unbalanced_journal_entry', [
            'entry' => $entryNumber,
            'debit' => $totalDebit,
            'credit' => $totalCredit,
        ]);
        parent::__construct($msg);
    }
}