<?php

namespace App\Exceptions;

class InvalidNumberFormatException extends NumberAllocationException
{
    public function __construct(string $message = 'قالب شماره وارد شده نامعتبر است.')
    {
        parent::__construct($message);
    }
}
