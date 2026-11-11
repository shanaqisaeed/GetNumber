<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceSubResource extends Model
{
    protected $fillable = [
        'parent_allocation_id',
        'user_id',
        'sub_identifier',
        'full_address',
        'display_name',
        'status',
        'routing_metadata',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'routing_metadata' => 'array',
            'metadata' => 'array',
        ];
    }

    public function parentAllocation(): BelongsTo
    {
        return $this->belongsTo(ServiceResourceAllocation::class, 'parent_allocation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

