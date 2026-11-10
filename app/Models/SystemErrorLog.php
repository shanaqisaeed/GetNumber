<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemErrorLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'error_hash',
        'source',
        'level',
        'exception_class',
        'message',
        'file',
        'line',
        'stack_trace',
        'url',
        'user_id',
        'ip_address',
        'user_agent',
        'correlation_id',
        'occurrences_count',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
    ];

    protected $attributes = [
        'occurrences_count' => 1,
        'level' => 'error',
        'source' => 'backend',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'line' => 'integer',
            'occurrences_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function markAsResolved(User $resolver): void
    {
        $this->update([
            'resolved_at' => now(),
            'resolved_by' => $resolver->id,
        ]);
    }
}