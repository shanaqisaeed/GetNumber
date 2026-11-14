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
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Numbers\NumberSearchAndSuggestionService;
use App\Services\Numbers\RondPatternDetectorService;

class PhoneLineServiceHandler extends AbstractServiceHandler
{
    public function __construct(
        BusinessRuleEngineInterface $ruleEngine,
        protected RondPatternDetectorService $rondDetector,
        protected ?NumberSearchAndSuggestionService $searchService = null
    ) {
        parent::__construct($ruleEngine);
        $this->searchService = $searchService ?? app(NumberSearchAndSuggestionService::class);
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
            $requestedLength = (int) ($payload['requested_length'] ?? ($plan?->metadata['digits'] ?? (strlen($rawNumber) ?: 6)));
            $allowedLengths = (array) ($policy['allowed_lengths'] ?? [4, 5, 6, 7, 8]);

            if (! in_array($requestedLength, $allowedLengths, true) || $requestedLength < 4 || $requestedLength > 8) {
                $errors[] = __('catalog.validation.invalid_phone_length', ['length' => $requestedLength]);
            }

            if ($rawNumber !== '' && strlen($rawNumber) !== $requestedLength) {
                $errors[] = __('catalog.validation.invalid_phone_length', ['length' => strlen($rawNumber)]);
            }

            $shortcodeSuggestions = $this->searchService->getShortcodeSuggestions(
                prefixId: isset($payload['prefix_id']) ? (int) $payload['prefix_id'] : null,
                length: $requestedLength,
                seedNumber: $rawNumber !== '' ? $rawNumber : null,
                limit: 6
            );

            $candidateNumber = $rawNumber !== ''
                ? $rawNumber
                : (($shortcodeSuggestions[0]['number'] ?? null) ?: str_pad((string) random_int(10 ** ($requestedLength - 1), (10 ** $requestedLength) - 1), $requestedLength, '0', STR_PAD_LEFT));

            $isAvailable = ! $this->searchService->isNumberTaken($candidateNumber)
                && ! $this->isIdentifierAllocated($candidateNumber);

            if (! $isAvailable && $rawNumber !== '') {
                $errors[] = __('catalog.validation.already_allocated', ['identifier' => $candidateNumber]);
            }

            $rondAnalysis = $this->rondDetector->detect($candidateNumber);
            $pricingConfig = $definition->pricing_config ?? config('service_policies.services.phone_line.pricing_config', []);
            $lengthPrices = $pricingConfig['shortcode_length_prices'] ?? [];
            $basePrice = (string) ($lengthPrices[$requestedLength]
                ?? SystemSetting::getOrgNumberPrice($requestedLength, (bool) ($rondAnalysis['is_rond'] ?? false))
                ?? $plan?->base_price
                ?? '150.0000');

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
                suggestions: array_map(fn ($item) => $item['number'], $shortcodeSuggestions),
                metadata: [
                    'allocation_mode' => 'enterprise_shortcode',
                    'requested_length' => $requestedLength,
                    'rond_tier' => $rondAnalysis['tier'] ?? 'regular',
                    'rond_pattern' => $rondAnalysis['pattern'] ?? null,
                    'discount_percent' => $priceData['discount_percent'],
                    'rich_suggestions' => $shortcodeSuggestions,
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

        $poolSuggestions = $this->searchService->getPoolSuggestions(
            prefixId: $prefix?->id ?? 1,
            subPrefix: strlen($rawNumber) >= 3 ? substr($rawNumber, 0, 3) : null,
            limit: 6
        );

        if ($rawNumber === '') {
            $rawNumber = ($poolSuggestions[0]['number'] ?? null)
                ?: str_pad((string) random_int(910000000, 999999999), 9, '0', STR_PAD_LEFT);
        }

        $isAvailable = ! $this->searchService->isNumberTaken($rawNumber)
            && ! $this->isIdentifierAllocated($rawNumber);

        if (! $isAvailable) {
            $errors[] = __('catalog.validation.already_allocated', ['identifier' => $rawNumber]);
        }

        $rondAnalysis = $this->rondDetector->detect($rawNumber);
        $rondTier = $rondAnalysis['tier'] ?? 'regular';

        $pricingConfig = $definition->pricing_config ?? config('service_policies.services.phone_line.pricing_config', []);
        $basePrice = match ($rondTier) {
            'golden_rond' => (string) ($prefix?->price_golden_rond ?? $pricingConfig['golden_rond_base'] ?? '100.0000'),
            'semi_rond' => (string) ($prefix?->price_semi_rond ?? $pricingConfig['semi_rond_base'] ?? '50.0000'),
            default => (string) ($prefix?->price_regular ?? $pricingConfig['standard_9digit_base'] ?? '25.0000'),
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
            suggestions: array_map(fn ($item) => $item['number'], $poolSuggestions),
            metadata: [
                'allocation_mode' => 'standard_9digit',
                'prefix_id' => $prefix?->id,
                'prefix_code' => $prefix?->prefix ?? '+85',
                'country_code' => $prefix?->country_code ?? 'IR',
                'rond_tier' => $rondTier,
                'rond_pattern' => $rondAnalysis['pattern'] ?? null,
                'discount_percent' => $priceData['discount_percent'],
                'rich_suggestions' => $poolSuggestions,
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
                'phone_prefix_id' => $validation->metadata['prefix_id'] ?? PhonePrefix::getDefaultPrefix()?->id,
                'requested_length' => $requestedLength,
                'service_title' => $displayTitle,
                'description' => (string) ($payload['description'] ?? $displayTitle),
                'preferred_number' => $validation->normalizedIdentifier,
                'is_rond' => ($validation->metadata['rond_tier'] ?? 'regular') !== 'regular',
                'rond_tier' => $validation->metadata['rond_tier'] ?? 'regular',
                'accepts_incoming_sms' => ($payload['communication_mode'] ?? 'two_way') === 'two_way',
                'held_amount' => $order->held_amount ?: $validation->finalAmount,
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
                'phone_prefix_id' => $validation->metadata['prefix_id'] ?? PhonePrefix::getDefaultPrefix()?->id,
                'type' => $phoneType,
                'rond_tier' => $validation->metadata['rond_tier'] ?? 'regular',
                'rond_pattern' => $validation->metadata['rond_pattern'] ?? null,
                'status' => PhoneNumberStatus::Allocated,
                'display_title' => $displayTitle,
                'accepts_incoming_sms' => true,
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
