<?php

namespace App\Exceptions;

class NumberAlreadyAllocatedException extends NumberAllocationException
{
    public function __construct(string $number)
    {
        parent::__construct("شماره سازمانی {$number} در حال حاضر در سامانه موجود بوده و امکان ثبت یا تخصیص مجدد آن وجود ندارد.");
    }
}
