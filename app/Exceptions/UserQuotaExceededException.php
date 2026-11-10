<?php

namespace App\Exceptions;

class UserQuotaExceededException extends NumberAllocationException
{
    public function __construct(int $maxAllowed)
    {
        parent::__construct(__('exceptions.user_quota_exceeded', ['max' => $maxAllowed]));
    }
}
