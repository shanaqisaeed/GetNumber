<?php

namespace App\Services;

use App\Models\PhonePrefix;
use App\Models\SystemSetting;

class SmsSegmentCalculatorService
{
    /**
     * Standard GSM 03.38 7-bit basic character set.
     */
    protected const GSM_BASIC_CHARS = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1bÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /**
     * GSM 03.38 7-bit extension character set.
     * Each of these characters requires an escape byte (\x1b) and therefore counts as 2 septets/units.
     */
    protected const GSM_EXTENSION_CHARS = "^{}\\[~]|€";

    /**
     * Default price per SMS segment in COIN.
     */
    protected const DEFAULT_RATE_PER_SEGMENT = '0.0500';

    /**
     * Calculate SMS segments, encoding type, character count, and cost estimate.
     *
     * @return array{
     *     encoding: 'GSM-7'|'UCS-2',
     *     character_count: int,
     *     units_used: int,
     *     segment_count: int,
     *     max_per_segment: int,
     *     chars_remaining_in_segment: int,
     *     rate_per_segment: string,
     *     prefix_id: int|null,
     *     prefix_code: string|null,
     *     currency: string,
     *     estimated_cost: string,
     *     destinations_count: int,
     *     total_cost: string
     * }
     */
    public function calculate(
        string $text,
        int $destinationsCount = 1,
        ?string $ratePerSegment = null,
        ?int $prefixId = null,
        ?string $numberForPrefix = null
    ): array {
        $destinationsCount = max(1, $destinationsCount);

        $resolvedPrefix = null;
        if ($prefixId !== null || ($numberForPrefix !== null && trim($numberForPrefix) !== '')) {
            $resolvedPrefix = PhonePrefix::resolveForNumber($numberForPrefix, $prefixId);
        } elseif ($ratePerSegment === null) {
            $resolvedPrefix = PhonePrefix::getDefaultPrefix();
        }

        $rate = $ratePerSegment
            ?? ($resolvedPrefix?->getSmsRatePerSegment())
            ?? (string) SystemSetting::get('sms_price_per_segment', self::DEFAULT_RATE_PER_SEGMENT);

        $charCount = mb_strlen($text, 'UTF-8');
        if ($charCount === 0) {
            return [
                'encoding' => 'GSM-7',
                'character_count' => 0,
                'units_used' => 0,
                'segment_count' => 0,
                'max_per_segment' => 160,
                'chars_remaining_in_segment' => 160,
                'rate_per_segment' => $rate,
                'prefix_id' => $resolvedPrefix?->id,
                'prefix_code' => $resolvedPrefix?->prefix,
                'currency' => 'COIN',
                'estimated_cost' => '0.0000',
                'destinations_count' => $destinationsCount,
                'total_cost' => '0.0000',
            ];
        }

        $isGsm = true;
        $unitsUsed = 0;

        // Iterate character by character
        for ($i = 0; $i < $charCount; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            if (mb_strpos(self::GSM_BASIC_CHARS, $char, 0, 'UTF-8') !== false) {
                $unitsUsed += 1;
            } elseif (mb_strpos(self::GSM_EXTENSION_CHARS, $char, 0, 'UTF-8') !== false) {
                // Extension character counts as 2 units
                $unitsUsed += 2;
            } else {
                // Non-GSM character found (e.g. Persian/Arabic/Emoji) -> triggers UCS-2
                $isGsm = false;
                break;
            }
        }

        if ($isGsm) {
            $encoding = 'GSM-7';
            if ($unitsUsed <= 160) {
                $segmentCount = 1;
                $maxPerSegment = 160;
                $charsRemaining = 160 - $unitsUsed;
            } else {
                $maxPerSegment = 153;
                $segmentCount = (int) ceil($unitsUsed / 153);
                $charsRemaining = ($segmentCount * 153) - $unitsUsed;
            }
        } else {
            $encoding = 'UCS-2';
            $unitsUsed = $charCount;
            if ($unitsUsed <= 70) {
                $segmentCount = 1;
                $maxPerSegment = 70;
                $charsRemaining = 70 - $unitsUsed;
            } else {
                $maxPerSegment = 67;
                $segmentCount = (int) ceil($unitsUsed / 67);
                $charsRemaining = ($segmentCount * 67) - $unitsUsed;
            }
        }

        // Calculate cost using bcmath
        $singleCost = bcmul($rate, (string) $segmentCount, 4);
        $totalCost = bcmul($singleCost, (string) $destinationsCount, 4);

        return [
            'encoding' => $encoding,
            'character_count' => $charCount,
            'units_used' => $unitsUsed,
            'segment_count' => $segmentCount,
            'max_per_segment' => $maxPerSegment,
            'chars_remaining_in_segment' => max(0, $charsRemaining),
            'rate_per_segment' => $rate,
            'prefix_id' => $resolvedPrefix?->id,
            'prefix_code' => $resolvedPrefix?->prefix,
            'currency' => 'COIN',
            'estimated_cost' => $singleCost,
            'destinations_count' => $destinationsCount,
            'total_cost' => $totalCost,
        ];
    }
}

