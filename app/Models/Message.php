<?php

namespace App\Models;

use App\Enums\MessageProcessingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone_number_id',
        'destination_number',
        'sender',
        'body',
        'source_time',
        'server_received_at',
        'source_system_id',
        'client_message_id',
        'processing_status',
        'direction',
        'peer_number',
    ];

    protected $casts = [
        'source_time' => 'datetime',
        'server_received_at' => 'datetime',
        'processing_status' => MessageProcessingStatus::class,
        'direction' => \App\Enums\MessageDirection::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            if (! isset($message->attributes['direction'])) {
                $message->attributes['direction'] = \App\Enums\MessageDirection::Inbound->value;
            }

            if (empty($message->attributes['peer_number'])) {
                $dir = $message->attributes['direction'];
                $message->attributes['peer_number'] = ($dir === \App\Enums\MessageDirection::Outbound->value || $dir === 'outbound')
                    ? $message->destination_number
                    : $message->sender;
            }
        });
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Scope query to messages belonging to numbers of a specific user.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('phoneNumber', function (Builder $pq) use ($user) {
            $pq->where('user_id', $user->id);
        });
    }

    /**
     * Scope query for ordered inbound timeline.
     */
    public function scopeLatestReceived(Builder $query): Builder
    {
        return $query->latest('server_received_at')->latest('id');
    }
}
