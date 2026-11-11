<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServiceResourceAllocation extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'service_definition_id',
        'service_order_id',
        'service_key',
        'channel_type',
        'resource_identifier',
        'display_title',
        'account_tier',
        'tier_badge',
        'status',
        'total_quota',
        'used_quota',
        'phone_number_id',
        'capabilities',
        'routing_config',
        'metadata',
        'allocated_at',
        'expires_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'total_quota' => 'integer',
            'used_quota' => 'integer',
            'capabilities' => 'array',
            'routing_config' => 'array',
            'metadata' => 'array',
            'allocated_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation) {
            if (empty($allocation->uuid)) {
                $allocation->uuid = (string) Str::uuid();
            }
            if (empty($allocation->allocated_at)) {
                $allocation->allocated_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function subResources(): HasMany
    {
        return $this->hasMany(ServiceSubResource::class, 'parent_allocation_id')->orderByDesc('id');
    }

    public function remainingQuota(): int
    {
        return max(0, (int) $this->total_quota - (int) $this->used_quota);
    }

    public function hasAvailableQuota(int $needed = 1): bool
    {
        return $this->remainingQuota() >= $needed;
    }
}

