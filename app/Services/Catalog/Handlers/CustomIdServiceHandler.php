<?php

namespace App\Services\Catalog\Handlers;

use App\DTOs\Services\ServiceValidationResultData;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\User;

class CustomIdServiceHandler extends AbstractServiceHandler
{
    public function serviceKey(): string
    {
        return 'custom_id';
    }

    public function channelType(): string
    {
        return 'handle_id';
    }

    public function validateAndQuote(
        User $user,
        ServiceDefinition $definition,
        array $payload,
        ?ServicePlan $plan = null
    ): ServiceValidationResultData {
        $tier = $this->ruleEngine->resolveTier($user);
        $policy = $this->ruleEngine->resolvePolicy($definition, $tier);

        $rawHandle = trim((string) ($payload['handle'] ?? $payload['identifier'] ?? ''));
        $cleanHandle = strtolower(ltrim($rawHandle, '@'));
        $formattedHandle = $cleanHandle !== '' ? ('@' . $cleanHandle) : '';

        $errors = array_merge(
            $this->ruleEngine->assertServiceAccess($user, $definition, $plan),
            $this->ruleEngine->validateQuotaLimit($user, $definition, $policy),
            $this->ruleEngine->validateIdentifierPolicy($cleanHandle, $policy, $tier)
        );

        $isAvailable = $formattedHandle !== '' && ! $this->isIdentifierAllocated($formattedHandle);
        if ($cleanHandle !== '' && ! $isAvailable) {
            $errors[] = __('catalog.validation.already_allocated', ['identifier' => $formattedHandle]);
        }

        $rondInfo = $this->ruleEngine->detectIdentifierRondTier($cleanHandle);
        $tierBadge = $rondInfo['tier_badge'];

        $pricingConfig = $definition->pricing_config ?? config('service_policies.services.custom_id.pricing_config', []);
        $len = strlen($cleanHandle);

        if ($len === 4) {
            $basePrice = (string) ($pricingConfig['enterprise_short_4_price'] ?? '240.0000');
            $tierBadge = 'vip_short';
        } elseif ($len === 5) {
            $basePrice = (string) ($pricingConfig['enterprise_short_5_price'] ?? '140.0000');
            $tierBadge = 'vip_short';
        } elseif ($tierBadge === 'golden_rond') {
            $basePrice = (string) ($pricingConfig['golden_rond_price'] ?? '120.0000');
        } elseif ($tierBadge === 'semi_rond') {
            $basePrice = (string) ($pricingConfig['semi_rond_price'] ?? '65.0000');
        } else {
            $basePrice = (string) ($plan?->base_price ?? $pricingConfig['standard_base_price'] ?? '30.0000');
        }

        $priceData = $this->ruleEngine->calculateDiscountedPrice($basePrice, $policy);

        return new ServiceValidationResultData(
            isValid: $errors === [],
            isAvailable: $isAvailable,
            normalizedIdentifier: $formattedHandle,
            tierBadge: $tierBadge,
            tierLabel: __('catalog.tiers_badges.' . $tierBadge),
            unitPrice: $priceData['unit_price'],
            discountAmount: $priceData['discount_amount'],
            finalAmount: $priceData['final_amount'],
            quotaUnits: 1,
            requiresApproval: false,
            errors: $errors,
            suggestions: ($cleanHandle !== '' && ! $isAvailable)
                ? $this->generateSuggestions($cleanHandle, '@')
                : [],
            metadata: [
                'clean_handle' => $cleanHandle,
                'character_length' => $len,
                'pattern_name' => $rondInfo['pattern_name'],
                'is_rond' => $rondInfo['is_rond'],
                'discount_percent' => $priceData['discount_percent'],
                'evaluated_tier' => $tier,
            ]
        );
    }

    public function provision(
        User $user,
        ServiceDefinition $definition,
        ServiceOrder $order,
        ServiceValidationResultData $validation,
        array $payload,
        ?ServicePlan $plan = null
    ): ?ServiceResourceAllocation {
        $displayTitle = trim((string) ($payload['display_title'] ?? '')) ?: $validation->normalizedIdentifier;

        return ServiceResourceAllocation::create([
            'user_id' => $user->id,
            'service_definition_id' => $definition->id,
            'service_order_id' => $order->id,
            'service_key' => $this->serviceKey(),
            'channel_type' => $this->channelType(),
            'resource_identifier' => $validation->normalizedIdentifier,
            'display_title' => $displayTitle,
            'account_tier' => $order->account_tier,
            'tier_badge' => $validation->tierBadge,
            'status' => 'active',
            'total_quota' => 1,
            'used_quota' => 1,
            'capabilities' => [
                'direct_handle_messaging' => true,
                'inbound_routing' => true,
                'outbound_identity' => true,
                'webhook_routing' => true,
            ],
            'routing_config' => $this->defaultRoutingConfig(),
            'metadata' => $validation->metadata,
            'allocated_at' => now(),
        ]);
    }
}

