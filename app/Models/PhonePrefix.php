<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class PhonePrefix extends Model
{
    use HasFactory;

    protected $fillable = [
        'prefix',
        'clean_prefix',
        'name',
        'country_code',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PhonePrefix $model) {
            if (empty($model->clean_prefix) && ! empty($model->prefix)) {
                $model->clean_prefix = ltrim($model->prefix, '+');
            }
        });

        static::saved(function () {
            Cache::forget('phone_prefixes_active');
            Cache::forget('phone_prefix_default');
            Cache::forget('phone_prefix_default_id');
            Cache::forget('phone_prefixes_active_clean');
        });

        static::deleted(function () {
            Cache::forget('phone_prefixes_active');
            Cache::forget('phone_prefix_default');
            Cache::forget('phone_prefix_default_id');
            Cache::forget('phone_prefixes_active_clean');
        });
    }

    /**
     * Relationship with phone numbers using this prefix.
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'phone_prefix_id');
    }

    /**
     * Scope query to active prefixes only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to default prefix.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Retrieve the default PhonePrefix instance, caching scalar ID instead of raw Eloquent object
     * to completely eliminate __PHP_Incomplete_Class serialization issues.
     */
    public static function getDefaultPrefix(): self
    {
        // 1. Purge any legacy serialized model cache if exists
        try {
            if (Cache::has('phone_prefix_default')) {
                Cache::forget('phone_prefix_default');
            }
        } catch (\Throwable) {
            // Ignore cache error
        }

        // 2. Try fetching via cached scalar ID
        try {
            $defaultId = Cache::remember('phone_prefix_default_id', 3600, function () {
                $prefix = self::where('is_default', true)->where('is_active', true)->first()
                    ?? self::where('is_active', true)->first();

                return $prefix?->id;
            });

            if ($defaultId && is_numeric($defaultId)) {
                $instance = self::find($defaultId);
                if ($instance instanceof self && $instance->is_active) {
                    return $instance;
                }
            }
        } catch (\Throwable) {
            // Fall through to direct DB query
        }

        // 3. Direct DB query fallback
        try {
            $prefix = self::where('is_default', true)->where('is_active', true)->first()
                ?? self::where('is_active', true)->first();

            if ($prefix instanceof self) {
                return $prefix;
            }
        } catch (\Throwable) {
            // Fall through to virtual model
        }

        // 4. Memory virtual fallback if table is unseeded or during early migration
        return new self([
            'prefix' => '+85',
            'clean_prefix' => '85',
            'name' => 'پیش‌شماره سازمانی پیش‌فرض',
            'country_code' => 'INTL',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to get default prefix string (e.g. '+85').
     */
    public static function getDefaultPrefixString(): string
    {
        return self::getDefaultPrefix()->prefix;
    }

    /**
     * Get array of all clean digits of active prefixes (e.g. ['85', '98']).
     *
     * @return list<string>
     */
    public static function getActiveCleanPrefixes(): array
    {
        try {
            $prefixes = Cache::remember('phone_prefixes_active_clean', 3600, function () {
                return self::where('is_active', true)->pluck('clean_prefix')->toArray();
            });

            if (! is_array($prefixes) || empty($prefixes)) {
                return ['85'];
            }

            return array_values(array_filter($prefixes, fn ($p) => is_string($p) && strlen($p) > 0));
        } catch (\Throwable) {
            return ['85'];
        }
    }

    /**
     * Strips any registered active prefix from a raw phone number.
     */
    public static function stripPrefix(string $rawNumber): string
    {
        $trimmed = trim($rawNumber);

        // Strip leading '+'
        $withoutPlus = ltrim($trimmed, '+');

        // Check against active clean prefixes (sorted by length descending for greediness)
        $cleanPrefixes = self::getActiveCleanPrefixes();
        usort($cleanPrefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($cleanPrefixes as $clean) {
            if (str_starts_with($withoutPlus, $clean)) {
                return substr($withoutPlus, strlen($clean));
            }
        }

        return $trimmed;
    }
}
