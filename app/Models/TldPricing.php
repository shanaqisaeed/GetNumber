<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TldPricing extends Model
{
    use HasFactory;

    protected $table = 'tld_pricing';

    protected $fillable = [
        'tld',
        'base_price',
        'multiplier_rond',
        'multiplier_semi_rond',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:4',
            'multiplier_rond' => 'decimal:2',
            'multiplier_semi_rond' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function web3Domains(): HasMany
    {
        return $this->hasMany(Web3Domain::class);
    }
}

