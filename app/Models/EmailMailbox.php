<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMailbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'web3_domain_id',
        'address',
        'display_name',
        'status',
        'quota_mb',
        'used_mb',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quota_mb' => 'integer',
            'used_mb' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function web3Domain(): BelongsTo
    {
        return $this->belongsTo(Web3Domain::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(EmailAlias::class, 'mailbox_id');
    }
}

