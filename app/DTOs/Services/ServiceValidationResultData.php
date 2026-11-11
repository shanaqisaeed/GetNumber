<?php

namespace App\DTOs\Services;

readonly class ServiceValidationResultData
{
    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $suggestions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $isValid,
        public bool $isAvailable,
        public string $normalizedIdentifier,
        public string $tierBadge = 'standard',
        public string $tierLabel = '',
        public string $unitPrice = '0.0000',
        public string $discountAmount = '0.0000',
        public string $finalAmount = '0.0000',
        public int $quotaUnits = 1,
        public bool $requiresApproval = false,
        public array $errors = [],
        public array $suggestions = [],
        public array $metadata = [],
    ) {}

    public function canProceed(): bool
    {
        return $this->isValid && $this->isAvailable && $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'is_available' => $this->isAvailable,
            'can_proceed' => $this->canProceed(),
            'normalized_identifier' => $this->normalizedIdentifier,
            'tier_badge' => $this->tierBadge,
            'tier_label' => $this->tierLabel ?: __('catalog.tiers_badges.' . $this->tierBadge),
            'unit_price' => $this->unitPrice,
            'discount_amount' => $this->discountAmount,
            'final_amount' => $this->finalAmount,
            'quota_units' => $this->quotaUnits,
            'requires_approval' => $this->requiresApproval,
            'errors' => $this->errors,
            'suggestions' => $this->suggestions,
            'metadata' => $this->metadata,
        ];
    }
}

