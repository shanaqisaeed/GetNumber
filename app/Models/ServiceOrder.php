<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceOrder extends Model
{
    protected $fillable = [
        'order_number',
        'user_id',
        'service_definition_id',
        'service_plan_id',
        'account_tier',
        'order_context',
        'fulfillment_mode',
        'status',
        'requested_identifier',
        'unit_price',
        'discount_amount',
        'final_amount',
        'held_amount',
        'wallet_reference_id',
        'number_request_id',
        'input_payload',
        'validation_snapshot',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'final_amount' => 'decimal:4',
            'held_amount' => 'decimal:4',
            'input_payload' => 'array',
            'validation_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class);
    }

    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(ServiceResourceAllocation::class, 'service_order_id');
    }

    public function numberRequest(): BelongsTo
    {
        return $this->belongsTo(NumberRequest::class);
    }
}

