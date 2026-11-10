<?php

namespace App\Services\Security;

use App\Models\Message;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SuspiciousBehaviorEngine
{
    /**
     * Thresholds for suspicious activities.
     */
    protected const REPETITIVE_BODY_THRESHOLD = 8; // Same text to 8+ numbers within 10 mins
    protected const HIGH_RISK_THRESHOLD = 70;

    /**
     * Analyze behavior prior to or during message dispatch.
     *
     * @return array{risk_score: int, is_flagged: bool, reasons: list<string>}
     */
    public function evaluateSend(User $user, array $destinations, string $body): array
    {
        if ($user->isAdmin()) {
            return ['risk_score' => 0, 'is_flagged' => false, 'reasons' => []];
        }

        $reasons = [];
        $addedRisk = 0;

        // 1. Detect repetitive spam (identical text sent to many different destinations recently)
        $bodyHash = md5(trim($body));
        $repKey = "spam_check:{$user->id}:{$bodyHash}";
        $repCount = (int) Cache::get($repKey, 0) + count($destinations);
        Cache::put($repKey, $repCount, 600); // 10 minutes window

        if ($repCount >= self::REPETITIVE_BODY_THRESHOLD) {
            $addedRisk += 30;
            $reasons[] = __('services.security.behavior.repetitive_body', ['threshold' => self::REPETITIVE_BODY_THRESHOLD]);
        }

        // 2. High-volume destination surge for non-trusted tiers
        if (! $user->isTrustedTier() && count($destinations) >= 15) {
            $addedRisk += 25;
            $reasons[] = __('services.security.behavior.bulk_unverified');
        }

        // 3. Check rapid rate limit trip history
        $tripsKey = "rate_limit_trips:{$user->id}";
        $tripsCount = (int) Cache::get($tripsKey, 0);
        if ($tripsCount >= 3) {
            $addedRisk += 20;
            $reasons[] = __('services.security.behavior.bypass_rate_limits');
        }

        // Compute new cumulative risk score
        $newScore = min(100, $user->risk_score + $addedRisk);
        if ($addedRisk > 0) {
            $user->update(['risk_score' => $newScore]);
        }

        $isCritical = $newScore >= self::HIGH_RISK_THRESHOLD;

        if ($isCritical && $user->trust_tier !== 'restricted') {
            // Automatically restrict account
            $user->update(['trust_tier' => 'restricted']);

            SecurityIncident::create([
                'user_id' => $user->id,
                'type' => 'suspicious_behavior_auto_restricted',
                'severity' => 'critical',
                'details' => [
                    'cumulative_risk_score' => $newScore,
                    'reasons' => $reasons,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'action_taken' => 'restricted',
            ]);
        } elseif ($addedRisk >= 25) {
            SecurityIncident::create([
                'user_id' => $user->id,
                'type' => 'suspicious_activity_warning',
                'severity' => 'medium',
                'details' => [
                    'risk_score' => $newScore,
                    'reasons' => $reasons,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'action_taken' => 'logged',
            ]);
        }

        return [
            'risk_score' => $newScore,
            'is_flagged' => $addedRisk > 0,
            'reasons' => $reasons,
        ];
    }
}