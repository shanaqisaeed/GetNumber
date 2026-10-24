<?php

namespace App\Services;

use App\Models\PhoneNumber;

class PhoneNumberNormalizer
{
    /**
     * Convert Persian/Arabic digits to English digits and strip non-digit characters.
     */
    public static function clean(string $input): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $str = str_replace($persian, $english, trim($input));
        $str = str_replace($arabic, $english, $str);

        // Strip everything except digits
        return preg_replace('/[^\d]/', '', $str);
    }

    /**
     * Resolve input string to the exact number string stored in phone_numbers table.
     * Accurately handles:
     * - 4-8 digit service numbers (e.g. 1000, 1001, 90001) with or without +85
     * - 9-digit standard numbers (e.g. 000010001) with or without +85
     * - Persian/Arabic keyboard digits
     */
    public static function resolveToStoredNumber(string $rawInput): string
    {
        $digits = self::clean($rawInput);

        if (empty($digits)) {
            return '';
        }

        // 1. Direct match in database
        if (PhoneNumber::where('number', $digits)->exists()) {
            return $digits;
        }

        // 2. If entered with country code 85 (e.g. 851001 or 85000010001)
        if (str_starts_with($digits, '85') && strlen($digits) > 2) {
            $without85 = substr($digits, 2);
            if (PhoneNumber::where('number', $without85)->exists()) {
                return $without85;
            }
        }

        // Fallback: if it starts with 85, strip 85
        if (str_starts_with($digits, '85') && strlen($digits) > 2) {
            return substr($digits, 2);
        }

        return $digits;
    }
}
