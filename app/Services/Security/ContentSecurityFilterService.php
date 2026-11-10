<?php

namespace App\Services\Security;

use App\Exceptions\ContentSecurityViolationException;
use App\Models\SecurityIncident;
use App\Models\User;

class ContentSecurityFilterService
{
    /**
     * High-risk phishing / scam keyword phrases (in Persian & English).
     */
    protected const PHISHING_KEYWORDS = [
        'سامانه ثنا',
        'ابلاغیه الکترونیکی',
        'ابلاغیه قضایی',
        'واریز یارانه',
        'سهام عدالت',
        'مسدودی حساب',
        'رمز دوم یکبار مصرف',
        'شکایت علیه شما',
        'حکم جلب',
        'قطع یارانه',
        'لغو امتیاز سیمکارت',
        'برنده قرعه کشی',
        'برنده شدید',
        'جایزه نقدی',
        'اطلاعات کارت بانکی',
        'رمز پویا',
        'توقیف حساب',
    ];

    /**
     * Suspicious URL regex patterns:
     * 1. Raw IP addresses in URL
     * 2. Spoofed bank / judicial domains
     */
    protected const SUSPICIOUS_URL_REGEX = [
        '/https?:\/\/(?:\d{1,3}\.){3}\d{1,3}/i', // Raw IP URL e.g. http://185.220.10.1
        '/https?:\/\/[^\s\/]*(?:shaparak|mellat|bmi|sepah|melli|sana|adliran)[^\s\/]*\.(?:xyz|top|site|club|tk|ml|ga|cf|gq|cc|ru|rest|click|monster)/i', // Suspicious TLD banking spoof
        '/https?:\/\/[^\s\/]*(?:shaparak[^\s\/]+\.|sana[^\s\/]+\.)[a-z]{2,}/i', // Domain with hyphens/prefixes mimicking official sites
    ];

    /**
     * Analyze message body. If unsafe, logs an incident and throws ContentSecurityViolationException.
     *
     * @throws ContentSecurityViolationException
     */
    public function validateContent(string $body, ?User $user = null, ?int $phoneNumberId = null): void
    {
        $inspection = $this->inspect($body);

        if (! $inspection['safe']) {
            if ($user) {
                SecurityIncident::create([
                    'user_id' => $user->id,
                    'phone_number_id' => $phoneNumberId,
                    'type' => 'phishing_attempt',
                    'severity' => 'high',
                    'details' => [
                        'reasons' => $inspection['reasons'],
                        'matched_phrases' => $inspection['matched_phrases'],
                        'body_snippet' => mb_substr($body, 0, 150),
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'action_taken' => 'blocked',
                ]);

                // Increment user's risk score by 25 points on phishing attempts
                $user->increment('risk_score', 25);
            }

            throw new ContentSecurityViolationException(
                message: __('services.security.filter.blocked_phishing'),
                details: $inspection
            );
        }
    }

    /**
     * Inspect message body and return detailed analysis.
     *
     * @return array{safe: bool, reasons: list<string>, matched_phrases: list<string>}
     */
    public function inspect(string $body): array
    {
        $cleanText = mb_strtolower($body);
        $reasons = [];
        $matchedPhrases = [];

        // 1. Phishing keywords check
        foreach (self::PHISHING_KEYWORDS as $keyword) {
            if (mb_strpos($cleanText, mb_strtolower($keyword)) !== false) {
                $matchedPhrases[] = $keyword;
            }
        }

        if (count($matchedPhrases) > 0) {
            $reasons[] = __('services.security.filter.reason_keywords');
        }

        // 2. Suspicious URL patterns check
        foreach (self::SUSPICIOUS_URL_REGEX as $regex) {
            if (preg_match($regex, $body, $matches)) {
                $matchedPhrases[] = $matches[0];
                $reasons[] = __('services.security.filter.reason_url');
            }
        }

        $isSafe = empty($reasons);

        return [
            'safe' => $isSafe,
            'reasons' => $reasons,
            'matched_phrases' => array_unique($matchedPhrases),
        ];
    }
}