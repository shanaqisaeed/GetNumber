<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConferencePollVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'conference_poll_id',
        'user_id',
        'option_index',
    ];

    protected function casts(): array
    {
        return [
            'option_index' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(ConferencePoll::class, 'conference_poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
