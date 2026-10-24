<?php

namespace App\Exceptions;

class NumberRequestAlreadyReviewedException extends NumberAllocationException
{
    public function __construct(string $message = 'این درخواست قبلاً مورد بررسی و تعیین تکلیف قرار گرفته است.')
    {
        parent::__construct($message);
    }
}
