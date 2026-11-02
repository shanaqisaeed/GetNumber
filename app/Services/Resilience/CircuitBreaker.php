<?php

namespace App\Services\Resilience;

use App\Exceptions\CircuitBreakerOpenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CircuitBreaker
{
    public const STATE_CLOSED = 'CLOSED';
    public const STATE_OPEN = 'OPEN';
    public const STATE_HALF_OPEN = 'HALF_OPEN';

    /**
     * Execute a callable within circuit breaker protection.
     *
     * @template T
     * @param callable(): T $action
     * @param (callable(Throwable): T)|null $fallback
     * @return T
     *
     * @throws CircuitBreakerOpenException|Throwable
     */
    public function execute(
        string $serviceName,
        callable $action,
        int $failureThreshold = 5,
        int $cooldownSeconds = 60,
        ?callable $fallback = null
    ): mixed {
        $state = $this->getState($serviceName, $cooldownSeconds);

        if ($state === self::STATE_OPEN) {
            $ttl = (int) Cache::get("cb:{$serviceName}:open_until", 0) - time();
            $retryAfter = max(1, $ttl);

            if ($fallback !== null) {
                return $fallback(new CircuitBreakerOpenException($serviceName, $retryAfter));
            }

            throw new CircuitBreakerOpenException($serviceName, $retryAfter);
        }

        try {
            $result = $action();
            $this->onSuccess($serviceName);
            return $result;
        } catch (Throwable $e) {
            $this->onFailure($serviceName, $failureThreshold, $cooldownSeconds);

            if ($fallback !== null) {
                return $fallback($e);
            }

            throw $e;
        }
    }

    /**
     * Get current state of the circuit.
     */
    public function getState(string $serviceName, int $cooldownSeconds): string
    {
        $openUntil = (int) Cache::get("cb:{$serviceName}:open_until", 0);

        if ($openUntil > time()) {
            return self::STATE_OPEN;
        }

        if ($openUntil > 0 && $openUntil <= time()) {
            return self::STATE_HALF_OPEN;
        }

        return self::STATE_CLOSED;
    }

    protected function onSuccess(string $serviceName): void
    {
        Cache::forget("cb:{$serviceName}:failures");
        Cache::forget("cb:{$serviceName}:open_until");
    }

    protected function onFailure(string $serviceName, int $failureThreshold, int $cooldownSeconds): void
    {
        $failKey = "cb:{$serviceName}:failures";
        $failures = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $failures, 120);

        if ($failures >= $failureThreshold) {
            $openUntil = time() + $cooldownSeconds;
            Cache::put("cb:{$serviceName}:open_until", $openUntil, $cooldownSeconds + 60);

            Log::warning("Circuit breaker TRIPPED to OPEN for service '{$serviceName}' ({$failures} failures).", [
                'service' => $serviceName,
                'cooldown_seconds' => $cooldownSeconds,
            ]);
        }
    }
}

