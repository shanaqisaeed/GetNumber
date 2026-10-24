<?php

namespace App\Exceptions;

class UserQuotaExceededException extends NumberAllocationException
{
    public function __construct(int $maxAllowed)
    {
        parent::__construct("سقف مجاز تخصیص خطوط فعال برای این حساب کاربری ({$maxAllowed} خط) تکمیل شده است.");
    }
}
