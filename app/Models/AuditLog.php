<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'payload',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record a system audit event.
     *
     * @param array<string, mixed>|null $payload
     */
    public static function record(
        string $action,
        ?Model $entity = null,
        ?User $actor = null,
        ?array $payload = null,
        ?string $ipAddress = null
    ): self {
        $actorUser = $actor ?? (auth()->check() ? auth()->user() : null);

        return self::create([
            'user_id' => $actorUser?->id,
            'action' => $action,
            'entity_type' => $entity ? get_class($entity) : null,
            'entity_id' => $entity?->getKey(),
            'payload' => $payload,
            'ip_address' => $ipAddress ?? request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get a human-readable Persian label for the action.
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'user_created' => __('models.audit_log.actions.user_created'),
            'user_updated' => __('models.audit_log.actions.user_updated'),
            'user_status_changed' => __('models.audit_log.actions.user_status_changed'),
            'user_role_changed' => __('models.audit_log.actions.user_role_changed'),
            'number_created' => __('models.audit_log.actions.number_created'),
            'number_assigned' => __('models.audit_log.actions.number_assigned'),
            'number_released' => __('models.audit_log.actions.number_released'),
            'number_status_changed' => __('models.audit_log.actions.number_status_changed'),
            'request_submitted' => __('models.audit_log.actions.request_submitted'),
            'request_approved' => __('models.audit_log.actions.request_approved'),
            'request_rejected' => __('models.audit_log.actions.request_rejected'),
            'token_created' => __('models.audit_log.actions.token_created'),
            'token_rotated' => __('models.audit_log.actions.token_rotated'),
            'token_revoked' => __('models.audit_log.actions.token_revoked'),
            'message_deleted' => __('models.audit_log.actions.message_deleted'),
            'message_sent' => __('models.audit_log.actions.message_sent'),
            default => $this->action,
        };
    }

    /**
     * Get CSS badge classes for the action.
     */
    public function getActionBadgeAttribute(): string
    {
        return match ($this->action) {
            'user_created', 'number_created', 'token_created', 'request_approved' =>
                'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30',
            'number_assigned', 'user_updated', 'token_rotated' =>
                'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30',
            'number_released', 'request_rejected', 'token_revoked', 'message_deleted' =>
                'bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30',
            'user_status_changed', 'user_role_changed', 'number_status_changed' =>
                'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30',
            default =>
                'bg-slate-500/15 text-slate-600 dark:text-slate-400 border border-slate-500/30',
        };
    }
}