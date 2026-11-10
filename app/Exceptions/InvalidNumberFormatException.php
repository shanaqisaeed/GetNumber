<?php

namespace App\Exceptions;

class InvalidNumberFormatException extends NumberAllocationException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('exceptions.invalid_number_format'));
    }
}