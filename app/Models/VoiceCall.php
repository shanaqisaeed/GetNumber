<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone_number_id',
        'source_identifier',
        'destination',
        'direction',
        'status',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'billed_minutes',
        'rate_per_minute',
        'cost',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
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
}

