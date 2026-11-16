<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\ServiceDefinition;
use App\Models\ServiceOrder;
use App\Models\ServiceResourceAllocation;
use App\Models\TldPricing;
use App\Models\Web3Domain;
use App\Services\Numbers\IdRarityEngine;
use App\Services\Numbers\RondPatternDetectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidationEngineController extends Controller
{
    public function __construct(
        protected IdRarityEngine $idRarityEngine,
        protected RondPatternDetectorService $rondDetector
    ) {}

    /**
     * Live AJAX validation for Short Lines (سرشماره‌های کوتاه).
     */
    public function validateShortLine(Request $request): JsonResponse
    {
        $length = (int) ($request->input('requested_length') ?? $request->input('length') ?? 6);
        $preferredNumber = trim((string) ($request->input('preferred_number') ?? $request->input('number') ?? ''));
        $prefixId = $request->input('phone_prefix_id') ?? $request->input('prefix_id');

        if (! in_array($length, [4, 5, 6, 7, 8, 9], true)) {
            return response()->json([
                'available' => false,
                'is_available' => false,
                'message' => __('controllers.api.validation_engine.short_line_length_range'),
            ], 422);
        }

        /** @var \App\Services\Numbers\NumberSearchAndSuggestionService $searchService */
        $searchService = app(\App\Services\Numbers\NumberSearchAndSuggestionService::class);

        if ($preferredNumber !== '') {
            if (! preg_match('/^[0-9]+$/', $preferredNumber)) {
                return response()->json([
                    'available' => false,
                    'is_available' => false,
                    'message' => __('controllers.api.validation_engine.short_line_digits_only'),
                ], 422);
            }

            if (strlen($preferredNumber) !== $length) {
                return response()->json([
                    'available' => false,
                    'is_available' => false,
                    'message' => __('controllers.api.validation_engine.short_line_exact_length', [
                        'length' => $length,
                        'current' => strlen($preferredNumber),
                    ]),
                ], 422);
            }

            $checkResult = $searchService->checkAndSuggestShortcode(
                prefixId: $prefixId ? (int) $prefixId : null,
                length: $length,
                preferredNumber: $preferredNumber
            );

            if (! $checkResult['is_available']) {
                return response()->json([
                    'available' => false,
                    'is_available' => false,
                    'requested_length' => $length,
                    'preferred_number' => $preferredNumber,
                    'message' => $checkResult['message'] ?? __('controllers.api.validation_engine.short_line_taken'),
                    'suggestions' => $checkResult['suggestions'] ?? [],
                    'base_price' => $checkResult['price'] ?? 100.0,
                    'final_price' => $checkResult['price'] ?? 100.0,
                    'is_rond' => $checkResult['is_rond'] ?? false,
                    'rond_tier' => $checkResult['rond_tier'] ?? 'regular',
                    'rond_tier_label' => $checkResult['rond_tier_label'] ?? __('controllers.api.validation_engine.rond_regular'),
                ]);
            }

            return response()->json([
                'available' => true,
                'is_available' => true,
                'requested_length' => $length,
                'preferred_number' => $preferredNumber,
                'formatted_number' => $checkResult['formatted_number'] ?? $preferredNumber,
                'rond_tier' => $checkResult['rond_tier'] ?? 'regular',
                'rond_tier_label' => $checkResult['rond_tier_label'] ?? __('controllers.api.validation_engine.rond_regular'),
                'pattern_name' => $checkResult['rond_pattern'] ?? __('controllers.api.validation_engine.pattern_standard'),
                'base_price' => $checkResult['price'] ?? 100.0,
                'final_price' => $checkResult['price'] ?? 100.0,
                'currency' => 'COIN',
                'message' => __('controllers.api.validation_engine.short_line_available'),
            ]);
        }

        // Base price calculation based on length (Unified Pricing)
        $lengthPrices = [
            4 => 500.0000,
            5 => 300.0000,
            6 => 150.0000,
            7 => 80.0000,
            8 => 40.0000,
            9 => 25.0000,
        ];
        $basePrice = $lengthPrices[$length] ?? 100.0000;

        return response()->json([
            'available' => true,
            'is_available' => true,
            'requested_length' => $length,
            'preferred_number' => '',
            'rond_tier' => 'regular',
            'rond_tier_label' => __('controllers.api.validation_engine.rond_regular'),
            'pattern_name' => __('controllers.api.validation_engine.pattern_smart_random'),
            'base_price' => $basePrice,
            'final_price' => $basePrice,
            'currency' => 'COIN',
            'message' => __('controllers.api.validation_engine.short_line_length_valid'),
        ]);
    }

    /**
     * Live AJAX validation for Organizational IDs & Custom Handles.
     */
    public function validateCustomId(Request $request): JsonResponse
    {
        $handle = strtolower(trim((string) $request->input('handle', '')));
        $cleanHandle = preg_replace('/^@/', '', $handle);

        if ($cleanHandle === '') {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.id_required'),
            ], 422);
        }

        $context = $request->input('context') ?? $request->input('order_context') ?? 'services';
        $minLen = ($context === 'organizational' || $context === 'enterprise') ? 4 : 6;

        if (strlen($cleanHandle) < $minLen || strlen($cleanHandle) > 32) {
            $msg = $minLen === 6
                ? __('controllers.api.validation_engine.id_length_individual')
                : __('controllers.api.validation_engine.id_length_enterprise');

            return response()->json([
                'available' => false,
                'is_available' => false,
                'message' => $msg,
            ], 422);
        }

        if (! preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/i', $cleanHandle) && strlen($cleanHandle) > 1) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.id_invalid_format'),
            ], 422);
        }

        if (str_contains($cleanHandle, '__')) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.id_consecutive_underscores'),
            ], 422);
        }

        // Reserved identifiers check
        $reserved = ['admin', 'administrator', 'system', 'root', 'support', 'security', 'getnumber', 'official', 'billing', 'wallet'];
        if (in_array($cleanHandle, $reserved, true)) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.id_reserved'),
            ]);
        }

        // Check availability in allocated resources
        $formattedHandle = "@{$cleanHandle}";
        $isTaken = ServiceResourceAllocation::where('service_key', 'custom_id')
            ->where(function ($q) use ($cleanHandle, $formattedHandle) {
                $q->where('resource_identifier', $cleanHandle)
                  ->orWhere('resource_identifier', $formattedHandle);
            })
            ->where('status', 'active')
            ->exists();

        if ($isTaken) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.id_taken', ['handle' => $formattedHandle]),
            ]);
        }

        // Check pending orders
        $isPending = ServiceOrder::where(function ($q) use ($cleanHandle, $formattedHandle) {
                $q->where('requested_identifier', $cleanHandle)
                  ->orWhere('requested_identifier', $formattedHandle);
            })
            ->whereIn('status', ['pending', 'held', 'reviewing'])
            ->exists();

        if ($isPending) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.id_pending', ['handle' => $formattedHandle]),
            ]);
        }

        // Resolve base price by length and rarity from custom_id ServiceDefinition
        $idDef = ServiceDefinition::where('key', 'custom_id')->first();
        $pricingConfig = $idDef?->pricing_config ?? [];
        $len = strlen($cleanHandle);

        if ($len <= 4) {
            $basePrice = (float) ($pricingConfig['price_4_char'] ?? $pricingConfig['enterprise_short_4_price'] ?? 240.0000);
        } elseif ($len === 5) {
            $basePrice = (float) ($pricingConfig['price_5_char'] ?? $pricingConfig['enterprise_short_5_price'] ?? 140.0000);
        } else {
            $basePrice = (float) ($pricingConfig['price_6_plus_char'] ?? $pricingConfig['regular'] ?? 30.0000);
        }

        $rarity = $this->idRarityEngine->analyze($cleanHandle, $basePrice);

        // Apply custom rarity multiplier if configured
        if ($rarity['tier'] === 'golden_rond' && ! empty($pricingConfig['multiplier_golden_rond'])) {
            $rarity['multiplier'] = (float) $pricingConfig['multiplier_golden_rond'];
            $rarity['calculated_price'] = (float) bcmul((string) $basePrice, (string) $rarity['multiplier'], 4);
        } elseif ($rarity['tier'] === 'semi_rond' && ! empty($pricingConfig['multiplier_semi_rond'])) {
            $rarity['multiplier'] = (float) $pricingConfig['multiplier_semi_rond'];
            $rarity['calculated_price'] = (float) bcmul((string) $basePrice, (string) $rarity['multiplier'], 4);
        }

        return response()->json([
            'available' => true,
            'handle' => $formattedHandle,
            'clean_handle' => $cleanHandle,
            'tier' => $rarity['tier'],
            'tier_label' => $rarity['tier_label'],
            'tier_badge' => $rarity['tier_badge'],
            'pattern_name' => $rarity['pattern_name'],
            'multiplier' => $rarity['multiplier'],
            'base_price' => $basePrice,
            'final_price' => (string) $rarity['calculated_price'],
            'price' => (string) $rarity['calculated_price'],
            'currency' => 'COIN',
            'message' => __('controllers.api.validation_engine.id_available', ['handle' => $formattedHandle]),
        ]);
    }

    /**
     * Live AJAX validation for Web3 Domains.
     */
    public function validateWeb3Domain(Request $request): JsonResponse
    {
        $domainName = strtolower(trim((string) $request->input('domain_name', '')));
        $tld = strtolower(trim((string) $request->input('tld', 'web3')));
        $cleanTld = ltrim($tld, '.');

        if ($domainName === '') {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_required'),
            ], 422);
        }

        if (strlen($domainName) < 3 || strlen($domainName) > 40) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_length'),
            ], 422);
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/i', $domainName)) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_invalid_format'),
            ], 422);
        }

        if (str_contains($domainName, '--')) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_consecutive_hyphens'),
            ], 422);
        }

        // Check TLD in tld_pricing
        $tldRecord = TldPricing::where('tld', $cleanTld)->where('is_active', true)->first();
        if (! $tldRecord) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.tld_inactive', ['tld' => $cleanTld]),
            ], 422);
        }

        $fullDomain = "{$domainName}.{$cleanTld}";

        // Reserved Web3 names
        $reserved = ['admin', 'system', 'root', 'support', 'tron', 'ethereum', 'foundation', 'getnumber'];
        if (in_array($domainName, $reserved, true)) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_reserved', ['domain' => $fullDomain]),
            ]);
        }

        // Check if already registered
        $isTaken = Web3Domain::where('domain_name', $domainName)
            ->where('tld', $cleanTld)
            ->where('status', 'active')
            ->exists();

        if ($isTaken) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_taken', ['domain' => $fullDomain]),
            ]);
        }

        // Check pending orders
        $isPending = ServiceOrder::where('requested_identifier', $fullDomain)
            ->whereIn('status', ['pending', 'held', 'reviewing'])
            ->exists();

        if ($isPending) {
            return response()->json([
                'available' => false,
                'message' => __('controllers.api.validation_engine.domain_pending', ['domain' => $fullDomain]),
            ]);
        }

        // Calculate pricing based on TLD and rarity
        $basePrice = (float) $tldRecord->base_price;
        $rarity = $this->idRarityEngine->analyze($domainName, $basePrice);

        $multiplier = 1.0;
        if ($rarity['tier'] === IdRarityEngine::TIER_GOLDEN) {
            $multiplier = (float) $tldRecord->multiplier_rond;
        } elseif ($rarity['tier'] === IdRarityEngine::TIER_SEMI) {
            $multiplier = (float) $tldRecord->multiplier_semi_rond;
        }

        $finalPrice = (float) bcmul((string) $basePrice, (string) $multiplier, 4);

        return response()->json([
            'available' => true,
            'full_domain' => $fullDomain,
            'domain_name' => $domainName,
            'tld' => $cleanTld,
            'tier' => $rarity['tier'],
            'tier_label' => $rarity['tier_label'],
            'tier_badge' => $rarity['tier_badge'],
            'pattern_name' => $rarity['pattern_name'],
            'base_price' => $basePrice,
            'multiplier' => $multiplier,
            'final_price' => $finalPrice,
            'max_sub_emails' => 100,
            'currency' => 'COIN',
            'message' => __('controllers.api.validation_engine.domain_available', ['domain' => $fullDomain]),
        ]);
    }

    /**
     * Get active Web3 domain TLDs.
     */
    public function activeTlds(): JsonResponse
    {
        $tlds = TldPricing::where('is_active', true)
            ->get(['id', 'tld', 'base_price']);

        return response()->json([
            'tlds' => $tlds,
        ]);
    }
}

