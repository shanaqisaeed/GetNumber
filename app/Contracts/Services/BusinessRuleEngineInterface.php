<?php

namespace App\Contracts\Services;

use App\Models\ServiceDefinition;
use App\Models\ServicePlan;
use App\Models\User;

interface BusinessRuleEngineInterface
{
    /**
     * Resolve effective user tier ('individual' or 'enterprise').
     */
    public function resolveTier(User $user): string;

    /**
     * Resolve merged validation policy for a service definition and user tier.
     *
     * @return array<string, mixed>
     */
    public function resolvePolicy(ServiceDefinition $definition, string $tier): array;

    /**
     * Evaluate feature flags (is_active, is_purchasable) and role/tier authorization.
     *
     * @return array<int, string>
     */
    public function assertServiceAccess(
        User $user,
        ServiceDefinition $definition,
        ?ServicePlan $plan = null,
        bool $requirePurchasable = true
    ): array;

    /**
     * Evaluate policy constraints on an identifier (min/max length, regex, consecutive symbols, reserved words).
     *
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    public function validateIdentifierPolicy(string $identifier, array $policy, string $tier): array;

    /**
     * Evaluate active resource count quota for the user on the given service.
     *
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    public function validateQuotaLimit(User $user, ServiceDefinition $definition, array $policy): array;

    /**
     * Apply tier-based discount policy using 4-decimal bcmath precision.
     *
     * @param  array<string, mixed>  $policy
     * @return array{unit_price: string, discount_amount: string, final_amount: string, discount_percent: int}
     */
    public function calculateDiscountedPrice(string $basePrice, array $policy): array;
}

