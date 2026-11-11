<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePlan extends Model
{
    protected $fillable = [
        'service_definition_id',
        'code',
        'target_tier',
        'billing_mode',
        'base_price',
        'quota_units',
        'is_active',
        'metadata',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:4',
            'quota_units' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class);
    }

    public function getTitleAttribute(): string
    {
        return __('catalog.plans.' . $this->code . '.title');
    }

    public function getDescriptionAttribute(): string
    {
        return __('catalog.plans.' . $this->code . '.description');
    }
}

