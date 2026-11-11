<?php

namespace App\Services\Catalog\Handlers;

use App\Contracts\Services\BusinessRuleEngineInterface;
use App\DTOs\Services\ServiceValidationResultData;
use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\User;
use App\Services\Numbers\RondPatternDetectorService;

class PhoneLineServiceHandler extends AbstractServiceHandler
{
    public function __construct(
        BusinessRuleEngineInterface $ruleEngine,
        protected RondPatternDetectorService $rondDetector
    ) {
        parent::__construct($ruleEngine);
    }

    public function serviceKey(): string
    {
        return 'phone_line';
    }

    public function channelType(): string
    {
        return 'sms_line';
    }

    public function validateAndQuote(
        User $user,
        ServiceDefinition $definition,
        array $payload,
        ?ServicePlan $plan = null
    ): ServiceValidationResultData {
        $tier = $this->ruleEngine->resolveTier($user);
        $policy = $this->ruleEngine->resolvePolicy($definition, $tier);

        $errors = array_merge(
            $this->ruleEngine->assertServiceAccess($user, $definition, $plan),
            $this->ruleEngine->validateQuotaLimit($user, $definition, $policy)
        );

        $allocationMode = (string) ($payload['allocation_mode'] ?? ($plan?->metadata['allocation_mode'] ?? 'standard_9digit'));
        $rawNumber = preg_replace('/\D+/', '', (string) ($payload['preferred_number'] ?? ''));

        if ($allocationMode === 'enterprise_shortcode') {
            if ($tier !== 'enterprise') {
                $errors[] = __('catalog.validation.shortcode_enterprise_only');
            }

            $requestedLength = (int) ($payload['requested_length'] ?? ($plan?->metadata['digits'] ?? (strlen($rawNumber) ?: 6)));
            $allowedLengths = (array) ($policy['allowed_lengths'] ?? [4, 5, 6, 7, 8]);

            if (! in_array($requestedLength, $allowedLengths, true) || $requestedLength < 4 || $requestedLength > 8) {
                $errors[] = __('catalog.validation.invalid_phone_length', ['length' => $requestedLength]);
            }

            if ($rawNumber !== '' && strlen($rawNumber) !== $requestedLength) {
                $errors[] = __('catalog.validation.invalid_phone_length', ['length' => strlen($rawNumber)]);
            }

            $candidateNumber = $rawNumber !== ''
                ? $rawNumber
                : str_pad((string) random_int(10 ** ($requestedLength - 1), (10 ** $requestedLength) - 1), $requestedLength, '0', STR_PAD_LEFT);

            $isAvailable = ! PhoneNumber::query()
                ->where('number', $candidateNumber)
                ->where('status', PhoneNumberStatus::Allocated)
                ->exists() && ! $this->isIdentifierAllocated($candidateNumber);

            if (! $isAvailable && $rawNumber !== '') {
                $errors[] = __('catalog.validation.already_allocated', ['identifier' => $candidateNumber]);
            }

            $pricingConfig = $definition->pricing_config ?? config('service_policies.services.phone_line.pricing_config', []);
            $lengthPrices = $pricingConfig['shortcode_length_prices'] ?? [];
            $basePrice = (string) ($lengthPrices[$requestedLength] ?? $plan?->base_price ?? '150.0000');

            $priceData = $this->ruleEngine->calculateDiscountedPrice($basePrice, $policy);

            return new ServiceValidationResultData(
                isValid: $errors === [],
                isAvailable: $isAvailable,
                normalizedIdentifier: $candidateNumber,
                tierBadge: 'enterprise_shortcode',
                tierLabel: __('catalog.tiers_badges.enterprise_shortcode'),
                unitPrice: $priceData['unit_price'],
                discountAmount: $priceData['discount_amount'],
                finalAmount: $priceData['final_amount'],
                quotaUnits: 1,
                requiresApproval: (bool) $definition->requires_approval_for_enterprise,
                errors: $errors,
                suggestions: ! $isAvailable ? $this->generateSuggestions($candidateNumber) : [],
                metadata: [
                    'allocation_mode' => 'enterprise_shortcode',
                    'requested_length' => $requestedLength,
                    'discount_percent' => $priceData['discount_percent'],
                ]
            );
        }

        // Standard 9-digit allocation mode
        $prefix = isset($payload['prefix_id'])
            ? PhonePrefix::query()->where('is_active', true)->find($payload['prefix_id'])
            : null;

        if (! $prefix) {
            $prefix = PhonePrefix::getDefaultPrefix();
        }

        if ($rawNumber !== '' && strlen($rawNumber) !== 9) {
            $errors[] = __('catalog.validation.invalid_phone_length', ['length' => strlen($rawNumber)]);
        }

        if ($rawNumber === '') {
            $availablePool = PhoneNumber::query()
                ->where('type', PhoneNumberType::UserStandard)
                ->where('status', PhoneNumberStatus::Available)
                ->value('number');

            $rawNumber = $availablePool ?: str_pad((string) random_int(100000, 999999999), 9, '0', STR_PAD_LEFT);
        }

        $isAvailable = ! PhoneNumber::query()
            ->where('number', $rawNumber)
            ->where('status', PhoneNumberStatus::Allocated)
            ->exists() && ! $this->isIdentifierAllocated($rawNumber);

        if (! $isAvailable) {
            $errors[] = __('catalog.validation.already_allocated', ['identifier' => $rawNumber]);
        }

        $rondAnalysis = $this->rondDetector->detectTier($rawNumber);
        $rondTier = $rondAnalysis['tier'] ?? 'regular';

        $pricingConfig = $definition->pricing_config ?? config('service_policies.services.phone_line.pricing_config', []);
        $basePrice = match ($rondTier) {
            'golden_rond' => (string) ($prefix?->golden_rond_price ?? $pricingConfig['golden_rond_base'] ?? '100.0000'),
            'semi_rond' => (string) ($prefix?->semi_rond_price ?? $pricingConfig['semi_rond_base'] ?? '50.0000'),
            default => (string) ($prefix?->regular_price ?? $pricingConfig['standard_9digit_base'] ?? '25.0000'),
        };

        $badge = match ($rondTier) {
            'golden_rond' => 'golden_rond',
            'semi_rond' => 'semi_rond',
            default => 'standard',
        };

        $priceData = $this->ruleEngine->calculateDiscountedPrice($basePrice, $policy);

        return new ServiceValidationResultData(
            isValid: $errors === [],
            isAvailable: $isAvailable,
            normalizedIdentifier: $rawNumber,
            tierBadge: $badge,
            tierLabel: __('catalog.tiers_badges.' . $badge),
            unitPrice: $priceData['unit_price'],
            discountAmount: $priceData['discount_amount'],
            finalAmount: $priceData['final_amount'],
            quotaUnits: 1,
            requiresApproval: false,
            errors: $errors,
            suggestions: ! $isAvailable ? $this->generateSuggestions(substr($rawNumber, 0, 7)) : [],
            metadata: [
                'allocation_mode' => 'standard_9digit',
                'prefix_id' => $prefix?->id,
                'prefix_code' => $prefix?->prefix_code ?? '0900',
                'country_code' => $prefix?->country_code ?? '+98',
                'rond_tier' => $rondTier,
                'rond_pattern' => $rondAnalysis['pattern_name'] ?? null,
                'discount_percent' => $priceData['discount_percent'],
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
        $mode = $validation->metadata['allocation_mode'] ?? 'standard_9digit';
        $displayTitle = trim((string) ($payload['display_title'] ?? '')) ?: $definition->title;

        if ($mode === 'enterprise_shortcode' && $validation->requiresApproval) {
            $requestedLength = (int) ($validation->metadata['requested_length'] ?? strlen($validation->normalizedIdentifier));

            $numberRequest = NumberRequest::create([
                'user_id' => $user->id,
                'requested_length' => $requestedLength,
                'service_title' => $displayTitle,
                'description' => (string) ($payload['description'] ?? $displayTitle),
                'preferred_number' => $validation->normalizedIdentifier,
                'communication_mode' => (string) ($payload['communication_mode'] ?? 'two_way'),
                'tariff_price' => $validation->finalAmount,
                'held_amount' => $order->held_amount,
                'status' => NumberRequestStatus::Pending,
            ]);

            $order->update(['number_request_id' => $numberRequest->id]);

            return null;
        }

        $phoneType = $mode === 'enterprise_shortcode'
            ? PhoneNumberType::UserService
            : PhoneNumberType::UserStandard;

        $phone = PhoneNumber::updateOrCreate(
            ['number' => $validation->normalizedIdentifier],
            [
                'prefix_id' => $validation->metadata['prefix_id'] ?? null,
                'type' => $phoneType,
                'rond_tier' => $validation->metadata['rond_tier'] ?? 'regular',
                'purchase_price' => $validation->finalAmount,
                'is_free_quota' => bccomp($validation->finalAmount, '0.0000', 4) === 0,
                'status' => PhoneNumberStatus::Allocated,
                'display_title' => $displayTitle,
                'communication_mode' => 'two_way',
                'user_id' => $user->id,
                'allocated_at' => now(),
                'released_at' => null,
            ]
        );

        return ServiceResourceAllocation::create([
            'user_id' => $user->id,
            'service_definition_id' => $definition->id,
            'service_order_id' => $order->id,
            'service_key' => $this->serviceKey(),
            'channel_type' => $this->channelType(),
            'resource_identifier' => $phone->number,
            'display_title' => $displayTitle,
            'account_tier' => $order->account_tier,
            'tier_badge' => $validation->tierBadge,
            'status' => 'active',
            'total_quota' => 1,
            'used_quota' => 1,
            'phone_number_id' => $phone->id,
            'capabilities' => [
                'inbound_sms' => true,
                'outbound_sms' => true,
                'webhook_routing' => true,
            ],
            'routing_config' => $this->defaultRoutingConfig(),
            'metadata' => array_merge($validation->metadata, [
                'formatted_number' => $phone->formatted_number,
            ]),
            'allocated_at' => now(),
        ]);
    }

    public function release(User $user, ServiceResourceAllocation $allocation): bool
    {
        if (! parent::release($user, $allocation)) {
            return false;
        }

        if ($allocation->phone_number_id) {
            PhoneNumber::query()
                ->where('id', $allocation->phone_number_id)
                ->update([
                    'status' => PhoneNumberStatus::Available,
                    'user_id' => null,
                    'released_at' => now(),
                ]);
        }

        return true;
    }
}

