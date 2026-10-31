<?php

namespace App\DTOs;

class PaymentAddressResult
{
    public function __construct(
        public readonly string $address,
        public readonly string $currency,
        public readonly string $network,
        public readonly ?string $tag = null,
        public readonly ?string $qrCodeUrl = null,
        public readonly ?\DateTimeInterface $expiresAt = null,
        public readonly array $extra = []
    ) {}

    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'currency' => $this->currency,
            'network' => $this->network,
            'tag' => $this->tag,
            'qr_code_url' => $this->qrCodeUrl,
            'expires_at' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'extra' => $this->extra,
        ];
    }
}

