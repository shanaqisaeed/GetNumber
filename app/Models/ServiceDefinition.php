<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceDefinition extends Model
{
    protected $fillable = [
        'key',
        'driver_class',
        'category',
        'channel_type',
        'icon',
        'translation_prefix',
        'is_active',
        'is_purchasable',
        'requires_approval_for_enterprise',
        'available_for_roles',
        'form_schema',
        'validation_policies',
        'pricing_config',
        'metadata',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_purchasable' => 'boolean',
            'requires_approval_for_enterprise' => 'boolean',
            'available_for_roles' => 'array',
            'form_schema' => 'array',
            'validation_policies' => 'array',
            'pricing_config' => 'array',
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Plans belonging to this service definition.
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ServicePlan::class)->orderBy('sort_order');
    }

    /**
     * Orders placed for this service definition.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    /**
     * Resource allocations provisioned under this service definition.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ServiceResourceAllocation::class);
    }

    /**
     * Check whether this service is accessible by the given account tier ('individual' or 'enterprise').
     */
    public function isAvailableForTier(string $tier): bool
    {
        $roles = $this->available_for_roles ?? ['individual', 'enterprise'];

        return in_array($tier, $roles, true);
    }

    /**
     * Get the localized title of the service from lang/catalog.php.
     */
    public function getTitleAttribute(): string
    {
        return __($this->translation_prefix . '.title');
    }

    /**
     * Get the localized subtitle of the service from lang/catalog.php.
     */
    public function getSubtitleAttribute(): string
    {
        return __($this->translation_prefix . '.subtitle');
    }

    /**
     * Get the localized description of the service from lang/catalog.php.
     */
    public function getDescriptionAttribute(): string
    {
        return __($this->translation_prefix . '.description');
    }
}

