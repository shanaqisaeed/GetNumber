<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCall extends Model
{
    use HasFactory;

    // Canonical Stage 2.2 Call Lifecycle States (with legacy aliases supported)
    public const STATE_INITIATED = 'initiated';
    public const STATE_RINGING = 'ringing';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_CONNECTING = 'connecting';
    public const STATE_ACTIVE = 'active';
    public const STATE_IN_CALL = 'in_call'; // Backward-compatible alias for active/accepted
    public const STATE_REJECTED = 'rejected';
    public const STATE_DECLINED = 'declined'; // Backward-compatible alias for rejected
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_MISSED = 'missed';
    public const STATE_FAILED = 'failed';
    public const STATE_ENDED = 'ended';
    public const STATE_COMPLETED = 'completed'; // Backward-compatible alias for ended

    public const TERMINAL_STATES = [
        self::STATE_REJECTED,
        self::STATE_DECLINED,
        self::STATE_CANCELLED,
        self::STATE_MISSED,
        self::STATE_FAILED,
        self::STATE_ENDED,
        self::STATE_COMPLETED,
    ];

    public const ACTIVE_STATES = [
        self::STATE_ACCEPTED,
        self::STATE_CONNECTING,
        self::STATE_ACTIVE,
        self::STATE_IN_CALL,
    ];

    protected $fillable = [
        'call_uuid',
        'user_id',
        'phone_number_id',
        'source_identifier',
        'destination',
        'direction',
        'status',
        'has_video',
        'accepted_device_id',
        'end_reason',
        'started_at',
        'ring_timeout_at',
        'answered_at',
        'last_pulse_at',
        'ended_at',
        'duration_seconds',
        'billed_minutes',
        'rate_per_minute',
        'cost',
        'metadata',
        'correlation_id',
        'user_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'has_video' => 'boolean',
            'started_at' => 'datetime',
            'ring_timeout_at' => 'datetime',
            'answered_at' => 'datetime',
            'last_pulse_at' => 'datetime',
            'ended_at' => 'datetime',
            'user_deleted_at' => 'datetime',
            'duration_seconds' => 'integer',
            'billed_minutes' => 'integer',
            'rate_per_minute' => 'decimal:4',
            'cost' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Normalize legacy DB statuses to canonical Stage 2.2 lifecycle names.
     */
    public function canonicalStatus(): string
    {
        return match ($this->status) {
            self::STATE_IN_CALL => self::STATE_ACTIVE,
            self::STATE_DECLINED => self::STATE_REJECTED,
            self::STATE_COMPLETED => self::STATE_ENDED,
            default => (string) $this->status,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    public function isRinging(): bool
    {
        return in_array($this->status, [self::STATE_INITIATED, self::STATE_RINGING], true);
    }

    public function isAcceptedOrActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATES, true);
    }

    /**
     * Check if transition from current state to target canonical state is valid.
     */
    public function canTransitionTo(string $targetCanonicalState): bool
    {
        $current = $this->canonicalStatus();

        if ($current === $targetCanonicalState) {
            return true; // Idempotent same-state transition
        }

        if ($this->isTerminal()) {
            return false; // Terminal states never transition back
        }

        $allowed = [
            self::STATE_INITIATED => [
                self::STATE_RINGING,
                self::STATE_ACCEPTED,
                self::STATE_CONNECTING,
                self::STATE_ACTIVE,
                self::STATE_REJECTED,
                self::STATE_CANCELLED,
                self::STATE_MISSED,
                self::STATE_FAILED,
                self::STATE_ENDED,
            ],
            self::STATE_RINGING => [
                self::STATE_ACCEPTED,
                self::STATE_CONNECTING,
                self::STATE_ACTIVE,
                self::STATE_REJECTED,
                self::STATE_CANCELLED,
                self::STATE_MISSED,
                self::STATE_FAILED,
                self::STATE_ENDED,
            ],
            self::STATE_ACCEPTED => [
                self::STATE_CONNECTING,
                self::STATE_ACTIVE,
                self::STATE_FAILED,
                self::STATE_ENDED,
            ],
            self::STATE_CONNECTING => [
                self::STATE_ACTIVE,
                self::STATE_FAILED,
                self::STATE_ENDED,
            ],
            self::STATE_ACTIVE => [
                self::STATE_CONNECTING, // Temporary network drop / ICE restart
                self::STATE_FAILED,
                self::STATE_ENDED,
            ],
        ];

        return in_array($targetCanonicalState, $allowed[$current] ?? [], true);
    }
}
