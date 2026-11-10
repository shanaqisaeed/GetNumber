<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivedMessage extends Model
{
    use HasFactory;

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'phone_number_id',
        'destination_number',
        'sender',
        'peer_number',
        'body',
        'direction',
        'processing_status',
        'source_time',
        'server_received_at',
        'source_system_id',
        'client_message_id',
        'original_created_at',
        'original_updated_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'processing_status' => MessageProcessingStatus::class,
            'source_time' => 'datetime',
            'server_received_at' => 'datetime',
            'original_created_at' => 'datetime',
            'original_updated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }
}