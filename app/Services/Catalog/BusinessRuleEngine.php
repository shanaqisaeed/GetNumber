<?php

namespace App\Services\Catalog;

use App\Contracts\Services\BusinessRuleEngineInterface;
use App\Models\ServiceDefinition;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\User;

class BusinessRuleEngine implements BusinessRuleEngineInterface
{
    public function resolveTier(User $user): string
    {
        return $user->effectiveServiceTier();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePolicy(ServiceDefinition $definition, string $tier): array
    {
        $configPolicy = config("service_policies.services.{$definition->key}.validation_policies.{$tier}", []);
        $dbPolicies = $definition->validation_policies ?? [];
        $dbTierPolicy = is_array($dbPolicies[$tier] ?? null) ? $dbPolicies[$tier] : [];

        return array_replace_recursive($configPolicy, $dbTierPolicy);
    }

    /**
     * @return array<int, string>
     */
    public function assertServiceAccess(
        User $user,
        ServiceDefinition $definition,
        ?ServicePlan $plan = null,
        bool $requirePurchasable = true
    ): array {
        $errors = [];
        $tier = $this->resolveTier($user);

        if (! $definition->is_active) {
            $errors[] = __('catalog.validation.service_inactive');
        }

        if ($requirePurchasable && ! $definition->is_purchasable) {
            $errors[] = __('catalog.validation.service_not_purchasable');
        }

        if (! $definition->isAvailableForTier($tier)) {
            $errors[] = __('catalog.validation.role_not_authorized', [
                'tier' => __('catalog.tiers.' . $tier),
            ]);
        }

        if ($plan !== null) {
            if (! $plan->is_active) {
                $errors[] = __('catalog.validation.service_not_purchasable');
            }

            if ($plan->target_tier === 'enterprise' && $tier !== 'enterprise') {
                $errors[] = __('catalog.validation.role_not_authorized', [
                    'tier' => __('catalog.tiers.' . $tier),
                ]);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    public function validateIdentifierPolicy(string $identifier, array $policy, string $tier): array
    {
        $errors = [];
        $clean = trim($identifier);

        if ($clean === '') {
            return [__('catalog.validation.identifier_required')];
        }

        $length = strlen($clean);
        $minLength = (int) ($policy['min_length'] ?? 1);
        $maxLength = (int) ($policy['max_length'] ?? 64);

        if ($length < $minLength) {
            $errors[] = __('catalog.validation.min_length_violation', [
                'tier' => __('catalog.tiers.' . $tier),
                'min' => $minLength,
                'actual' => $length,
            ]);
        }

        if ($length > $maxLength) {
            $errors[] = __('catalog.validation.max_length_violation', [
                'max' => $maxLength,
            ]);
        }

        if (($policy['allow_consecutive_underscores'] ?? true) === false && str_contains($clean, '__')) {
            $errors[] = __('catalog.validation.consecutive_symbols_not_allowed');
        }

        if (($policy['allow_consecutive_hyphens'] ?? true) === false && str_contains($clean, '--')) {
            $errors[] = __('catalog.validation.consecutive_symbols_not_allowed');
        }

        $pattern = $policy['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '' && $length >= 2) {
            if (! preg_match($pattern, $clean)) {
                $errors[] = __('catalog.validation.invalid_format');
            }
        }

        $reservedWords = array_unique(array_merge(
            config('service_policies.global_reserved_identifiers', []),
            (array) ($policy['reserved_words'] ?? [])
        ));

        if (in_array(strtolower($clean), array_map('strtolower', $reservedWords), true)) {
            $errors[] = __('catalog.validation.reserved_identifier', [
                'identifier' => $clean,
            ]);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<int, string>
     */
    public function validateQuotaLimit(User $user, ServiceDefinition $definition, array $policy): array
    {
        $maxActive = (int) ($policy['max_active_resources'] ?? 0);
        if ($maxActive <= 0) {
            return [];
        }

        $currentActive = ServiceResourceAllocation::query()
            ->where('user_id', $user->id)
            ->where('service_definition_id', $definition->id)
            ->where('status', 'active')
            ->count();

        if ($currentActive >= $maxActive) {
            return [
                __('catalog.validation.quota_limit_reached', [
                    'max' => $maxActive,
                ]),
            ];
        }

        return [];
    }

    /**
     * Detect rond / rhythmic / short pattern quality on an alphanumeric identifier.
     *
     * @return array{tier_badge: string, is_rond: bool, pattern_name: string}
     */
    public function detectIdentifierRondTier(string $cleanIdentifier): array
    {
        $lower = strtolower($cleanIdentifier);
        $len = strlen($lower);

        if ($len > 0 && $len <= 5) {
            return [
                'tier_badge' => 'vip_short',
                'is_rond' => true,
                'pattern_name' => 'VIP Short (' . $len . ' chars)',
            ];
        }

        // Check triple+ identical characters (e.g., aaa, 777) or full palindrome
        if (preg_match('/(.)\1{2,}/', $lower) || ($len >= 4 && $lower === strrev($lower))) {
            return [
                'tier_badge' => 'golden_rond',
                'is_rond' => true,
                'pattern_name' => 'Symmetric / Triple Repeat',
            ];
        }

        // Check repeating pair or ending digits (e.g. gold77, ababab, x2020)
        if (preg_match('/(.{2,3})\1+$/', $lower) || preg_match('/(.)\1/', $lower)) {
            return [
                'tier_badge' => 'semi_rond',
                'is_rond' => true,
                'pattern_name' => 'Rhythmic Pair',
            ];
        }

        return [
            'tier_badge' => 'standard',
            'is_rond' => false,
            'pattern_name' => 'Standard',
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{unit_price: string, discount_amount: string, final_amount: string, discount_percent: int}
     */
    public function calculateDiscountedPrice(string $basePrice, array $policy): array
    {
        $unitPrice = bcadd($basePrice, '0.0000', 4);
        $discountPercent = max(0, min(100, (int) ($policy['discount_percent'] ?? 0)));

        if ($discountPercent <= 0 || bccomp($unitPrice, '0.0000', 4) <= 0) {
            return [
                'unit_price' => $unitPrice,
                'discount_amount' => '0.0000',
                'final_amount' => $unitPrice,
                'discount_percent' => 0,
            ];
        }

        $multiplier = bcdiv((string) $discountPercent, '100', 6);
        $discountAmount = bcmul($unitPrice, $multiplier, 4);
        $finalAmount = bcsub($unitPrice, $discountAmount, 4);

        if (bccomp($finalAmount, '0.0000', 4) < 0) {
            $finalAmount = '0.0000';
        }

        return [
            'unit_price' => $unitPrice,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'discount_percent' => $discountPercent,
        ];
    }
}

