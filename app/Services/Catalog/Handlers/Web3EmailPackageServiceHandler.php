<?php

namespace App\Services\Catalog\Handlers;

use App\DTOs\Services\ServiceValidationResultData;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServicePlan;
use App\Models\ServiceResourceAllocation;
use App\Models\ServiceSubResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class Web3EmailPackageServiceHandler extends AbstractServiceHandler
{
    public function serviceKey(): string
    {
        return 'web3_email_package';
    }

    public function channelType(): string
    {
        return 'web3_domain_suite';
    }

    public function validateAndQuote(
        User $user,
        ServiceDefinition $definition,
        array $payload,
        ?ServicePlan $plan = null
    ): ServiceValidationResultData {
        $tier = $this->ruleEngine->resolveTier($user);
        $policy = $this->ruleEngine->resolvePolicy($definition, $tier);

        $rawDomain = strtolower(trim((string) ($payload['base_domain'] ?? $payload['identifier'] ?? '')));
        $tld = strtolower(ltrim(trim((string) ($payload['tld'] ?? 'web3')), '.'));

        // If user entered "goldensource.web3", split out base domain and TLD automatically
        if (str_contains($rawDomain, '.')) {
            $parts = explode('.', $rawDomain, 2);
            $rawDomain = $parts[0];
            if (! empty($parts[1]) && empty($payload['tld'])) {
                $tld = $parts[1];
            }
        }

        if ($plan === null) {
            $planCode = $payload['plan_code'] ?? ($tier === 'enterprise' ? 'web3_ent_pack_100' : 'web3_pack_10');
            $plan = $definition->plans()->where('code', $planCode)->first()
                ?? $definition->plans()->where('target_tier', $tier)->orderBy('sort_order')->first()
                ?? $definition->plans()->orderBy('sort_order')->first();
        }

        $errors = array_merge(
            $this->ruleEngine->assertServiceAccess($user, $definition, $plan),
            $this->ruleEngine->validateQuotaLimit($user, $definition, $policy),
            $this->ruleEngine->validateIdentifierPolicy($rawDomain, $policy, $tier)
        );

        $allowedTlds = array_map('strtolower', (array) ($policy['allowed_tlds'] ?? ['web3', 'trx', 'id3']));
        if (! in_array($tld, $allowedTlds, true)) {
            $errors[] = __('catalog.validation.tld_not_allowed_for_tier', ['tld' => $tld]);
        }

        $quotaUnits = (int) ($payload['quota_units'] ?? $plan?->quota_units ?? 10);
        $allowedQuotas = array_map('intval', (array) ($policy['allowed_package_quotas'] ?? [10, 25]));
        if (! in_array($quotaUnits, $allowedQuotas, true)) {
            $errors[] = __('catalog.validation.package_quota_not_allowed', ['quota' => $quotaUnits]);
        }

        $initialSubs = $this->parseInitialSubAccounts($payload['initial_sub_accounts'] ?? []);
        if (count($initialSubs) > $quotaUnits) {
            $errors[] = __('catalog.validation.sub_accounts_exceed_package', [
                'count' => count($initialSubs),
                'quota' => $quotaUnits,
            ]);
        }

        $subMin = (int) ($policy['sub_account_min_length'] ?? 3);
        $subMax = (int) ($policy['sub_account_max_length'] ?? 32);
        $subPattern = (string) ($policy['sub_account_pattern'] ?? '/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/i');

        foreach ($initialSubs as $sub) {
            if (strlen($sub) < $subMin || strlen($sub) > $subMax || ! preg_match($subPattern, $sub)) {
                $errors[] = __('catalog.validation.sub_account_invalid_format', [
                    'sub' => $sub,
                    'min' => $subMin,
                    'max' => $subMax,
                ]);
            }
        }

        $fullDomain = $rawDomain !== '' ? "{$rawDomain}.{$tld}" : '';
        $isAvailable = $fullDomain !== '' && ! $this->isIdentifierAllocated($fullDomain);

        if ($rawDomain !== '' && ! $isAvailable) {
            $errors[] = __('catalog.validation.already_allocated', ['identifier' => $fullDomain]);
        }

        $pricingConfig = $definition->pricing_config ?? config('service_policies.services.web3_email_package.pricing_config', []);
        $basePrice = (string) ($plan?->base_price ?? '60.0000');

        $tldSurcharge = (string) ($pricingConfig['premium_tld_surcharge'][$tld] ?? '0.0000');
        $basePrice = bcadd($basePrice, $tldSurcharge, 4);

        if ($rawDomain !== '' && strlen($rawDomain) <= 5) {
            $shortSurcharge = (string) ($pricingConfig['short_domain_surcharge_3_to_5'] ?? '90.0000');
            $basePrice = bcadd($basePrice, $shortSurcharge, 4);
        }

        $tierBadge = (in_array($tld, ['dao', 'org3', 'corp'], true) || strlen($rawDomain) <= 5 || $quotaUnits >= 50)
            ? 'web3_premium'
            : 'web3_standard';

        $priceData = $this->ruleEngine->calculateDiscountedPrice($basePrice, $policy);

        return new ServiceValidationResultData(
            isValid: $errors === [],
            isAvailable: $isAvailable,
            normalizedIdentifier: $fullDomain,
            tierBadge: $tierBadge,
            tierLabel: __('catalog.tiers_badges.' . $tierBadge),
            unitPrice: $priceData['unit_price'],
            discountAmount: $priceData['discount_amount'],
            finalAmount: $priceData['final_amount'],
            quotaUnits: $quotaUnits,
            requiresApproval: false,
            errors: $errors,
            suggestions: ($rawDomain !== '' && ! $isAvailable)
                ? $this->generateSuggestions($rawDomain, '', '.' . $tld)
                : [],
            metadata: [
                'base_domain' => $rawDomain,
                'tld' => $tld,
                'full_domain' => $fullDomain,
                'plan_code' => $plan?->code,
                'quota_units' => $quotaUnits,
                'initial_sub_accounts' => $initialSubs,
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
        $initialSubs = (array) ($validation->metadata['initial_sub_accounts'] ?? []);

        $allocation = ServiceResourceAllocation::create([
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
            'total_quota' => $validation->quotaUnits,
            'used_quota' => 0,
            'capabilities' => [
                'web3_domain_resolution' => true,
                'sub_account_provisioning' => true,
                'inbound_mail_routing' => true,
                'outbound_mail_dispatch' => true,
                'webhook_routing' => true,
            ],
            'routing_config' => array_merge($this->defaultRoutingConfig(), [
                'catch_all_enabled' => false,
                'web3_namespace' => $validation->metadata['tld'] ?? 'web3',
            ]),
            'metadata' => $validation->metadata,
            'allocated_at' => now(),
        ]);

        foreach ($initialSubs as $subIdentifier) {
            $this->createSubAccount($user, $allocation, $subIdentifier, strtoupper($subIdentifier) . ' Mailbox');
        }

        return $allocation->fresh(['subResources']);
    }

    /**
     * Allocate a new sub-account under an existing Web3 domain allocation while enforcing Quota rules.
     */
    public function createSubAccount(
        User $user,
        ServiceResourceAllocation $allocation,
        string $subIdentifier,
        ?string $displayName = null
    ): ServiceSubResource {
        $cleanSub = strtolower(trim(explode('@', $subIdentifier)[0]));
        $tier = $this->ruleEngine->resolveTier($user);
        $definition = $allocation->serviceDefinition;
        $policy = $this->ruleEngine->resolvePolicy($definition, $tier);

        $subMin = (int) ($policy['sub_account_min_length'] ?? 3);
        $subMax = (int) ($policy['sub_account_max_length'] ?? 32);
        $subPattern = (string) ($policy['sub_account_pattern'] ?? '/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/i');

        if (strlen($cleanSub) < $subMin || strlen($cleanSub) > $subMax || ! preg_match($subPattern, $cleanSub)) {
            throw ValidationException::withMessages([
                'sub_identifier' => [
                    __('catalog.validation.sub_account_invalid_format', [
                        'sub' => $cleanSub,
                        'min' => $subMin,
                        'max' => $subMax,
                    ]),
                ],
            ]);
        }

        if (! $allocation->hasAvailableQuota(1)) {
            throw ValidationException::withMessages([
                'sub_identifier' => [
                    __('catalog.validation.sub_account_quota_exhausted', [
                        'used' => $allocation->used_quota,
                        'total' => $allocation->total_quota,
                    ]),
                ],
            ]);
        }

        $fullAddress = "{$cleanSub}@{$allocation->resource_identifier}";

        if (ServiceSubResource::query()->where('full_address', $fullAddress)->exists()) {
            throw ValidationException::withMessages([
                'sub_identifier' => [
                    __('catalog.validation.sub_account_duplicate', ['address' => $fullAddress]),
                ],
            ]);
        }

        $subResource = ServiceSubResource::create([
            'parent_allocation_id' => $allocation->id,
            'user_id' => $user->id,
            'sub_identifier' => $cleanSub,
            'full_address' => $fullAddress,
            'display_name' => $displayName ?: $fullAddress,
            'status' => 'active',
            'routing_metadata' => [
                'domain' => $allocation->resource_identifier,
                'inbox_enabled' => true,
                'phase2_ready' => true,
            ],
            'metadata' => [
                'created_under_tier' => $tier,
            ],
        ]);

        $allocation->increment('used_quota');

        return $subResource;
    }

    /**
     * Remove a sub-account and restore 1 quota slot to the parent Web3 domain.
     */
    public function deleteSubAccount(
        User $user,
        ServiceResourceAllocation $allocation,
        ServiceSubResource $subResource
    ): void {
        if ($subResource->parent_allocation_id !== $allocation->id) {
            return;
        }

        $subResource->delete();

        if ($allocation->used_quota > 0) {
            $allocation->decrement('used_quota');
        }
    }

    /**
     * Parse comma-separated or array input of initial sub-account usernames.
     *
     * @return array<int, string>
     */
    protected function parseInitialSubAccounts(mixed $raw): array
    {
        if (is_string($raw)) {
            $items = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        } elseif (is_array($raw)) {
            $items = $raw;
        } else {
            $items = [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $clean = strtolower(trim(explode('@', (string) $item)[0]));
            if ($clean !== '' && ! in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }
}

