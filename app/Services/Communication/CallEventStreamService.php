<?php

namespace App\Services\Communication;

use App\Models\CallLifecycleEvent;
use App\Models\VoiceCall;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Call Lifecycle Event Stream Service (Stage 2.4 & Stage 4.4).
 *
 * Implements:
 * - Database source-of-truth persistence (`call_lifecycle_events`) with idempotent `event_id`
 * - Schema-versioned event envelope (`schema_version = 1`)
 * - Optional Redis Streams publishing (`XADD ravy-no:stream:calls`) and Dead-Letter Queue (`ravy-no:stream:calls:dlq`)
 *   when Redis is configured in the LAN environment.
 */
class CallEventStreamService
{
    public const STREAM_KEY = 'ravy-no:stream:calls';
    public const DLQ_STREAM_KEY = 'ravy-no:stream:calls:dlq';
    public const SCHEMA_VERSION = 1;

    /**
     * Publish a call lifecycle state change event idempotently.
     *
     * @param array<string, mixed> $extraPayload
     */
    public function publishStateTransition(
        VoiceCall $call,
        ?string $previousState,
        string $currentState,
        string $eventType = 'call.state_changed',
        ?string $actorDeviceUuid = null,
        array $extraPayload = [],
        ?string $deterministicEventId = null
    ): CallLifecycleEvent {
        $callUuid = $call->call_uuid ?: ('call-' . $call->id);
        $eventId = $deterministicEventId ?: ('evt_' . Str::ulid()->toBase32());
        $correlationId = $call->correlation_id ?: ('corr-' . $callUuid);

        // Idempotency guarantee: if this exact event_id was already recorded, return existing record without re-emitting
        $existing = CallLifecycleEvent::where('event_id', $eventId)->first();
        if ($existing) {
            return $existing;
        }

        $envelope = array_merge([
            'event_id' => $eventId,
            'schema_version' => self::SCHEMA_VERSION,
            'event_type' => $eventType,
            'call_uuid' => $callUuid,
            'call_id' => $call->id,
            'user_id' => $call->user_id,
            'previous_state' => $previousState,
            'current_state' => $currentState,
            'actor_device_uuid' => $actorDeviceUuid,
            'correlation_id' => $correlationId,
            'occurred_at_ms' => (int) round(microtime(true) * 1000),
        ], $extraPayload);

        $eventRecord = CallLifecycleEvent::create([
            'event_id' => $eventId,
            'schema_version' => self::SCHEMA_VERSION,
            'event_type' => $eventType,
            'call_uuid' => $callUuid,
            'call_id' => $call->id,
            'user_id' => $call->user_id,
            'previous_state' => $previousState,
            'current_state' => $currentState,
            'actor_device_uuid' => $actorDeviceUuid,
            'correlation_id' => $correlationId,
            'payload' => $envelope,
            'processed_at' => now(),
            'created_at' => now(),
        ]);

        // If Redis Streams is enabled in this environment, also append to Redis Stream
        $this->appendToRedisStreamIfEnabled(self::STREAM_KEY, $envelope);

        return $eventRecord;
    }

    /**
     * Process an incoming event envelope idempotently (Consumer helper).
     *
     * @param array<string, mixed> $envelope
     * @param callable(array<string, mixed>): void $handler
     */
    public function consumeIdempotently(array $envelope, callable $handler, int $maxRetries = 3): bool
    {
        $eventId = (string) ($envelope['event_id'] ?? '');
        if ($eventId === '') {
            return false;
        }

        $existing = CallLifecycleEvent::where('event_id', $eventId)
            ->whereNotNull('processed_at')
            ->exists();

        if ($existing) {
            return false; // Duplicate event ignored
        }

        $attempt = 0;
        $lastError = null;
        while ($attempt < $maxRetries) {
            $attempt++;
            try {
                $handler($envelope);

                CallLifecycleEvent::updateOrCreate(
                    ['event_id' => $eventId],
                    [
                        'schema_version' => (int) ($envelope['schema_version'] ?? self::SCHEMA_VERSION),
                        'event_type' => (string) ($envelope['event_type'] ?? 'call.custom'),
                        'call_uuid' => (string) ($envelope['call_uuid'] ?? 'unknown'),
                        'call_id' => isset($envelope['call_id']) ? (int) $envelope['call_id'] : null,
                        'user_id' => isset($envelope['user_id']) ? (int) $envelope['user_id'] : null,
                        'previous_state' => $envelope['previous_state'] ?? null,
                        'current_state' => $envelope['current_state'] ?? null,
                        'actor_device_uuid' => $envelope['actor_device_uuid'] ?? null,
                        'correlation_id' => $envelope['correlation_id'] ?? null,
                        'payload' => $envelope,
                        'processed_at' => now(),
                    ]
                );

                return true;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        // Move failed event after retries to Dead-Letter Queue
        $dlqEnvelope = array_merge($envelope, [
            'dlq_error' => $lastError,
            'dlq_failed_at_ms' => (int) round(microtime(true) * 1000),
            'attempts' => $attempt,
        ]);

        CallLifecycleEvent::updateOrCreate(
            ['event_id' => $eventId],
            [
                'schema_version' => (int) ($envelope['schema_version'] ?? self::SCHEMA_VERSION),
                'event_type' => 'dlq.' . ($envelope['event_type'] ?? 'failed'),
                'call_uuid' => (string) ($envelope['call_uuid'] ?? 'unknown'),
                'payload' => $dlqEnvelope,
                'processed_at' => null,
            ]
        );

        $this->appendToRedisStreamIfEnabled(self::DLQ_STREAM_KEY, $dlqEnvelope);

        return false;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    protected function appendToRedisStreamIfEnabled(string $streamKey, array $envelope): void
    {
        if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis' && ! env('CALL_REDIS_STREAMS_ENABLED', false)) {
            return;
        }

        try {
            Redis::command('XADD', [
                $streamKey,
                'MAXLEN',
                '~',
                '10000',
                '*',
                'event_id',
                (string) ($envelope['event_id'] ?? ''),
                'event_type',
                (string) ($envelope['event_type'] ?? ''),
                'call_uuid',
                (string) ($envelope['call_uuid'] ?? ''),
                'payload',
                json_encode($envelope, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // Database remains the authoritative store even if Redis is temporarily down
        }
    }
}
