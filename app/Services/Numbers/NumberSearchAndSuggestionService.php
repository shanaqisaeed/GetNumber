<?php

namespace App\Services\Numbers;

use App\Enums\NumberRequestStatus;
use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\NumberRequest;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\ServiceOrder;
use App\Models\ServiceResourceAllocation;
use App\Models\SystemSetting;
use App\Models\User;

class NumberSearchAndSuggestionService
{
    public function __construct(
        protected RondPatternDetectorService $rondDetector
    ) {}

    /**
     * Authoritative global check whether a number is already registered/taken/held in the platform.
     * Checks phone_numbers (optionally scoped by prefix), pending/approved number_requests,
     * active service_resource_allocations, and pending/completed service_orders.
     */
    public function isNumberTaken(string $cleanNumber, ?int $prefixId = null): bool
    {
        $digits = preg_replace('/\D/', '', $cleanNumber);
        if ($digits === '') {
            return false;
        }

        // 1. Check phone_numbers
        $phoneQuery = PhoneNumber::query()->where('number', $digits);
        if ($prefixId) {
            $phoneQuery->where(function ($q) use ($prefixId) {
                $q->where('phone_prefix_id', $prefixId)->orWhereNull('phone_prefix_id');
            });
        }
        $phoneExists = $phoneQuery->where(function ($q) {
            $q->whereNotNull('user_id')
              ->orWhere('status', '!=', PhoneNumberStatus::Available);
        })->exists();

        if ($phoneExists) {
            return true;
        }

        // 2. Check pending or approved shortcode requests
        $requestQuery = NumberRequest::query()->where('preferred_number', $digits);
        if ($prefixId) {
            $requestQuery->where(function ($q) use ($prefixId) {
                $q->where('phone_prefix_id', $prefixId)->orWhereNull('phone_prefix_id');
            });
        }
        $requestExists = $requestQuery->whereIn('status', [NumberRequestStatus::Pending, NumberRequestStatus::Approved])
            ->exists();

        if ($requestExists) {
            return true;
        }

        // 3. Check active service resource allocations
        $allocationExists = ServiceResourceAllocation::query()
            ->where('service_key', 'phone_line')
            ->where('resource_identifier', $digits)
            ->where('status', 'active')
            ->exists();

        if ($allocationExists) {
            return true;
        }

        // 4. Check pending/held/active service orders
        $orderExists = ServiceOrder::query()
            ->where('requested_identifier', $digits)
            ->whereIn('status', ['pending', 'pending_approval', 'held', 'reviewing', 'approved', 'active'])
            ->exists();

        return $orderExists;
    }

    /**
     * Check availability of a specific 9-digit number under a prefix,
     * calculate its rond tier & price, and return up to 6 smart suggestions if unavailable.
     */
    public function checkAndSuggest(
        int $prefixId,
        string $subPrefix,
        string $suffix,
        ?User $user = null
    ): array {
        /** @var PhonePrefix|null $prefix */
        $prefix = PhonePrefix::find($prefixId) ?? PhonePrefix::getDefaultPrefix();

        $cleanSub = preg_replace('/\D/', '', $subPrefix);
        $cleanSuffix = preg_replace('/\D/', '', $suffix);
        $fullNumber = $cleanSub . $cleanSuffix;

        $rond = $this->rondDetector->detect($fullNumber, $cleanSuffix);
        $price = $prefix->getPriceForTier($rond['tier']);

        $isAvailable = ! $this->isNumberTaken($fullNumber);

        $suggestions = [];
        if (! $isAvailable) {
            $suggestions = $this->generateSmartSuggestions($prefix, $cleanSub, $cleanSuffix, 6);
        }

        return [
            'is_available' => $isAvailable,
            'number' => $fullNumber,
            'formatted' => "{$prefix->prefix} {$fullNumber}",
            'formatted_number' => "{$prefix->prefix} {$fullNumber}",
            'prefix' => [
                'id' => $prefix->id,
                'prefix' => $prefix->prefix,
                'country_name' => $prefix->country_name ?? $prefix->name,
                'flag' => $prefix->flag ?? '🌐',
            ],
            'sub_prefix' => $cleanSub,
            'suffix' => $cleanSuffix,
            'rond' => $rond,
            'rond_tier' => $rond['tier'],
            'rond_tier_label' => $rond['tier_label'],
            'rond_pattern' => $rond['pattern'],
            'is_rond' => $rond['is_rond'],
            'price' => $price,
            'suggestions' => $suggestions,
            'message' => $isAvailable
                ? null
                : __('services.numbers.search.message_number_allocated', ['number' => "{$prefix->prefix} {$fullNumber}"]),
        ];
    }

    /**
     * Check availability of an Enterprise Shortcode (4 to 8 digits) and return smart suggestions.
     */
    public function checkAndSuggestShortcode(
        ?int $prefixId,
        int $length,
        string $preferredNumber
    ): array {
        $prefix = ($prefixId ? PhonePrefix::find($prefixId) : null) ?? PhonePrefix::getDefaultPrefix();
        $cleanNumber = preg_replace('/\D/', '', $preferredNumber);
        $length = max(4, min(8, $length ?: strlen($cleanNumber)));

        $rond = $this->rondDetector->detect($cleanNumber);
        $price = SystemSetting::getOrgNumberPrice($length, $rond['is_rond']);

        $isLengthValid = strlen($cleanNumber) === $length;
        $isAvailable = $isLengthValid && ! $this->isNumberTaken($cleanNumber, $prefix?->id);

        $suggestions = $this->getShortcodeSuggestions(
            prefixId: $prefix?->id,
            length: $length,
            seedNumber: $cleanNumber,
            limit: 6
        );

        return [
            'is_available' => $isAvailable,
            'is_length_valid' => $isLengthValid,
            'number' => $cleanNumber,
            'formatted_number' => $prefix ? "{$prefix->prefix} {$cleanNumber}" : $cleanNumber,
            'length' => $length,
            'rond_tier' => $rond['tier'],
            'rond_tier_label' => $rond['tier_label'],
            'rond_pattern' => $rond['pattern'],
            'is_rond' => $rond['is_rond'],
            'price' => $price,
            'suggestions' => $suggestions,
            'message' => $isAvailable
                ? null
                : __('services.numbers.search.message_number_allocated', ['number' => $cleanNumber]),
        ];
    }

    /**
     * Get rich shortcode suggestions for Enterprise requests (lengths 4..8).
     * Combines available UserService pool numbers with dynamically generated free rond & regular shortcodes.
     */
    public function getShortcodeSuggestions(
        ?int $prefixId = null,
        int $length = 4,
        ?string $seedNumber = null,
        int $limit = 8
    ): array {
        $length = max(4, min(8, $length));
        $prefix = ($prefixId ? PhonePrefix::find($prefixId) : null) ?? PhonePrefix::getDefaultPrefix();

        $suggestions = [];
        $seen = [];

        if ($seedNumber !== null && $seedNumber !== '') {
            $seen[$seedNumber] = true;
        }

        // 1. First load available pool numbers of exact length
        $poolQuery = PhoneNumber::query()
            ->where('status', PhoneNumberStatus::Available)
            ->where('type', PhoneNumberType::UserService)
            ->whereRaw('LENGTH(number) = ?', [$length])
            ->orderBy('number')
            ->limit($limit * 2)
            ->get();

        foreach ($poolQuery as $poolItem) {
            $num = $poolItem->number;
            if (isset($seen[$num]) || $this->isNumberTaken($num)) {
                continue;
            }

            $rond = $this->rondDetector->detect($num);
            $price = SystemSetting::getOrgNumberPrice($length, $rond['is_rond']);

            $seen[$num] = true;
            $suggestions[] = [
                'number' => $num,
                'formatted_number' => $prefix ? "{$prefix->prefix} {$num}" : $num,
                'length' => $length,
                'rond_tier' => $rond['tier'],
                'rond_tier_label' => $rond['tier_label'],
                'rond_pattern' => $rond['pattern'],
                'is_rond' => $rond['is_rond'],
                'price' => $price,
                'source' => 'pool',
            ];

            if (count($suggestions) >= $limit) {
                return $suggestions;
            }
        }

        // 2. If a seedNumber was typed, generate similar shortcodes first
        $candidateStrings = [];
        if ($seedNumber !== null && strlen($seedNumber) >= 2) {
            $cleanSeed = substr(preg_replace('/\D/', '', $seedNumber), 0, $length);
            $padSeed = str_pad($cleanSeed, $length, '0', STR_PAD_RIGHT);

            for ($step = 1; $step <= 9; $step++) {
                $prefixPart = substr($padSeed, 0, max(1, $length - 2));
                $candidateStrings[] = str_pad($prefixPart . ($step * 10), $length, '0', STR_PAD_RIGHT);
                $candidateStrings[] = str_pad($prefixPart . $step . $step, $length, '0', STR_PAD_RIGHT);
            }
        }

        // 3. Curated smart patterns per length (Golden Rond, Semi-Rond, and Standard)
        $curatedByLength = [
            4 => ['4000', '7000', '8000', '9000', '4040', '5050', '7070', '8080', '2200', '4400', '5500', '7700', '4120', '5240', '6380', '7410'],
            5 => ['50000', '60000', '70000', '90000', '40400', '50500', '77000', '88000', '51000', '62000', '73500', '84200', '45600', '54300'],
            6 => ['700000', '800000', '900000', '500500', '600600', '700700', '440000', '550000', '612000', '724000', '835000', '515200'],
            7 => ['5000000', '7000000', '8000000', '4004000', '5005000', '7700000', '6100200', '7250000', '8400100', '5320000'],
            8 => ['50000000', '70000000', '80000000', '40004000', '50005000', '77007700', '61002000', '72504000', '84001000', '53201000'],
        ];

        $candidateStrings = array_merge($candidateStrings, $curatedByLength[$length] ?? []);

        foreach ($candidateStrings as $cand) {
            $cand = substr(preg_replace('/\D/', '', $cand), 0, $length);
            if (strlen($cand) !== $length || $cand[0] === '0' || isset($seen[$cand])) {
                continue;
            }

            if ($this->isNumberTaken($cand)) {
                continue;
            }

            $rond = $this->rondDetector->detect($cand);
            $price = SystemSetting::getOrgNumberPrice($length, $rond['is_rond']);

            $seen[$cand] = true;
            $suggestions[] = [
                'number' => $cand,
                'formatted_number' => $prefix ? "{$prefix->prefix} {$cand}" : $cand,
                'length' => $length,
                'rond_tier' => $rond['tier'],
                'rond_tier_label' => $rond['tier_label'],
                'rond_pattern' => $rond['pattern'],
                'is_rond' => $rond['is_rond'],
                'price' => $price,
                'source' => 'smart',
            ];

            if (count($suggestions) >= $limit) {
                return $suggestions;
            }
        }

        return $suggestions;
    }

    /**
     * Get suggested free numbers from the pool for a given prefix and optional sub-prefix.
     */
    public function getPoolSuggestions(
        int $prefixId,
        ?string $subPrefix = null,
        ?string $suffixPrefix = null,
        int $limit = 6
    ): array {
        $prefix = PhonePrefix::find($prefixId) ?? PhonePrefix::getDefaultPrefix();

        $query = PhoneNumber::query()
            ->where(function ($q) use ($prefix) {
                $q->where('phone_prefix_id', $prefix->id);
                if ($prefix->is_default) {
                    $q->orWhereNull('phone_prefix_id');
                }
            })
            ->where('status', PhoneNumberStatus::Available)
            ->where('type', PhoneNumberType::UserStandard);

        $searchPrefix = '';
        if (! empty($subPrefix)) {
            $searchPrefix .= preg_replace('/\D/', '', $subPrefix);
        }
        if (! empty($suffixPrefix)) {
            $searchPrefix .= preg_replace('/\D/', '', $suffixPrefix);
        }

        if ($searchPrefix !== '') {
            $query->where('number', 'like', $searchPrefix . '%');
        }

        $poolNumbers = $query->inRandomOrder()->limit($limit * 2)->get();

        $results = [];
        $seen = [];

        foreach ($poolNumbers as $p) {
            if ($this->isNumberTaken($p->number)) {
                continue;
            }

            $sub = substr($p->number, 0, 3);
            $suf = substr($p->number, 3, 6);
            $rond = $this->rondDetector->detect($p->number, $suf);

            $seen[$p->number] = true;
            $results[] = [
                'number' => $p->number,
                'formatted' => "{$prefix->prefix} {$p->number}",
                'formatted_number' => "{$prefix->prefix} {$p->number}",
                'sub_prefix' => $sub,
                'suffix' => $suf,
                'rond' => $rond,
                'rond_tier' => $rond['tier'],
                'rond_tier_label' => $rond['tier_label'],
                'rond_pattern' => $rond['pattern'],
                'is_rond' => $rond['is_rond'],
                'price' => $prefix->getPriceForTier($rond['tier']),
                'source' => 'pool',
            ];

            if (count($results) >= $limit) {
                return $results;
            }
        }

        // Supplement with smart free candidates if the pool has fewer than $limit items
        $effectiveSub = ! empty($subPrefix)
            ? preg_replace('/\D/', '', $subPrefix)
            : (($prefix->sub_prefixes[0] ?? null) ?: '912');

        $smartCandidates = [
            $effectiveSub . '777888',
            $effectiveSub . '404040',
            $effectiveSub . '500600',
            $effectiveSub . '246810',
            $effectiveSub . '315920',
            $effectiveSub . '824619',
        ];

        foreach ($smartCandidates as $cand) {
            if (strlen($cand) !== 9 || isset($seen[$cand]) || $this->isNumberTaken($cand)) {
                continue;
            }

            $sub = substr($cand, 0, 3);
            $suf = substr($cand, 3, 6);
            $rond = $this->rondDetector->detect($cand, $suf);

            $seen[$cand] = true;
            $results[] = [
                'number' => $cand,
                'formatted' => "{$prefix->prefix} {$cand}",
                'formatted_number' => "{$prefix->prefix} {$cand}",
                'sub_prefix' => $sub,
                'suffix' => $suf,
                'rond' => $rond,
                'rond_tier' => $rond['tier'],
                'rond_tier_label' => $rond['tier_label'],
                'rond_pattern' => $rond['pattern'],
                'is_rond' => $rond['is_rond'],
                'price' => $prefix->getPriceForTier($rond['tier']),
                'source' => 'smart',
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Generate up to $limit smart alternative free numbers when the requested number is taken.
     */
    protected function generateSmartSuggestions(
        PhonePrefix $prefix,
        string $requestedSubPrefix,
        string $requestedSuffix,
        int $limit = 6
    ): array {
        $suggestions = [];
        $seen = [$requestedSubPrefix . $requestedSuffix => true];

        // Strategy A: Same 6-digit suffix under other sub-prefixes of the same country
        $allowedSubs = is_array($prefix->sub_prefixes) && count($prefix->sub_prefixes) > 0
            ? $prefix->sub_prefixes
            : ['912', '910', '919', '935'];

        foreach ($allowedSubs as $altSub) {
            $cleanAltSub = preg_replace('/\D/', '', (string) $altSub);
            if ($cleanAltSub === $requestedSubPrefix || strlen($cleanAltSub) !== 3) {
                continue;
            }

            $candNumber = $cleanAltSub . $requestedSuffix;
            if (isset($seen[$candNumber])) {
                continue;
            }

            if (! $this->isNumberTaken($candNumber)) {
                $seen[$candNumber] = true;
                $suggestions[] = $this->buildSuggestionItem(
                    $prefix,
                    $cleanAltSub,
                    $requestedSuffix,
                    __('services.numbers.search.badge_same_six_digits', ['sub' => $cleanAltSub])
                );

                if (count($suggestions) >= 2) {
                    break;
                }
            }
        }

        // Strategy B: Close suffix variations under the same sub-prefix
        $candidatesSuffixes = $this->buildCloseSuffixVariations($requestedSuffix);

        foreach ($candidatesSuffixes as $altSuffix) {
            if (count($suggestions) >= $limit) {
                break;
            }

            $candNumber = $requestedSubPrefix . $altSuffix;
            if (isset($seen[$candNumber])) {
                continue;
            }

            if (! $this->isNumberTaken($candNumber)) {
                $seen[$candNumber] = true;
                $suggestions[] = $this->buildSuggestionItem(
                    $prefix,
                    $requestedSubPrefix,
                    $altSuffix,
                    __('services.numbers.search.badge_highest_similarity')
                );
            }
        }

        // Strategy C: Fill remaining slots from available pool or random free numbers with same sub-prefix
        if (count($suggestions) < $limit) {
            $attempts = 0;
            while (count($suggestions) < $limit && $attempts < 25) {
                $attempts++;
                $prefix3 = substr($requestedSuffix, 0, 3);
                $randTail = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
                $altSuffix = $prefix3 . $randTail;
                $candNumber = $requestedSubPrefix . $altSuffix;

                if (isset($seen[$candNumber])) {
                    continue;
                }

                if (! $this->isNumberTaken($candNumber)) {
                    $seen[$candNumber] = true;
                    $suggestions[] = $this->buildSuggestionItem(
                        $prefix,
                        $requestedSubPrefix,
                        $altSuffix,
                        __('services.numbers.search.badge_alternative_clean_sub', ['sub' => $requestedSubPrefix])
                    );
                }
            }
        }

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Build close 6-digit variations of the requested suffix.
     */
    protected function buildCloseSuffixVariations(string $suffix): array
    {
        $variations = [];
        $len = strlen($suffix);
        if ($len !== 6) {
            return $variations;
        }

        $numVal = (int) $suffix;

        foreach ([1, -1, 2, -2, 10, -10, 100, -100] as $delta) {
            $next = $numVal + $delta;
            if ($next >= 0 && $next <= 999999) {
                $variations[] = str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            }
        }

        $first4 = substr($suffix, 0, 4);
        $d4 = $suffix[4];
        $d5 = $suffix[5];
        if ($d4 !== $d5) {
            $variations[] = $first4 . $d5 . $d4;
        }

        foreach (['0', '7', '8', '5', '1'] as $rep) {
            $variations[] = substr($suffix, 0, 5) . $rep;
        }

        return array_values(array_unique($variations));
    }

    protected function buildSuggestionItem(
        PhonePrefix $prefix,
        string $subPrefix,
        string $suffix,
        string $reasonBadge
    ): array {
        $fullNumber = $subPrefix . $suffix;
        $rond = $this->rondDetector->detect($fullNumber, $suffix);

        return [
            'number' => $fullNumber,
            'formatted' => "{$prefix->prefix} {$fullNumber}",
            'formatted_number' => "{$prefix->prefix} {$fullNumber}",
            'sub_prefix' => $subPrefix,
            'suffix' => $suffix,
            'rond' => $rond,
            'rond_tier' => $rond['tier'],
            'rond_tier_label' => $rond['tier_label'],
            'rond_pattern' => $rond['pattern'],
            'is_rond' => $rond['is_rond'],
            'price' => $prefix->getPriceForTier($rond['tier']),
            'badge' => $reasonBadge,
            'reason_badge' => $reasonBadge,
        ];
    }
}
