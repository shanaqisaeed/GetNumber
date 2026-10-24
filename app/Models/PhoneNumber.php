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
        'phone_prefix_id',
        'number',
        'type',
        'status',
        'display_title',
        'user_id',
        'allocated_at',
        'reserved_until',
        'expires_at',
        'accepts_incoming_sms',
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
            'reserved_until' => 'datetime',
            'expires_at' => 'datetime',
            'accepts_incoming_sms' => 'boolean',
        ];
    }

    /**
     * Relationship with the dynamic prefix definition.
     */
    public function prefix(): BelongsTo
    {
        return $this->belongsTo(PhonePrefix::class, 'phone_prefix_id');
    }

    public function phonePrefix(): BelongsTo
    {
        return $this->prefix();
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
     * Relationship with inbound messages received on this number.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderByDesc('server_received_at');
    }

    /**
     * Relationship with support tickets linked to this number.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Formatted presentation number using dynamic prefix.
     */
    public function getFormattedNumberAttribute(): string
    {
        $prefixStr = $this->prefix?->prefix ?? PhonePrefix::getDefaultPrefixString();

        return $prefixStr . $this->number;
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

    public function isReserved(): bool
    {
        return $this->status === PhoneNumberStatus::Reserved
            && (! $this->reserved_until || $this->reserved_until->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->status === PhoneNumberStatus::Expired
            || ($this->expires_at && $this->expires_at->isPast());
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

    /**
     * Determine if this phone number can receive inbound messages from a given sender.
     * Implements validation for 9-digit standard lines vs 4-8 digit service lines.
     */
    public function canReceiveMessagesFrom(PhoneNumber $senderLine, ?User $senderUser = null): array
    {
        // 1. Must be allocated
        if ($this->status !== PhoneNumberStatus::Allocated) {
            return [
                'allowed' => false,
                'reason' => "خط مقصد در وضعیت «{$this->status->label()}» بوده و آماده دریافت پیامک نمی‌باشد.",
            ];
        }

        // 2. Explicit incoming SMS flag check (applies to all, especially non-9-digit service lines)
        if (! $this->accepts_incoming_sms) {
            return [
                'allowed' => false,
                'reason' => 'این سرشماره برای عدم دریافت پیامک ورودی (صرفاً یک‌طرفه/اطلاع‌رسانی) پیکربندی شده است.',
            ];
        }

        // 3. Platform Service non-9-digit line checks
        if ($this->type === PhoneNumberType::PlatformService) {
            if (! $senderUser?->isAdmin()) {
                return [
                    'allowed' => false,
                    'reason' => 'ارسال پیامک به این سرشماره اختصاصی سامانه توسط کاربران عادی مجاز نمی‌باشد.',
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
        ];
    }
}
