<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConferencePoll extends Model
{
    use HasFactory;

    protected $fillable = [
        'conference_room_id',
        'created_by_user_id',
        'question',
        'options',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function conferenceRoom(): BelongsTo
    {
        return $this->belongsTo(ConferenceRoom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ConferencePollVote::class);
    }

    public function getResultsSummary(): array
    {
        $totalVotes = $this->votes()->count();
        $voteCounts = $this->votes()
            ->selectRaw('option_index, count(*) as count')
            ->groupBy('option_index')
            ->pluck('count', 'option_index')
            ->toArray();

        $options = $this->options ?? [];
        $summary = [];

        foreach ($options as $index => $label) {
            $count = (int) ($voteCounts[$index] ?? 0);
            $percentage = $totalVotes > 0 ? round(($count / $totalVotes) * 100, 1) : 0;
            $summary[] = [
                'index' => $index,
                'label' => $label,
                'votes' => $count,
                'percentage' => $percentage,
            ];
        }

        return [
            'total_votes' => $totalVotes,
            'options' => $summary,
        ];
    }
}
