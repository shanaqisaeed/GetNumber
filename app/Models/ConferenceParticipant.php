<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConferenceParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'conference_room_id',
        'user_id',
        'phone_number',
        'display_name',
        'status',
        'role',
        'is_muted',
        'is_camera_on',
        'can_share_screen',
        'is_sharing_screen',
        'hand_raised',
        'hand_raised_at',
        'ip_address',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'is_muted' => 'boolean',
            'is_camera_on' => 'boolean',
            'can_share_screen' => 'boolean',
            'is_sharing_screen' => 'boolean',
            'hand_raised' => 'boolean',
            'hand_raised_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeAdmitted(Builder $query): Builder
    {
        return $query->where('status', 'admitted');
    }

    public function scopeWaitingApproval(Builder $query): Builder
    {
        return $query->where('status', 'waiting_approval');
    }

    public function scopeHosts(Builder $query): Builder
    {
        return $query->whereIn('role', ['host', 'co_host']);
    }

    public function isHost(): bool
    {
        return in_array($this->role, ['host', 'co_host'], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['waiting_approval', 'admitted'], true);
    }
}
