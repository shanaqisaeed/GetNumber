<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'entry_number',
        'reference_type',
        'reference_id',
        'currency',
        'description',
        'posted_at',
        'created_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Verify mathematically that this journal entry is balanced.
     */
    public function isBalanced(): bool
    {
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($this->entries as $entry) {
            $totalDebit = bcadd($totalDebit, (string) $entry->debit, 4);
            $totalCredit = bcadd($totalCredit, (string) $entry->credit, 4);
        }

        return bccomp($totalDebit, $totalCredit, 4) === 0;
    }
}

