<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallLifecycleEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'schema_version',
        'event_type',
        'call_uuid',
        'call_id',
        'user_id',
        'previous_state',
        'current_state',
        'actor_device_uuid',
        'correlation_id',
        'payload',
        'processed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
