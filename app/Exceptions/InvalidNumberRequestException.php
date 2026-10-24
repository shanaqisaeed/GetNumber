<?php

namespace App\Exceptions;

class InvalidNumberRequestException extends NumberAllocationException
{
    public function __construct(string $message = 'درخواست ارسال شده فاقد ضوابط مجاز سامانه است.')
    {
        parent::__construct($message);
    }
}
