<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    /**
     * Reconcile current wallet balance against immutable ledger records.
     * Throws an exception or returns false if balance does not match ledger history.
     */
    public function reconcileBalanceFromLedger(): bool
    {
        $transactions = $this->transactions()->oldest('id')->get();
        $expectedBalance = '0.0000';

        foreach ($transactions as $tx) {
            $amount = (string) $tx->amount;
            if ($tx->type->isCredit()) {
                $expectedBalance = bcadd($expectedBalance, $amount, 4);
            } else {
                $expectedBalance = bcsub($expectedBalance, $amount, 4);
            }
        }

        $currentBalance = (string) $this->balance;
        return bccomp($currentBalance, $expectedBalance, 4) === 0;
    }
}

