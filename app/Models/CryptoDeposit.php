<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoDeposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payment_gateway_id',
        'gateway_driver',
        'tracking_code',
        'currency',
        'network',
        'requested_amount',
        'coin_amount',
        'trx_to_coin_rate',
        'deposit_address',
        'deposit_tag',
        'tx_hash',
        'actual_amount',
        'status',
        'failure_reason',
        'attempts',
        'last_checked_at',
        'next_check_at',
        'expires_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'requested_amount' => 'decimal:4',
            'coin_amount' => 'decimal:4',
            'trx_to_coin_rate' => 'decimal:4',
            'actual_amount' => 'decimal:4',
            'attempts' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    public function scopePendingVerification(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::TxSubmitted->value,
            PaymentStatus::Verifying->value,
        ]);
    }

    public function scopeDueForCheck(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::TxSubmitted->value,
            PaymentStatus::Verifying->value,
        ])
        ->where(function ($q) {
            $q->whereNull('next_check_at')
              ->orWhere('next_check_at', '<=', now());
        })
        ->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    public function canSubmitTx(): bool
    {
        return ! $this->isExpired() && $this->status->canSubmitTx();
    }

    public function canBeVerified(): bool
    {
        return ! $this->isExpired() && $this->status->canBeVerified();
    }
}

