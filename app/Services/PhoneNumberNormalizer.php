<?php

namespace App\Services;

use App\Models\PhoneNumber;
use App\Models\PhonePrefix;

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
     * Fully dynamic across any registered active PhonePrefix (e.g. +85, +98, etc.).
     *
     * Accurately handles:
     * - 4-8 digit service numbers (e.g. 1000, 1001, 90001) with or without prefix
     * - 9-digit standard numbers (e.g. 000010001) with or without prefix
     * - Persian/Arabic keyboard digits
     */
    public static function resolveToStoredNumber(string $rawInput, ?string $explicitPrefix = null): string
    {
        $digits = self::clean($rawInput);

        if (empty($digits)) {
            return '';
        }

        // 1. Direct match in database (stored as 4-9 digit number string)
        if (PhoneNumber::where('number', $digits)->exists()) {
            return $digits;
        }

        // 2. Dynamic check across all active clean prefixes (e.g. 85, 98)
        $activeCleanPrefixes = PhonePrefix::getActiveCleanPrefixes();
        usort($activeCleanPrefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($activeCleanPrefixes as $prefix) {
            if (str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix)) {
                $withoutPrefix = substr($digits, strlen($prefix));
                if (PhoneNumber::where('number', $withoutPrefix)->exists()) {
                    return $withoutPrefix;
                }
            }
        }

        // 3. Fallback: if starts with any active prefix, strip it
        foreach ($activeCleanPrefixes as $prefix) {
            if (str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix)) {
                return substr($digits, strlen($prefix));
            }
        }

        return $digits;
    }
}
