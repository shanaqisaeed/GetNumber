<?php

namespace App\DTOs;

class PaymentVerificationResult
{
    public function __construct(
        public readonly bool $isVerified,
        public readonly string $status, // e.g. 'Approved', 'Done', 'Pending', 'Rejected'
        public readonly ?string $txHash = null,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $network = null,
        public readonly ?string $destinationAddress = null,
        public readonly int $confirmations = 0,
        public readonly ?string $failureReason = null,
        public readonly array $rawResponse = []
    ) {}

    public static function verified(
        string $txHash,
        string $amount,
        string $currency,
        string $destinationAddress,
        string $network = 'TRC20',
        int $confirmations = 1,
        array $raw = []
    ): self {
        return new self(
            isVerified: true,
            status: 'Approved',
            txHash: $txHash,
            amount: $amount,
            currency: strtoupper($currency),
            network: $network,
            destinationAddress: $destinationAddress,
            confirmations: $confirmations,
            failureReason: null,
            rawResponse: $raw
        );
    }

    public static function pending(string $status = 'Pending', ?string $reason = null, array $raw = []): self
    {
        return new self(
            isVerified: false,
            status: $status,
            failureReason: $reason,
            rawResponse: $raw
        );
    }

    public static function rejected(string $reason, array $raw = []): self
    {
        return new self(
            isVerified: false,
            status: 'Rejected',
            failureReason: $reason,
            rawResponse: $raw
        );
    }

    public function toArray(): array
    {
        return [
            'is_verified' => $this->isVerified,
            'status' => $this->status,
            'tx_hash' => $this->txHash,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'network' => $this->network,
            'destination_address' => $this->destinationAddress,
            'confirmations' => $this->confirmations,
            'failure_reason' => $this->failureReason,
            'raw_response' => $this->rawResponse,
        ];
    }
}

