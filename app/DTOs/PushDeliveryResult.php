<?php

namespace App\DTOs;

class PushDeliveryResult
{
    public function __construct(
        public readonly bool $delivered,
        public readonly string $provider,
        public readonly string $deviceUuid,
        public readonly bool $acknowledged = false,
        public readonly bool $tokenInvalidated = false,
        public readonly ?string $messageId = null,
        public readonly ?string $errorReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'delivered' => $this->delivered,
            'provider' => $this->provider,
            'device_uuid' => $this->deviceUuid,
            'acknowledged' => $this->acknowledged,
            'token_invalidated' => $this->tokenInvalidated,
            'message_id' => $this->messageId,
            'error_reason' => $this->errorReason,
        ];
    }
}
