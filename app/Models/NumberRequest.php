<?php

namespace App\Models;

use App\Enums\NumberRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberRequest extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'requested_length',
        'service_title',
        'description',
        'preferred_number',
        'status',
        'rejection_reason',
        'allocated_number_id',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => NumberRequestStatus::class,
        'requested_length' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /**
     * The user who submitted the request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The admin reviewer who approved or rejected the request.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The allocated phone number if approved.
     */
    public function allocatedNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'allocated_number_id');
    }

    /**
     * Scope for pending requests.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', NumberRequestStatus::Pending->value);
    }

    /**
     * Scope for approved requests.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', NumberRequestStatus::Approved->value);
    }

    /**
     * Scope for rejected requests.
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', NumberRequestStatus::Rejected->value);
    }

    public function isPending(): bool
    {
        return $this->status === NumberRequestStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === NumberRequestStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === NumberRequestStatus::Rejected;
    }
}
