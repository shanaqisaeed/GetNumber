<?php

namespace App\Exceptions;

use RuntimeException;

class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly int $retryAfterSeconds = 60,
        ?string $message = null
    ) {
        parent::__construct($message ?? __('exceptions.circuit_breaker_open'));
    }
}

