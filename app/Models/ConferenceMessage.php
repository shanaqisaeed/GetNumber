<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConferenceMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conference_room_id',
        'user_id',
        'to_user_id',
        'message',
    ];

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isPrivate(): bool
    {
        return $this->to_user_id !== null;
    }

    public function scopeVisibleFor(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->whereNull('to_user_id')
              ->orWhere('user_id', $userId)
              ->orWhere('to_user_id', $userId);
        });
    }
}
