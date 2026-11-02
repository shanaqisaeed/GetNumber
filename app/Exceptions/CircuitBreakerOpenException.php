<?php

namespace App\Exceptions;

use RuntimeException;

class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly int $retryAfterSeconds = 60,
        string $message = 'سرویس مورد نظر به علت خطاهای متوالی به صورت موقت در وضعیت مدار باز (قطع ارتباط) قرار دارد.'
    ) {
        parent::__construct($message);
    }
}

