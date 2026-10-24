<?php

namespace App\Models;

use App\Enums\ApiTokenStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'token_prefix',
        'abilities',
        'status',
        'last_used_at',
        'last_used_ip',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => ApiTokenStatus::class,
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Hidden attributes for serialization (never expose hash in API/Inertia props).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'token_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ApiTokenStatus::Active->value)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', ApiTokenStatus::Revoked->value);
    }

    public function isActive(): bool
    {
        return $this->status === ApiTokenStatus::Active;
    }

    public function isRevoked(): bool
    {
        return $this->status === ApiTokenStatus::Revoked;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->isActive() && ! $this->isExpired();
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? ['*'];

        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }
}
