<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageBatch extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIALLY_FAILED = 'partially_failed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'batch_id',
        'user_id',
        'phone_number_id',
        'sender',
        'body',
        'total_count',
        'processed_count',
        'success_count',
        'failed_count',
        'status',
        'metadata',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'success_count' => 'integer',
            'failed_count' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(MessageBatchItem::class);
    }

    public function progressPercentage(): int
    {
        if ($this->total_count === 0) {
            return 100;
        }

        return (int) min(100, round(($this->processed_count / $this->total_count) * 100));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_PARTIALLY_FAILED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}

