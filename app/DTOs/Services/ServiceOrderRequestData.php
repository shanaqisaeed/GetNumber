<?php

namespace App\DTOs\Services;

use App\Models\User;

readonly class ServiceOrderRequestData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public User $user,
        public string $serviceKey,
        public ?string $planCode = null,
        public string $orderContext = 'services',
        public array $payload = [],
    ) {}
}

