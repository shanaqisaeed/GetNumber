<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrganizationWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'balance',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OrganizationWalletTransaction::class, 'organization_wallet_id')->latest('id');
    }

    /**
     * Reconcile current corporate balance against immutable ledger records.
     */
    public function reconcileBalanceFromLedger(): bool
    {
        $transactions = $this->transactions()->oldest('id')->get();
        $expectedBalance = '0.0000';

        foreach ($transactions as $tx) {
            $amount = (string) $tx->amount;
            if ($tx->isCredit()) {
                $expectedBalance = bcadd($expectedBalance, $amount, 4);
            } else {
                $expectedBalance = bcsub($expectedBalance, $amount, 4);
            }
        }

        $currentBalance = (string) $this->balance;
        return bccomp($currentBalance, $expectedBalance, 4) === 0;
    }

    /**
     * Credit funds to the organization wallet with an immutable ledger entry.
     */
    public function credit(string $amount, string $type = 'deposit', ?string $description = null, ?int $userId = null, ?array $metadata = null): OrganizationWalletTransaction
    {
        $before = (string) $this->balance;
        $after = bcadd($before, $amount, 4);

        $this->update(['balance' => $after]);

        return $this->transactions()->create([
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_id' => 'tx_org_' . Str::lower(Str::random(16)),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Debit funds from the organization wallet with an immutable ledger entry.
     */
    public function debit(string $amount, string $type = 'usage', ?string $description = null, ?int $userId = null, ?array $metadata = null): ?OrganizationWalletTransaction
    {
        $before = (string) $this->balance;
        if (bccomp($before, $amount, 4) < 0) {
            return null; // Insufficient funds
        }

        $after = bcsub($before, $amount, 4);
        $this->update(['balance' => $after]);

        return $this->transactions()->create([
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_id' => 'tx_org_' . Str::lower(Str::random(16)),
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
