<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Web3Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tld_pricing_id',
        'domain_name',
        'tld',
        'tier_badge',
        'status',
        'max_sub_emails',
        'registered_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'max_sub_emails' => 'integer',
            'registered_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tldPricing(): BelongsTo
    {
        return $this->belongsTo(TldPricing::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(EmailMailbox::class);
    }

    public function getFullDomainAttribute(): string
    {
        return $this->domain_name . '.' . ltrim($this->tld, '.');
    }
}

