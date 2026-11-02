<?php

namespace App\Exceptions;

use RuntimeException;

class ContentSecurityViolationException extends RuntimeException
{
    protected array $details = [];

    public function __construct(string $message = 'محتوای پیام به دلیل نقض قوانین ضدکلاهبرداری و فیشینگ رد شد.', array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

