<?php

namespace App\Exceptions;

use RuntimeException;

class ContentSecurityViolationException extends RuntimeException
{
    protected array $details = [];

    public function __construct(?string $message = null, array $details = [])
    {
        parent::__construct($message ?? __('exceptions.content_security_violation'));
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}