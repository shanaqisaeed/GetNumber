<?php

namespace App\Services;

use App\Contracts\LedgerServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidWalletTransactionException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService implements WalletServiceInterface
{
    /**
     * Precision for bcmath financial calculations.
     */
    protected const DECIMAL_SCALE = 4;

    protected LedgerServiceInterface $ledgerService;

    public function __construct(?LedgerServiceInterface $ledgerService = null)
    {
        $this->ledgerService = $ledgerService ?? app(LedgerServiceInterface::class);
    }

    public function getWallet(User $user): Wallet
    {
        return $user->getOrCreateWallet();
    }

    public function deposit(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);

        return DB::transaction(function () use ($user, $cleanAmount, $referenceId, $description, $metadata) {
            $existing = WalletTransaction::where('reference_id', $referenceId)->first();
            if ($existing) {
                return $existing;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Deposit,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? __('services.wallet.desc_credit'),
                'metadata' => $metadata,
            ]);

            // Strictly record balanced Double-Entry Ledger
            $this->ledgerService->recordDeposit(
                user: $user,
                amount: $cleanAmount,
                referenceId: $referenceId,
                description: $description ?? __('services.wallet.desc_credit'),
                metadata: $metadata
            );

            return $tx;
        });
    }

    public function chargeForNumberPurchase(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);

        return DB::transaction(function () use ($user, $cleanAmount, $referenceId, $description, $metadata) {
            $existing = WalletTransaction::where('reference_id', $referenceId)->first();
            if ($existing) {
                return $existing;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;

            if (bccomp($balanceBefore, $cleanAmount, self::DECIMAL_SCALE) < 0) {
                throw new InsufficientWalletBalanceException(
                    requiredAmount: $cleanAmount,
                    currentBalance: $balanceBefore,
                    message: __('services.wallet.insufficient_balance_purchase', ['balance' => $balanceBefore, 'amount' => $cleanAmount])
                );
            }

            $balanceAfter = bcsub($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::NumberPurchase,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? __('services.wallet.desc_charge_number'),
                'metadata' => $metadata,
            ]);

            // Strictly record balanced Double-Entry Ledger
            $this->ledgerService->recordNumberPurchase(
                user: $user,
                amount: $cleanAmount,
                referenceId: $referenceId,
                description: $description ?? __('services.wallet.desc_charge_number'),
                metadata: $metadata
            );

            return $tx;
        });
    }

    public function refund(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);

        return DB::transaction(function () use ($user, $cleanAmount, $referenceId, $description, $metadata) {
            $existing = WalletTransaction::where('reference_id', $referenceId)->first();
            if ($existing) {
                return $existing;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Refund,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? __('services.wallet.desc_refund_purchase'),
                'metadata' => $metadata,
            ]);

            // Strictly record balanced Double-Entry Ledger
            $this->ledgerService->recordRefund(
                user: $user,
                amount: $cleanAmount,
                referenceId: $referenceId,
                description: $description ?? __('services.wallet.desc_refund_purchase'),
                metadata: $metadata
            );

            return $tx;
        });
    }

    public function holdForNumberRequest(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);

        return DB::transaction(function () use ($user, $cleanAmount, $referenceId, $description, $metadata) {
            $existing = WalletTransaction::where('reference_id', $referenceId)->first();
            if ($existing) {
                return $existing;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;

            if (bccomp($balanceBefore, $cleanAmount, self::DECIMAL_SCALE) < 0) {
                throw new InsufficientWalletBalanceException(
                    requiredAmount: $cleanAmount,
                    currentBalance: $balanceBefore,
                    message: __('services.wallet.insufficient_balance_request', ['balance' => $balanceBefore, 'amount' => $cleanAmount])
                );
            }

            $balanceAfter = bcsub($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);
            $wallet->update(['balance' => $balanceAfter]);

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Hold,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? __('services.wallet.desc_block_request'),
                'metadata' => $metadata,
            ]);

            $this->ledgerService->recordHold(
                user: $user,
                amount: $cleanAmount,
                referenceId: $referenceId,
                description: $description ?? __('services.wallet.desc_block_request'),
                metadata: $metadata
            );

            return $tx;
        });
    }

    public function finalizeNumberRequestHold(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): void {
        $cleanAmount = $this->sanitizeAmount($amount);

        DB::transaction(function () use ($user, $cleanAmount, $referenceId, $description, $metadata) {
            $this->ledgerService->recordHoldFinalize(
                user: $user,
                amount: $cleanAmount,
                referenceId: $referenceId,
                description: $description ?? __('services.wallet.desc_settle_request'),
                metadata: $metadata
            );
        });
    }

    public function releaseNumberRequestHold(
        User $user,
        string $amount,
        string $referenceId,
        ?string $description = null,
        array $metadata = []
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);

        return DB::transaction(function () use ($user, $cleanAmount, $referenceId, $description, $metadata) {
            $existing = WalletTransaction::where('reference_id', $referenceId)->first();
            if ($existing) {
                return $existing;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::HoldRelease,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? __('services.wallet.desc_release_request'),
                'metadata' => $metadata,
            ]);

            $this->ledgerService->recordHoldRelease(
                user: $user,
                amount: $cleanAmount,
                referenceId: $referenceId,
                description: $description ?? __('services.wallet.desc_release_request'),
                metadata: $metadata
            );

            return $tx;
        });
    }

    public function adminCredit(
        User $user,
        string $amount,
        User $admin,
        string $reason,
        ?string $referenceId = null
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new InvalidWalletTransactionException(__('services.wallet.reason_required_manual_change'));
        }

        $ref = $referenceId ?? ('ADM-CR-' . Str::uuid());

        return DB::transaction(function () use ($user, $cleanAmount, $admin, $cleanReason, $ref) {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::AdminCredit,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $ref,
                'description' => __('services.wallet.desc_manual_credit', ['reason' => $cleanReason]),
                'metadata' => [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'reason' => $cleanReason,
                ],
            ]);

            // Strictly record balanced Double-Entry Ledger
            $this->ledgerService->recordAdminAdjustment(
                user: $user,
                amount: $cleanAmount,
                isCredit: true,
                admin: $admin,
                reason: $cleanReason,
                referenceId: $ref
            );

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'admin.wallet.credit',
                'entity_type' => WalletTransaction::class,
                'entity_id' => $transaction->id,
                'payload' => [
                    'target_user_id' => $user->id,
                    'amount' => $cleanAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reason' => $cleanReason,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
            ]);

            return $transaction;
        });
    }

    public function adminDebit(
        User $user,
        string $amount,
        User $admin,
        string $reason,
        ?string $referenceId = null
    ): WalletTransaction {
        $cleanAmount = $this->sanitizeAmount($amount);
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new InvalidWalletTransactionException(__('services.wallet.reason_required_manual_debit'));
        }

        $ref = $referenceId ?? ('ADM-DB-' . Str::uuid());

        return DB::transaction(function () use ($user, $cleanAmount, $admin, $cleanReason, $ref) {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = $user->getOrCreateWallet();
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = (string) $wallet->balance;

            if (bccomp($balanceBefore, $cleanAmount, self::DECIMAL_SCALE) < 0) {
                throw new InsufficientWalletBalanceException(
                    requiredAmount: $cleanAmount,
                    currentBalance: $balanceBefore,
                    message: __('services.wallet.balance_less_than_debit', ['balance' => $balanceBefore, 'amount' => $cleanAmount])
                );
            }

            $balanceAfter = bcsub($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::AdminDebit,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $ref,
                'description' => __('services.wallet.desc_manual_debit', ['reason' => $cleanReason]),
                'metadata' => [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'reason' => $cleanReason,
                ],
            ]);

            // Strictly record balanced Double-Entry Ledger
            $this->ledgerService->recordAdminAdjustment(
                user: $user,
                amount: $cleanAmount,
                isCredit: false,
                admin: $admin,
                reason: $cleanReason,
                referenceId: $ref
            );

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'admin.wallet.debit',
                'entity_type' => WalletTransaction::class,
                'entity_id' => $transaction->id,
                'payload' => [
                    'target_user_id' => $user->id,
                    'amount' => $cleanAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reason' => $cleanReason,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
            ]);

            return $transaction;
        });
    }

    public function reverseTransaction(
        WalletTransaction $originalTransaction,
        User $admin,
        string $reason
    ): WalletTransaction {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new InvalidWalletTransactionException(__('services.wallet.reason_required_reversal'));
        }

        if ($originalTransaction->type === WalletTransactionType::Reversal) {
            throw new InvalidWalletTransactionException(__('services.wallet.reversal_cannot_be_reversed'));
        }

        return DB::transaction(function () use ($originalTransaction, $admin, $cleanReason) {
            $user = $originalTransaction->user;
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = (string) $wallet->balance;
            $amount = (string) $originalTransaction->amount;

            $isOriginalCredit = $originalTransaction->type->isCredit();

            if ($isOriginalCredit) {
                if (bccomp($balanceBefore, $amount, self::DECIMAL_SCALE) < 0) {
                    throw new InsufficientWalletBalanceException(
                        requiredAmount: $amount,
                        currentBalance: $balanceBefore,
                        message: __('services.wallet.insufficient_balance_reversal')
                    );
                }
                $balanceAfter = bcsub($balanceBefore, $amount, self::DECIMAL_SCALE);
            } else {
                $balanceAfter = bcadd($balanceBefore, $amount, self::DECIMAL_SCALE);
            }

            $wallet->update(['balance' => $balanceAfter]);

            $reversalRef = 'REV-' . $originalTransaction->id . '-' . Str::random(8);

            $reversalTx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Reversal,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $reversalRef,
                'description' => __('services.wallet.desc_reversal', ['id' => $originalTransaction->id, 'reason' => $cleanReason]),
                'metadata' => [
                    'original_transaction_id' => $originalTransaction->id,
                    'original_type' => $originalTransaction->type->value,
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'reason' => $cleanReason,
                ],
            ]);

            // Reverse in ledger
            $this->ledgerService->recordAdminAdjustment(
                user: $user,
                amount: $amount,
                isCredit: ! $isOriginalCredit,
                admin: $admin,
                reason: __('services.wallet.audit_reversal', ['id' => $originalTransaction->id, 'reason' => $cleanReason]),
                referenceId: $reversalRef
            );

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'admin.wallet.reversal',
                'entity_type' => WalletTransaction::class,
                'entity_id' => $reversalTx->id,
                'payload' => [
                    'target_user_id' => $user->id,
                    'original_transaction_id' => $originalTransaction->id,
                    'amount' => $amount,
                    'reason' => $cleanReason,
                ],
                'ip_address' => request()->ip() ?? '127.0.0.1',
            ]);

            return $reversalTx;
        });
    }

    public function reconcile(User $user): bool
    {
        $wallet = $this->getWallet($user);

        // First check internal transaction consistency
        if (! $wallet->reconcileBalanceFromLedger()) {
            return false;
        }

        // Second check double-entry ledger if entries exist
        $hasLedger = \App\Models\LedgerEntry::where('user_id', $user->id)->exists();
        if ($hasLedger) {
            $ledgerBalance = $this->ledgerService->calculateUserBalance($user);
            return bccomp((string) $wallet->balance, $ledgerBalance, self::DECIMAL_SCALE) === 0;
        }

        return true;
    }

    /**
     * Validate and normalize a monetary amount to 4 decimal places.
     */
    protected function sanitizeAmount(string|float|int $amount): string
    {
        $cleaned = trim((string) $amount);
        if (! is_numeric($cleaned) || bccomp($cleaned, '0.0000', self::DECIMAL_SCALE) <= 0) {
            throw new InvalidWalletTransactionException(__('services.wallet.amount_must_be_positive'));
        }

        return number_format((float) $cleaned, self::DECIMAL_SCALE, '.', '');
    }
}