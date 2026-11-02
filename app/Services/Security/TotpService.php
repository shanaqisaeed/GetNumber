<?php

namespace App\Services\Security;

use InvalidArgumentException;

class TotpService
{
    /**
     * Standard RFC 4648 Base32 alphabet.
     */
    protected const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Default time slice duration in seconds.
     */
    protected const TIME_STEP = 30;

    /**
     * Default OTP digit length.
     */
    protected const DIGITS = 6;

    /**
     * Generate a cryptographically secure random Base32 secret key.
     */
    public function generateSecret(int $byteCount = 20): string
    {
        $bytes = random_bytes($byteCount);
        return $this->base32Encode($bytes);
    }

    /**
     * Generate the standard `otpauth://` URI compatible with Google Authenticator, Authy, 1Password, etc.
     */
    public function generateQrCodeUrl(string $company, string $accountName, string $secret): string
    {
        $encodedCompany = rawurlencode($company);
        $encodedAccount = rawurlencode($accountName);

        return "otpauth://totp/{$encodedCompany}:{$encodedAccount}?secret={$secret}&issuer={$encodedCompany}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Calculate a 6-digit TOTP code for a given timestamp.
     */
    public function calculateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $timeSlice = (int) floor($timestamp / self::TIME_STEP);

        $binarySecret = $this->base32Decode($secret);

        // Pack timeSlice into an 8-byte big-endian binary string
        $binaryTime = pack('N*', 0, $timeSlice);

        // Compute HMAC-SHA1
        $hash = hash_hmac('sha1', $binaryTime, $binarySecret, true);

        // Dynamic Truncation (RFC 4226)
        $offset = ord(substr($hash, -1)) & 0x0F;
        $unpacked = unpack('N', substr($hash, $offset, 4));
        $truncatedHash = $unpacked[1] & 0x7FFFFFFF;

        $otp = $truncatedHash % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a submitted TOTP code with drift tolerance (window ±1 step = ±30 seconds).
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (strlen($code) !== self::DIGITS || ! ctype_digit($code)) {
            return false;
        }

        $timestamp = $timestamp ?? time();

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $checkTime = $timestamp + ($i * self::TIME_STEP);
            $calculatedCode = $this->calculateCode($secret, $checkTime);

            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a list of cryptographically secure one-time backup recovery codes.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $part1 = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
            $part2 = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
            $codes[] = "{$part1}-{$part2}";
        }

        return $codes;
    }

    /**
     * Encode binary string into Base32.
     */
    public function base32Encode(string $binary): string
    {
        if (empty($binary)) {
            return '';
        }

        $binaryLength = strlen($binary);
        $bits = '';
        for ($i = 0; $i < $binaryLength; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 5);
        $base32 = '';
        foreach ($chunks as $chunk) {
            $paddedChunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $base32 .= self::BASE32_ALPHABET[bindec($paddedChunk)];
        }

        return $base32;
    }

    /**
     * Decode Base32 string into binary string.
     */
    public function base32Decode(string $base32): string
    {
        $base32 = strtoupper(trim($base32));
        $base32 = str_replace('=', '', $base32);

        if (empty($base32)) {
            return '';
        }

        $bits = '';
        $length = strlen($base32);
        for ($i = 0; $i < $length; $i++) {
            $val = strpos(self::BASE32_ALPHABET, $base32[$i]);
            if ($val === false) {
                throw new InvalidArgumentException("Invalid Base32 character: {$base32[$i]}");
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 8);
        $binary = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }

        return $binary;
    }
}

