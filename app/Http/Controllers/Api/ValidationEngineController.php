<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
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
                'message' => 'طول سرشماره باید بین ۴ تا ۹ رقم باشد.',
            ], 422);
        }

        /** @var \App\Services\Numbers\NumberSearchAndSuggestionService $searchService */
        $searchService = app(\App\Services\Numbers\NumberSearchAndSuggestionService::class);

        if ($preferredNumber !== '') {
            if (! preg_match('/^[0-9]+$/', $preferredNumber)) {
                return response()->json([
                    'available' => false,
                    'is_available' => false,
                    'message' => 'شماره درخواستی فقط باید شامل ارقام انگلیسی باشد.',
                ], 422);
            }

            if (strlen($preferredNumber) !== $length) {
                return response()->json([
                    'available' => false,
                    'is_available' => false,
                    'message' => "طول شماره درخواستی باید دقیقاً {$length} رقم باشد (اکنون " . strlen($preferredNumber) . " رقم است).",
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
                    'message' => $checkResult['message'] ?? 'این سرشماره قبلاً به مشترک دیگری واگذار شده و در دسترس نیست.',
                    'suggestions' => $checkResult['suggestions'] ?? [],
                    'base_price' => $checkResult['price'] ?? 100.0,
                    'final_price' => $checkResult['price'] ?? 100.0,
                    'is_rond' => $checkResult['is_rond'] ?? false,
                    'rond_tier' => $checkResult['rond_tier'] ?? 'regular',
                    'rond_tier_label' => $checkResult['rond_tier_label'] ?? 'عادی',
                ]);
            }

            return response()->json([
                'available' => true,
                'is_available' => true,
                'requested_length' => $length,
                'preferred_number' => $preferredNumber,
                'formatted_number' => $checkResult['formatted_number'] ?? $preferredNumber,
                'rond_tier' => $checkResult['rond_tier'] ?? 'regular',
                'rond_tier_label' => $checkResult['rond_tier_label'] ?? 'عادی',
                'pattern_name' => $checkResult['rond_pattern'] ?? 'الگوی استاندارد',
                'base_price' => $checkResult['price'] ?? 100.0,
                'final_price' => $checkResult['price'] ?? 100.0,
                'currency' => 'COIN',
                'message' => 'سرشماره انتخابی در دسترس بوده و امکان ثبت سفارش وجود دارد.',
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
            'rond_tier_label' => 'عادی',
            'pattern_name' => 'تخصیص هوشمند رندوم',
            'base_price' => $basePrice,
            'final_price' => $basePrice,
            'currency' => 'COIN',
            'message' => 'طول سرشماره معتبر است. در صورت تمایل شماره دلخواه را وارد کنید.',
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
                'message' => 'لطفاً آیدی مورد نظر خود را وارد نمایید.',
            ], 422);
        }

        if (strlen($cleanHandle) < 3 || strlen($cleanHandle) > 32) {
            return response()->json([
                'available' => false,
                'message' => 'طول آیدی باید بین ۳ تا ۳۲ کاراکتر باشد.',
            ], 422);
        }

        if (! preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/i', $cleanHandle) && strlen($cleanHandle) > 1) {
            return response()->json([
                'available' => false,
                'message' => 'آیدی باید با حروف انگلیسی آغاز شده و فقط شامل حروف، ارقام و خط زیر باشد.',
            ], 422);
        }

        if (str_contains($cleanHandle, '__')) {
            return response()->json([
                'available' => false,
                'message' => 'استفاده از علامت‌های خط زیر متوالی مجاز نمی‌باشد.',
            ], 422);
        }

        // Reserved identifiers check
        $reserved = ['admin', 'administrator', 'system', 'root', 'support', 'security', 'getnumber', 'official', 'billing', 'wallet'];
        if (in_array($cleanHandle, $reserved, true)) {
            return response()->json([
                'available' => false,
                'message' => 'این آیدی در زمره شناسه‌های سیستمی و مسدودشده سامانه می‌باشد.',
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
                'message' => "آیدی {$formattedHandle} قبلاً ثبت شده است و در دسترس نمی‌باشد.",
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
                'message' => "آیدی {$formattedHandle} در حال حاضر توسط کاربر دیگری در دست بررسی است.",
            ]);
        }

        // Analyze Rarity
        $basePrice = 30.0000;
        $rarity = $this->idRarityEngine->analyze($cleanHandle, $basePrice);

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
            'final_price' => $rarity['calculated_price'],
            'currency' => 'COIN',
            'message' => "آیدی {$formattedHandle} آزاد و آماده ثبت سفارش می‌باشد.",
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
                'message' => 'نام دامنه نمی‌تواند خالی باشد.',
            ], 422);
        }

        if (strlen($domainName) < 3 || strlen($domainName) > 40) {
            return response()->json([
                'available' => false,
                'message' => 'طول نام دامنه باید بین ۳ تا ۴۰ کاراکتر باشد.',
            ], 422);
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/i', $domainName)) {
            return response()->json([
                'available' => false,
                'message' => 'نام دامنه فقط می‌تواند شامل حروف انگلیسی، اعداد و خط تیره (-) باشد.',
            ], 422);
        }

        if (str_contains($domainName, '--')) {
            return response()->json([
                'available' => false,
                'message' => 'استفاده از خط تیره‌های متوالی مجاز نیست.',
            ], 422);
        }

        // Check TLD in tld_pricing
        $tldRecord = TldPricing::where('tld', $cleanTld)->where('is_active', true)->first();
        if (! $tldRecord) {
            return response()->json([
                'available' => false,
                'message' => "پسوند انتخابی (.{$cleanTld}) در سامانه فعال نمی‌باشد.",
            ], 422);
        }

        $fullDomain = "{$domainName}.{$cleanTld}";

        // Reserved Web3 names
        $reserved = ['admin', 'system', 'root', 'support', 'tron', 'ethereum', 'foundation', 'getnumber'];
        if (in_array($domainName, $reserved, true)) {
            return response()->json([
                'available' => false,
                'message' => "دامنه {$fullDomain} از دامنه‌های رزروشده بنیاد سامانه می‌باشد.",
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
                'message' => "دامنه {$fullDomain} قبلاً ثبت شده و در دسترس نیست.",
            ]);
        }

        // Check pending orders
        $isPending = ServiceOrder::where('requested_identifier', $fullDomain)
            ->whereIn('status', ['pending', 'held', 'reviewing'])
            ->exists();

        if ($isPending) {
            return response()->json([
                'available' => false,
                'message' => "دامنه {$fullDomain} در دست بررسی سفارش دیگری قرار دارد.",
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
            'message' => "دامنه {$fullDomain} آزاد و قابل ثبت است.",
        ]);
    }
}

