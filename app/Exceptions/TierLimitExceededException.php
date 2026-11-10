<?php

namespace App\Exceptions;

use RuntimeException;

class TierLimitExceededException extends RuntimeException
{
    public function __construct(
        ?string $message = null,
        public readonly string $tier = 'new',
        public readonly string $limitType = 'velocity',
        public readonly int $limit = 0,
        public readonly int $retryAfterSeconds = 60
    ) {
        parent::__construct($message ?? __('exceptions.tier_limit_exceeded'));
    }
}

