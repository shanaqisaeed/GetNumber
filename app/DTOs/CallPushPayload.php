<?php

namespace App\DTOs;

class CallPushPayload
{
    public function __construct(
        public readonly string $callUuid,
        public readonly int $callId,
        public readonly string $callerHandle,
        public readonly string $callerDisplayName,
        public readonly string $destinationLine,
        public readonly bool $hasVideo = false,
        public readonly int $issuedAt = 0,
        public readonly int $expiresAt = 0,
        public readonly string $correlationId = '',
        public readonly string $eventType = 'call.invite',
    ) {}

    /**
     * Minimal, privacy-safe payload for APNs VoIP, FCM, and Local Push.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $now = time();
        return [
            'schema_version' => 1,
            'event_type' => $this->eventType,
            'call_uuid' => $this->callUuid,
            'call_id' => $this->callId,
            'caller_handle' => $this->callerHandle,
            'caller_display_name' => $this->callerDisplayName ?: $this->callerHandle,
            'destination_line' => $this->destinationLine,
            'has_video' => $this->hasVideo,
            'issued_at' => $this->issuedAt > 0 ? $this->issuedAt : $now,
            'expires_at' => $this->expiresAt > 0 ? $this->expiresAt : ($now + 45),
            'correlation_id' => $this->correlationId ?: ('corr-' . $this->callUuid),
        ];
    }

    /**
     * String-only map required by FCM HTTP v1 `data` payload.
     *
     * @return array<string, string>
     */
    public function toFcmDataMap(): array
    {
        $arr = $this->toArray();
        return [
            'schema_version' => (string) $arr['schema_version'],
            'event_type' => (string) $arr['event_type'],
            'call_uuid' => (string) $arr['call_uuid'],
            'call_id' => (string) $arr['call_id'],
            'caller_handle' => (string) $arr['caller_handle'],
            'caller_display_name' => (string) $arr['caller_display_name'],
            'destination_line' => (string) $arr['destination_line'],
            'has_video' => $this->hasVideo ? 'true' : 'false',
            'issued_at' => (string) $arr['issued_at'],
            'expires_at' => (string) $arr['expires_at'],
            'correlation_id' => (string) $arr['correlation_id'],
        ];
    }
}
