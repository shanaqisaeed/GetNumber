<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConferenceRoom extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'room_uuid',
        'title',
        'pin_code',
        'admission_policy',
        'initial_audio_state',
        'webinar_mode',
        'screen_share_policy',
        'mute_others_on_share',
        'allow_whiteboard',
        'watermark_enabled',
        'is_locked',
        'max_participants',
        'is_active',
        'scheduled_start_at',
        'scheduled_end_at',
        'expires_at',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'webinar_mode' => 'boolean',
            'mute_others_on_share' => 'boolean',
            'allow_whiteboard' => 'boolean',
            'watermark_enabled' => 'boolean',
            'is_locked' => 'boolean',
            'is_active' => 'boolean',
            'max_participants' => 'integer',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_active_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConferenceParticipant::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->hasMany(ConferenceParticipant::class)
            ->whereIn('status', ['waiting_approval', 'admitted']);
    }

    public function admittedParticipants(): HasMany
    {
        return $this->hasMany(ConferenceParticipant::class)
            ->where('status', 'admitted');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConferenceMessage::class);
    }

    public function polls(): HasMany
    {
        return $this->hasMany(ConferencePoll::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', Carbon::now());
            });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', Carbon::now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isScheduledInFuture(): bool
    {
        return $this->scheduled_start_at !== null && $this->scheduled_start_at->isFuture();
    }

    public function isScheduledEnded(): bool
    {
        return $this->scheduled_end_at !== null && $this->scheduled_end_at->isPast();
    }

    public function hasActiveSeat(string $phoneNumber): bool
    {
        return $this->participants()
            ->where('phone_number', $phoneNumber)
            ->whereIn('status', ['waiting_approval', 'admitted'])
            ->exists();
    }
}
