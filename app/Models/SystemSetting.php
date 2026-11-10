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
     * Get the default system locale ('fa' or 'en').
     */
    public static function getDefaultLocale(): string
    {
        return (string) self::get('default_locale', 'fa');
    }

    /**
     * Get direction for given or active locale ('rtl' or 'ltr').
     */
    public static function getLocaleDirection(?string $locale = null): string
    {
        $loc = $locale ?: app()->getLocale();
        return $loc === 'fa' ? 'rtl' : 'ltr';
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

    /**
     * Get tariff for 4-8 digit organizational service number.
     */
    public static function getOrgNumberPrice(int $length, bool $isRond): string
    {
        $clampedLength = max(4, min(8, $length));
        $typeKey = $isRond ? 'rond' : 'regular';
        $settingKey = "org_price_{$clampedLength}_{$typeKey}";

        $fallbackDefaults = [
            'org_price_4_regular' => '100.0000',
            'org_price_4_rond' => '250.0000',
            'org_price_5_regular' => '50.0000',
            'org_price_5_rond' => '120.0000',
            'org_price_6_regular' => '20.0000',
            'org_price_6_rond' => '50.0000',
            'org_price_7_regular' => '10.0000',
            'org_price_7_rond' => '25.0000',
            'org_price_8_regular' => '5.0000',
            'org_price_8_rond' => '12.0000',
        ];

        return (string) self::get($settingKey, $fallbackDefaults[$settingKey] ?? '10.0000');
    }

    /**
     * Get entire 10-field matrix for organizational service numbers.
     *
     * @return array<int, array{regular: string, rond: string}>
     */
    public static function getAllOrgPricing(): array
    {
        $matrix = [];
        for ($len = 4; $len <= 8; $len++) {
            $matrix[$len] = [
                'regular' => self::getOrgNumberPrice($len, false),
                'rond' => self::getOrgNumberPrice($len, true),
            ];
        }

        return $matrix;
    }
}

