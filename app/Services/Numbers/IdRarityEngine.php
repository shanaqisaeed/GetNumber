<?php

namespace App\Services\Numbers;

class IdRarityEngine
{
    public const TIER_GOLDEN = 'golden_rond';
    public const TIER_SEMI = 'semi_rond';
    public const TIER_REGULAR = 'regular';

    protected const VIP_KEYWORDS = [
        'vip', 'ceo', 'cfo', 'cto', 'pro', 'top', 'bot', 'god', 'sky', 'sun',
        'star', 'king', 'gold', 'coin', 'pay', 'bank', 'shop', 'meta', 'web3',
        'iran', 'teh', 'hero', 'club', 'team', 'news', 'chat', 'live', 'call',
    ];

    /**
     * Analyze an ID/handle and determine its rarity pattern and tier.
     *
     * @param string $handle
     * @param float $basePrice
     * @return array{
     *     tier: string,
     *     tier_label: string,
     *     tier_badge: string,
     *     pattern_name: string,
     *     multiplier: float,
     *     calculated_price: float,
     *     is_rond: bool
     * }
     */
    public function analyze(string $handle, float $basePrice = 30.0000): array
    {
        $clean = strtolower(trim(preg_replace('/^@/', '', $handle)));
        $len = strlen($clean);

        if ($len === 0) {
            return [
                'tier' => self::TIER_REGULAR,
                'tier_label' => 'عادی',
                'tier_badge' => 'regular',
                'pattern_name' => 'ورودی نامعتبر',
                'multiplier' => 1.0,
                'calculated_price' => $basePrice,
                'is_rond' => false,
            ];
        }

        // 1. Ultra-short IDs (3 or 4 characters) or high-profile VIP keywords
        if ($len <= 4 || in_array($clean, self::VIP_KEYWORDS, true)) {
            $pattern = $len <= 3
                ? 'آیدی کوتاه ۳ کاراکتری فوق نایاب'
                : ($len === 4 ? 'آیدی ۴ کاراکتری رند طلایی' : 'آیدی کلیدواژه طلایی (VIP Keyword)');

            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => 'رند طلایی (Golden VIP)',
                'tier_badge' => 'golden',
                'pattern_name' => $pattern,
                'multiplier' => 4.0,
                'calculated_price' => (float) bcmul((string) $basePrice, '4.00', 4),
                'is_rond' => true,
            ];
        }

        // 2. All identical characters (e.g. aaaaa, bbbbbb, 77777)
        if (preg_match('/^(.)\1+$/', $clean)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => 'رند طلایی (Golden VIP)',
                'tier_badge' => 'golden',
                'pattern_name' => 'تکرار یکنواخت یک کاراکتر',
                'multiplier' => 3.5,
                'calculated_price' => (float) bcmul((string) $basePrice, '3.50', 4),
                'is_rond' => true,
            ];
        }

        // 3. Palindrome (reads same forwards and backwards, e.g. radar, madam, 12321)
        if ($clean === strrev($clean) && $len >= 4) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => 'رند طلایی (Golden VIP)',
                'tier_badge' => 'golden',
                'pattern_name' => 'الگوی آینه‌ای متقارن (Palindrome)',
                'multiplier' => 3.0,
                'calculated_price' => (float) bcmul((string) $basePrice, '3.00', 4),
                'is_rond' => true,
            ];
        }

        // 4. Consecutive alphabetical or numerical sequence (e.g. abcde, 12345, abcd, 6789)
        if ($this->isSequential($clean)) {
            return [
                'tier' => self::TIER_GOLDEN,
                'tier_label' => 'رند طلایی (Golden VIP)',
                'tier_badge' => 'golden',
                'pattern_name' => 'توالی ترتیبی کاراکترها (Sequential)',
                'multiplier' => 3.0,
                'calculated_price' => (float) bcmul((string) $basePrice, '3.00', 4),
                'is_rond' => true,
            ];
        }

        // 5. 5-character length
        if ($len === 5) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => 'نیمه‌رند (Semi-Rond)',
                'tier_badge' => 'semi_rond',
                'pattern_name' => 'آیدی کوتاه ۵ کاراکتری',
                'multiplier' => 2.0,
                'calculated_price' => (float) bcmul((string) $basePrice, '2.00', 4),
                'is_rond' => true,
            ];
        }

        // 6. Repeated 2-character blocks (e.g. koko, mimi, toot, cool, book)
        if (preg_match('/^([a-z0-9]{2})\1+$/', $clean) || preg_match('/([a-z0-9])\1{2,}/', $clean)) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => 'نیمه‌رند (Semi-Rond)',
                'tier_badge' => 'semi_rond',
                'pattern_name' => 'تکرار بلوک‌های دوگانه یا سه‌گانه',
                'multiplier' => 1.8,
                'calculated_price' => (float) bcmul((string) $basePrice, '1.80', 4),
                'is_rond' => true,
            ];
        }

        // 7. Ends with 00, 000, 777 or memorable digits
        if (preg_match('/(000|777|999|007)$/', $clean)) {
            return [
                'tier' => self::TIER_SEMI,
                'tier_label' => 'نیمه‌رند (Semi-Rond)',
                'tier_badge' => 'semi_rond',
                'pattern_name' => 'پسوند عددی رند و خاطره‌انگیز',
                'multiplier' => 1.5,
                'calculated_price' => (float) bcmul((string) $basePrice, '1.50', 4),
                'is_rond' => true,
            ];
        }

        // 8. Standard Regular ID
        return [
            'tier' => self::TIER_REGULAR,
            'tier_label' => 'عادی',
            'tier_badge' => 'regular',
            'pattern_name' => 'آیدی استاندارد',
            'multiplier' => 1.0,
            'calculated_price' => $basePrice,
            'is_rond' => false,
        ];
    }

    /**
     * Check if string contains sequentially increasing or decreasing characters.
     */
    protected function isSequential(string $str): bool
    {
        $len = strlen($str);
        if ($len < 4) {
            return false;
        }

        $isInc = true;
        $isDec = true;

        for ($i = 0; $i < $len - 1; $i++) {
            $curr = ord($str[$i]);
            $next = ord($str[$i + 1]);

            if ($next !== $curr + 1) {
                $isInc = false;
            }
            if ($next !== $curr - 1) {
                $isDec = false;
            }
        }

        return $isInc || $isDec;
    }
}

