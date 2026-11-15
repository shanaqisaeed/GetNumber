<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallSignal extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'voice_call_id',
        'sender_call_id',
        'type',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}

