<?php

namespace App\Services\Security;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Exceptions\TierLimitExceededException;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TieredRateLimiterService
{
    /**
     * Tier quota configurations.
     */
    protected const TIER_LIMITS = [
        'new' => [
            'per_minute' => 5,
            'per_hour' => 20,
            'daily_destinations' => 10,
            'max_bulk_batch' => 3,
            'label_key' => 'services.security.rate_limiter.tiers.new_user',
        ],
        'standard' => [
            'per_minute' => 30,
            'per_hour' => 200,
            'daily_destinations' => 50,
            'max_bulk_batch' => 50,
            'label_key' => 'services.security.rate_limiter.tiers.standard_user',
        ],
        'trusted' => [
            'per_minute' => 100,
            'per_hour' => 1000,
            'daily_destinations' => 500,
            'max_bulk_batch' => 500,
            'label_key' => 'services.security.rate_limiter.tiers.verified_user',
        ],
        'restricted' => [
            'per_minute' => 0,
            'per_hour' => 0,
            'daily_destinations' => 0,
            'max_bulk_batch' => 0,
            'label_key' => 'services.security.rate_limiter.tiers.restricted_account',
        ],
    ];

    /**
     * Resolve user's active trust tier.
     */
    public function resolveTier(User $user): string
    {
        if ($user->isSuspended() || $user->trust_tier === 'restricted') {
            return 'restricted';
        }

        if ($user->isAdmin() || $user->trust_tier === 'trusted') {
            return 'trusted';
        }

        if ($user->trust_tier === 'standard') {
            return 'standard';
        }

        // Cache tier determination for 10 minutes to reduce repeated queries
        return Cache::remember("user_tier_{$user->id}", 600, function () use ($user) {
            // New account: registered less than 7 days ago OR sent fewer than 5 successful outbound messages
            $isYoung = $user->created_at->diffInDays(now()) < 7;
            if ($isYoung) {
                return 'new';
            }

            $sentCount = $user->messages()
                ->where('direction', MessageDirection::Outbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->count();

            if ($sentCount < 5) {
                return 'new';
            }

            return 'standard';
        });
    }

    /**
     * Get limits configuration for a user.
     */
    public function getLimits(User $user): array
    {
        $tier = $this->resolveTier($user);
        $limits = self::TIER_LIMITS[$tier];
        $limits['tier'] = $tier;
        $limits['label'] = __($limits['label_key'] ?? 'services.security.rate_limiter.tiers.new_user');
        return $limits;
    }

    /**
     * Enforce rate limits before dispatching messages.
     *
     * @throws TierLimitExceededException
     */
    public function enforceLimits(User $user, int $destinationsCount = 1): void
    {
        if ($user->isAdmin()) {
            return; // Admins bypass rate limits
        }

        $limits = $this->getLimits($user);
        $tier = $limits['tier'];

        if ($tier === 'restricted') {
            throw new TierLimitExceededException(
                message: __('services.security.rate_limiter.restricted_account_blocked'),
                tier: $tier,
                limitType: 'restricted',
                limit: 0,
                retryAfterSeconds: 86400
            );
        }

        // 1. Check max bulk destinations in a single request
        if ($destinationsCount > $limits['max_bulk_batch']) {
            throw new TierLimitExceededException(
                message: __('services.security.rate_limiter.max_bulk_batch_exceeded', ['label' => $limits['label'], 'max' => $limits['max_bulk_batch']]),
                tier: $tier,
                limitType: 'bulk_batch_size',
                limit: $limits['max_bulk_batch'],
                retryAfterSeconds: 0
            );
        }

        // 2. Check per-minute velocity
        $minuteKey = "rate:msg:min:{$user->id}:" . now()->format('YmdHi');
        $currentMinuteCount = (int) Cache::get($minuteKey, 0);

        if (($currentMinuteCount + $destinationsCount) > $limits['per_minute']) {
            $this->logRateLimitIncident($user, 'velocity_minute', $limits['per_minute']);
            throw new TierLimitExceededException(
                message: __('services.security.rate_limiter.per_minute_exceeded', ['count' => $limits['per_minute']]),
                tier: $tier,
                limitType: 'per_minute',
                limit: $limits['per_minute'],
                retryAfterSeconds: 60 - (int) now()->format('s')
            );
        }

        // 3. Check per-hour velocity
        $hourKey = "rate:msg:hr:{$user->id}:" . now()->format('YmdH');
        $currentHourCount = (int) Cache::get($hourKey, 0);

        if (($currentHourCount + $destinationsCount) > $limits['per_hour']) {
            $this->logRateLimitIncident($user, 'velocity_hour', $limits['per_hour']);
            throw new TierLimitExceededException(
                message: __('services.security.rate_limiter.per_hour_exceeded', ['count' => $limits['per_hour']]),
                tier: $tier,
                limitType: 'per_hour',
                limit: $limits['per_hour'],
                retryAfterSeconds: 3600
            );
        }

        // 4. Check daily destinations quota
        $dayKey = "rate:msg:day:{$user->id}:" . now()->format('Ymd');
        $currentDayCount = (int) Cache::get($dayKey, 0);

        if (($currentDayCount + $destinationsCount) > $limits['daily_destinations']) {
            $this->logRateLimitIncident($user, 'daily_destinations', $limits['daily_destinations']);
            throw new TierLimitExceededException(
                message: __('services.security.rate_limiter.daily_destinations_exceeded', ['count' => $limits['daily_destinations']]),
                tier: $tier,
                limitType: 'daily_destinations',
                limit: $limits['daily_destinations'],
                retryAfterSeconds: (int) now()->endOfDay()->diffInSeconds(now())
            );
        }

        // Increment cache counters
        Cache::increment($minuteKey, $destinationsCount);
        Cache::put($minuteKey, Cache::get($minuteKey), 120);

        Cache::increment($hourKey, $destinationsCount);
        Cache::put($hourKey, Cache::get($hourKey), 7200);

        Cache::increment($dayKey, $destinationsCount);
        Cache::put($dayKey, Cache::get($dayKey), 172800);
    }

    protected function logRateLimitIncident(User $user, string $type, int $limit): void
    {
        // Avoid spamming incidents: log at most once per 5 minutes per user
        $lockKey = "incident_logged_rate_{$user->id}_{$type}";
        if (! Cache::add($lockKey, true, 300)) {
            return;
        }

        SecurityIncident::create([
            'user_id' => $user->id,
            'type' => 'rate_limit_tripped',
            'severity' => 'medium',
            'details' => [
                'limit_type' => $type,
                'limit_threshold' => $limit,
                'trust_tier' => $user->trust_tier,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'action_taken' => 'rate_limited',
        ]);
    }
}