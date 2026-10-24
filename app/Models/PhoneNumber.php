<?php

namespace App\Models;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhoneNumber extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'type',
        'status',
        'display_title',
        'user_id',
        'allocated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PhoneNumberType::class,
            'status' => PhoneNumberStatus::class,
            'allocated_at' => 'datetime',
        ];
    }

    /**
     * Relationship with the owner user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship with audit trails.
     */
    public function audits(): HasMany
    {
        return $this->hasMany(PhoneNumberAudit::class)->orderByDesc('created_at');
    }

    /**
     * Formatted presentation number (with +85 for standard numbers).
     */
    public function getFormattedNumberAttribute(): string
    {
        if ($this->type === PhoneNumberType::UserStandard) {
            return '+85' . $this->number;
        }

        return $this->number;
    }

    /**
     * Effective title according to Domain Rule 5.4:
     * If display_title exists, use it; otherwise use formatted number.
     */
    public function getEffectiveTitleAttribute(): string
    {
        if (! empty($this->display_title)) {
            return $this->display_title;
        }

        return $this->formatted_number;
    }

    public function isAvailable(): bool
    {
        return $this->status === PhoneNumberStatus::Available;
    }

    public function isAllocated(): bool
    {
        return $this->status === PhoneNumberStatus::Allocated;
    }

    public function isSuspended(): bool
    {
        return $this->status === PhoneNumberStatus::Suspended;
    }

    /**
     * Scope for available numbers.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', PhoneNumberStatus::Available);
    }

    /**
     * Scope for allocated numbers.
     */
    public function scopeAllocated(Builder $query): Builder
    {
        return $query->where('status', PhoneNumberStatus::Allocated);
    }

    /**
     * Scope by type.
     */
    public function scopeOfType(Builder $query, PhoneNumberType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Request through which this number was allocated, if applicable.
     */
    public function numberRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NumberRequest::class, 'allocated_number_id');
    }
}
