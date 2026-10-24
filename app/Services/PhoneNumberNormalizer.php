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

    /**
     * Validate that a destination number includes a valid full active system prefix,
     * and that the remaining number length is valid according to active system settings (4 to 9 digits).
     *
     * @return array{valid: bool, error?: string, clean_number?: string, prefix?: string, full_formatted?: string}
     */
    public static function validateFullDestinationNumber(string $rawInput): array
    {
        $trimmed = trim($rawInput);
        if ($trimmed === '') {
            return [
                'valid' => false,
                'error' => 'شماره مقصد الزامی است و نمی‌تواند خالی باشد.',
            ];
        }

        $cleanedDigits = self::clean($rawInput);
        if (empty($cleanedDigits)) {
            return [
                'valid' => false,
                'error' => 'شماره مقصد فاقد ارقام معتبر است.',
            ];
        }

        // Active prefixes in system (e.g. 85, 98)
        $activeCleanPrefixes = PhonePrefix::getActiveCleanPrefixes();
        usort($activeCleanPrefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

        $matchedCleanPrefix = null;
        $numberPart = null;

        // Check if raw input starts with '+' followed by an active prefix, '00' prefix, or clean prefix
        foreach ($activeCleanPrefixes as $clean) {
            if (str_starts_with($trimmed, '+' . $clean)) {
                $matchedCleanPrefix = $clean;
                $numberPart = self::clean(substr($trimmed, strlen('+' . $clean)));
                break;
            } elseif (str_starts_with($cleanedDigits, '00' . $clean)) {
                $matchedCleanPrefix = $clean;
                $numberPart = substr($cleanedDigits, strlen('00' . $clean));
                break;
            } elseif (str_starts_with($cleanedDigits, $clean)) {
                $matchedCleanPrefix = $clean;
                $numberPart = substr($cleanedDigits, strlen($clean));
                break;
            }
        }

        if (! $matchedCleanPrefix || $numberPart === null || $numberPart === '') {
            $defaultPrefix = PhonePrefix::getDefaultPrefixString();
            return [
                'valid' => false,
                'error' => "شماره مقصد باید همیشه به همراه پیش‌شماره کامل سامانه (مانند {$defaultPrefix}...) ارسال شود.",
            ];
        }

        // Validate remaining number length:
        // Service numbers: 4 to 8 digits
        // Standard user numbers: 9 digits
        // Overall valid length: 4 to 9 digits
        $length = strlen($numberPart);
        if ($length < 4 || $length > 9) {
            return [
                'valid' => false,
                'error' => "طول شماره مقصد با احتساب پیش‌شماره نامعتبر است. طول شماره اصلی باید بین ۴ تا ۹ رقم باشد (طول دریافت شده: {$length} رقم).",
            ];
        }

        return [
            'valid' => true,
            'clean_number' => $numberPart,
            'prefix' => '+' . $matchedCleanPrefix,
            'full_formatted' => '+' . $matchedCleanPrefix . $numberPart,
        ];
    }
}
