<?php

namespace App\Services\Numbers;

class RondPatternDetectorService
{
    public const TIER_GOLDEN = 'golden_rond';
    public const TIER_SEMI = 'semi_rond';
    public const TIER_REGULAR = 'regular';

    /**
     * Analyze a number and determine its rond tier and human-readable pattern name.
     * Can evaluate either the raw number, or also inspect the 6-digit suffix for 9-digit lines.
     *
     * @return array{
     *     tier: string,
     *     tier_label: string,
     *     pattern: string,
     *     is_rond: bool,
     * }
     */
    public function detect(string $fullNumber, ?string $suffix = null): array
    {
        $cleanNumber = preg_replace('/\D/', '', $fullNumber);
        $cleanSuffix = $suffix !== null ? preg_replace('/\D/', '', $suffix) : null;

        // If a 6-digit suffix is provided (or if the number is >= 6 digits), evaluate both and take highest tier
        $candidates = array_unique(array_filter([
            $cleanSuffix,
            $cleanNumber,
            strlen($cleanNumber) >= 6 ? substr($cleanNumber, -6) : null,
        ]));

        $bestResult = [
            'tier' => self::TIER_REGULAR,
            'tier_label' => __('services.numbers.rond.tiers.regular'),
            'pattern' => __('services.numbers.rond.patterns.regular_none'),
            'is_rond' => false,
        ];

        foreach ($candidates as $cand) {
            if (empty($cand)) {
                continue;
            }

            $res = $this->evaluateString($cand);
            if ($this->tierWeight($res['tier']) > $this->tierWeight($bestResult['tier'])) {
                $bestResult = $res;
            }

            if ($bestResult['tier'] === self::TIER_GOLDEN) {
                break;
            }
        }

        return $bestResult;
    }

    /**
     * Alias for detect() to maintain compatibility with catalog handlers.
     */
    public function detectTier(string $fullNumber, ?string $suffix = null): array
    {
        $res = $this->detect($fullNumber, $suffix);
        $res['pattern_name'] = $res['pattern'] ?? null;

        return $res;
    }

    /**
     * Evaluate a single clean numeric string.
     */
    protected function evaluateString(string $digits): array
    {
        $len = strlen($digits);
        if ($len < 4) {
            return [
                'tier' => self::TIER_REGULAR,
                'tier_label' => __('services.numbers.rond.tiers.regular'),
                'pattern' => __('services.numbers.rond.patterns.short_regular'),
                'is_rond' => false,
            ];
        }

        // 1. All identical digits (e.g. 777777, 8888, 99999999)
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.all_same_digits', ['digit' => $digits[0]]),
                'is_rond' => true,
            ];
        }

        // 2. Trailing 4 or more zeros (e.g. 100000, 500000, 900000)
        if (preg_match('/^[1-9]0{4,}$/', $digits)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.multiples_zeros'),
                'is_rond' => true,
            ];
        }

        // 3. Fully sequential ascending or descending (e.g. 123456, 654321, 1234, 4321)
        if ($this->isFullySequentialAscending($digits)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.consecutive_ascending'),
                'is_rond' => true,
            ];
        }

        if ($this->isFullySequentialDescending($digits)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.consecutive_descending'),
                'is_rond' => true,
            ];
        }

        // 4. Repeated triplets twice (e.g. 123123, 456456)
        if ($len === 6 && substr($digits, 0, 3) === substr($digits, 3, 3)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.repeat_three_digits'),
                'is_rond' => true,
            ];
        }

        // 5. Triple consecutive pairs (e.g. 112233, 332211, 445566)
        if ($len === 6 && preg_match('/^(\d)\1(\d)\2(\d)\3$/', $digits)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.consecutive_three_pairs'),
                'is_rond' => true,
            ];
        }

        // 6. Two-digit alternating 3 times (e.g. 121212, 101010, 898989)
        if ($len === 6 && preg_match('/^(\d{2})\1\1$/', $digits)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                'pattern' => __('services.numbers.rond.patterns.periodic_two_digits'),
                'is_rond' => true,
            ];
        }

        // 7. Full Palindrome / Mirror (length >= 5)
        if ($digits === strrev($digits)) {
            if ($len >= 6) {
                return [
                    'tier' => self::TIER_GOLDEN,
                    'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                    'pattern' => __('services.numbers.rond.patterns.mirror_six_digits'),
                    'is_rond' => true,
                ];
            }
            if ($len === 5) {
                return [
                    'tier' => self::TIER_SEMI,
                    'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                    'pattern' => __('services.numbers.rond.patterns.mirror_five_digits'),
                    'is_rond' => true,
                ];
            }
            if ($len === 4) {
                return [
                    'tier' => self::TIER_GOLDEN,
                    'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                    'pattern' => __('services.numbers.rond.patterns.mirror_four_digits'),
                    'is_rond' => true,
                ];
            }
        }

        // 4-digit specific patterns
        if ($len === 4) {
            // e.g. 1212 (alternating)
            if (substr($digits, 0, 2) === substr($digits, 2, 2)) {
                return [
                    'tier' => self::TIER_GOLDEN,
                    'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                    'pattern' => __('services.numbers.rond.patterns.repeat_pair_abab'),
                    'is_rond' => true,
                ];
            }
            // e.g. 1122
            if ($digits[0] === $digits[1] && $digits[2] === $digits[3]) {
                return [
                    'tier' => self::TIER_GOLDEN,
                    'tier_label' => __('services.numbers.rond.tiers.golden_rond'),
                    'pattern' => __('services.numbers.rond.patterns.pairs_aabb'),
                    'is_rond' => true,
                ];
            }
            // 3 identical digits (e.g. 1112 or 2111)
            if (preg_match('/^(\d)\1\1\d$/', $digits) || preg_match('/^\d(\d)\1\1$/', $digits)) {
                return [
                    'tier' => self::TIER_SEMI,
                    'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                    'pattern' => __('services.numbers.rond.patterns.three_same_digits'),
                    'is_rond' => true,
                ];
            }
            // Trailing two zeros (e.g. 1200, 5000)
            if (str_ends_with($digits, '00')) {
                return [
                    'tier' => self::TIER_SEMI,
                    'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                    'pattern' => __('services.numbers.rond.patterns.ending_two_zeros'),
                    'is_rond' => true,
                ];
            }
        }

        // 8. Semi-Rond Patterns (4+ identical digits in a row)
        if (preg_match('/(\d)\1{3,}/', $digits, $matches)) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                'pattern' => __('services.numbers.rond.patterns.repeat_four_digits', ['digits' => $matches[1]]),
                'is_rond' => true,
            ];
        }

        // 9. Semi-Rond: Trailing or leading 3 zeros (e.g. xxx000 or 000xxx)
        if (str_ends_with($digits, '000') || str_starts_with($digits, '000')) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                'pattern' => __('services.numbers.rond.patterns.three_zeros_ends'),
                'is_rond' => true,
            ];
        }

        // 10. Semi-Rond: 4+ sequential digits anywhere
        if ($this->hasSequentialSubsegment($digits, 4)) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                'pattern' => __('services.numbers.rond.patterns.four_consecutive_digits'),
                'is_rond' => true,
            ];
        }

        // 11. Semi-Rond: 5-digit palindrome subsegment
        for ($i = 0; $i <= $len - 5; $i++) {
            $sub = substr($digits, $i, 5);
            if ($sub === strrev($sub)) {
                return [
                    'tier' => self::TIER_SEMI,
                    'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                    'pattern' => __('services.numbers.rond.patterns.mirror_part_five_digits'),
                    'is_rond' => true,
                ];
            }
        }

        // 12. Semi-Rond: Two distinct pairs (e.g. 1122xx, xx1122, 11xx22)
        if (preg_match('/(\d)\1.*?(\d)\2/', $digits)) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => __('services.numbers.rond.tiers.semi_rond'),
                'pattern' => __('services.numbers.rond.patterns.separate_two_pairs'),
                'is_rond' => true,
            ];
        }

        // 13. Regular
        return [
            'tier' => self::TIER_REGULAR,
            'tier_label' => __('services.numbers.rond.tiers.regular'),
            'pattern' => __('services.numbers.rond.patterns.standard_regular'),
            'is_rond' => false,
        ];
    }

    protected function isFullySequentialAscending(string $digits): bool
    {
        $len = strlen($digits);
        for ($i = 0; $i < $len - 1; $i++) {
            $curr = (int) $digits[$i];
            $next = (int) $digits[$i + 1];
            if ($next !== ($curr + 1) % 10) {
                return false;
            }
        }

        return true;
    }

    protected function isFullySequentialDescending(string $digits): bool
    {
        $len = strlen($digits);
        for ($i = 0; $i < $len - 1; $i++) {
            $curr = (int) $digits[$i];
            $next = (int) $digits[$i + 1];
            $expected = ($curr - 1 < 0) ? 9 : $curr - 1;
            if ($next !== $expected) {
                return false;
            }
        }

        return true;
    }

    protected function hasSequentialSubsegment(string $digits, int $subLen): bool
    {
        $len = strlen($digits);
        for ($i = 0; $i <= $len - $subLen; $i++) {
            $sub = substr($digits, $i, $subLen);
            if ($this->isFullySequentialAscending($sub) || $this->isFullySequentialDescending($sub)) {
                return true;
            }
        }

        return false;
    }

    protected function tierWeight(string $tier): int
    {
        return match ($tier) {
            self::TIER_GOLDEN => 3,
            self::TIER_SEMI => 2,
            default => 1,
        };
    }
}