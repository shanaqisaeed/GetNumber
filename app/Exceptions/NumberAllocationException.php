<?php

namespace App\Exceptions;

use RuntimeException;

class NumberAllocationException extends RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('exceptions.number_allocation_failed'));
    }
}
