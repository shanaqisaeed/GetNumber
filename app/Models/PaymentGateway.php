<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    protected $fillable = [
        'name',
        'driver',
        'is_active',
        'is_default',
        'supported_currencies',
        'supported_networks',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'supported_currencies' => 'array',
            'supported_networks' => 'array',
            'config' => 'array',
        ];
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CryptoDeposit::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public static function getDefault(): ?self
    {
        return static::active()->where('is_default', true)->first()
            ?? static::active()->first();
    }
}

