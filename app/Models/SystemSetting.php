<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    /**
     * Cache key prefix for fast resolution.
     */
    protected const CACHE_PREFIX = 'system_setting_';

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    public static function set(string $key, mixed $value, ?string $description = null): self
    {
        Cache::forget(self::CACHE_PREFIX . $key);

        return self::updateOrCreate(
            ['key' => $key],
            [
                'value' => (string) $value,
                'description' => $description,
            ]
        );
    }

    /**
     * Get the active TRX to COIN exchange rate (Default 1 TRX = 1 COIN).
     */
    public static function getTrxToCoinRate(): string
    {
        return (string) self::get('trx_to_coin_rate', '1.0000');
    }

    /**
     * Get the active price of purchasing a phone number in COIN (Default 1 COIN).
     */
    public static function getNumberPriceCoin(): string
    {
        return (string) self::get('number_price_coin', '1.0000');
    }

    /**
     * Check if a given authentication provider is enabled in system settings.
     */
    public static function isAuthProviderEnabled(string $provider): bool
    {
        return (bool) (int) self::get("auth_{$provider}_enabled", in_array($provider, ['username', 'email']) ? '1' : '0');
    }

    /**
     * Get array of all currently enabled authentication providers.
     *
     * @return list<string>
     */
    public static function getEnabledAuthProviders(): array
    {
        $providers = ['username', 'email', 'ldap', 'keycloak'];
        $enabled = [];

        foreach ($providers as $provider) {
            if (self::isAuthProviderEnabled($provider)) {
                $enabled[] = $provider;
            }
        }

        // Guarantee at least username is enabled as a safeguard
        if (empty($enabled)) {
            $enabled[] = 'username';
        }

        return $enabled;
    }

    /**
     * Check if public registration is enabled.
     */
    public static function isRegistrationAllowed(): bool
    {
        return (bool) (int) self::get('allow_user_registration', '1');
    }

    /**
     * Check if specific event logging/notification is enabled.
     */
    public static function isEventNotificationEnabled(string $eventKey): bool
    {
        return (bool) (int) self::get("event_notify_{$eventKey}", '1');
    }
}

