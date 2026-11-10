<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone_number_id',
        'type',
        'severity',
        'details',
        'ip_address',
        'user_agent',
        'action_taken',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (SecurityIncident $incident) {
            try {
                app(\App\Contracts\SystemCacheServiceInterface::class)->handleSecurityIncidentChanged($incident);
            } catch (\Throwable) {
            }
        });

        static::deleted(function (SecurityIncident $incident) {
            try {
                app(\App\Contracts\SystemCacheServiceInterface::class)->handleSecurityIncidentChanged($incident);
            } catch (\Throwable) {
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function markAsResolved(User $resolver): void
    {
        $this->update([
            'resolved_at' => now(),
            'resolved_by' => $resolver->id,
        ]);
    }
}