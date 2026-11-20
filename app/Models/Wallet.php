<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /**
     * Credit funds to personal wallet with immutable transaction entry.
     */
    public function credit(string $amount, WalletTransactionType|string $type = WalletTransactionType::Deposit, ?string $description = null, ?int $userId = null, ?array $metadata = null): WalletTransaction
    {
        if (is_string($type)) {
            $type = WalletTransactionType::tryFrom(strtoupper($type)) ?? WalletTransactionType::Deposit;
        }
        $before = (string) $this->balance;
        $after = bcadd($before, $amount, 4);

        $this->update(['balance' => $after]);

        return $this->transactions()->create([
            'user_id' => $userId ?? $this->user_id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_id' => 'tx_' . Str::lower(Str::random(16)),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Debit funds from personal wallet with immutable transaction entry.
     */
    public function debit(string $amount, WalletTransactionType|string $type = WalletTransactionType::CorporateTransfer, ?string $description = null, ?int $userId = null, ?array $metadata = null): ?WalletTransaction
    {
        if (is_string($type)) {
            $type = WalletTransactionType::tryFrom(strtoupper($type)) ?? WalletTransactionType::CorporateTransfer;
        }
        $before = (string) $this->balance;
        if (bccomp($before, $amount, 4) < 0) {
            return null;
        }

        $after = bcsub($before, $amount, 4);
        $this->update(['balance' => $after]);

        return $this->transactions()->create([
            'user_id' => $userId ?? $this->user_id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_id' => 'tx_' . Str::lower(Str::random(16)),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}