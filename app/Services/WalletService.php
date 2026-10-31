<?php

namespace App\Services;

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

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Deposit,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? 'افزایش موجودی کیف پول',
                'metadata' => $metadata,
            ]);
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
                    message: "موجودی کیف پول شما ({$balanceBefore} کوین) برای خرید شماره ({$cleanAmount} کوین) کافی نمی‌باشد."
                );
            }

            $balanceAfter = bcsub($balanceBefore, $cleanAmount, self::DECIMAL_SCALE);

            $wallet->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::NumberPurchase,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? 'کسر اعتبار جهت تخصیص شماره مجازی',
                'metadata' => $metadata,
            ]);
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

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Refund,
                'amount' => $cleanAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_id' => $referenceId,
                'description' => $description ?? 'بازگشت وجه به کیف پول (عدم موفقیت تخصیص شماره)',
                'metadata' => $metadata,
            ]);
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
            throw new InvalidWalletTransactionException('ثبت دلیل برای تغییر دستی موجودی توسط مدیر الزامی است.');
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
                'description' => 'شارژ دستی توسط مدیر: ' . $cleanReason,
                'metadata' => [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'reason' => $cleanReason,
                ],
            ]);

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
            throw new InvalidWalletTransactionException('ثبت دلیل برای کسر دستی موجودی توسط مدیر الزامی است.');
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
                    message: "موجودی کاربر ({$balanceBefore} کوین) کمتر از مبلغ درخواستی برای کسر ({$cleanAmount} کوین) است."
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
                'description' => 'کسر دستی توسط مدیر: ' . $cleanReason,
                'metadata' => [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'reason' => $cleanReason,
                ],
            ]);

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
            throw new InvalidWalletTransactionException('ثبت دلیل برای بازگشت و اصلاح تراکنش الزامی است.');
        }

        if ($originalTransaction->type === WalletTransactionType::Reversal) {
            throw new InvalidWalletTransactionException('یک تراکنش اصلاحی (Reversal) نمی‌تواند مجدداً اصلاح شود.');
        }

        return DB::transaction(function () use ($originalTransaction, $admin, $cleanReason) {
            $user = $originalTransaction->user;
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = (string) $wallet->balance;
            $amount = (string) $originalTransaction->amount;

            // If original transaction was credit, reversal debits the wallet
            // If original transaction was debit, reversal credits the wallet
            $isOriginalCredit = $originalTransaction->type->isCredit();

            if ($isOriginalCredit) {
                if (bccomp($balanceBefore, $amount, self::DECIMAL_SCALE) < 0) {
                    throw new InsufficientWalletBalanceException(
                        requiredAmount: $amount,
                        currentBalance: $balanceBefore,
                        message: "موجودی کاربر جهت لغو تراکنش واریز قبلی کافی نمی‌باشد."
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
                'description' => "تراکنش معکوس اصلاحی برای شناسه #{$originalTransaction->id}: {$cleanReason}",
                'metadata' => [
                    'original_transaction_id' => $originalTransaction->id,
                    'original_type' => $originalTransaction->type->value,
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'reason' => $cleanReason,
                ],
            ]);

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
        return $wallet->reconcileBalanceFromLedger();
    }

    /**
     * Validate and normalize a monetary amount to 4 decimal places.
     */
    protected function sanitizeAmount(string|float|int $amount): string
    {
        $cleaned = trim((string) $amount);
        if (! is_numeric($cleaned) || bccomp($cleaned, '0.0000', self::DECIMAL_SCALE) <= 0) {
            throw new InvalidWalletTransactionException('مبلغ تراکنش باید یک مقدار عددی مثبت و بزرگتر از صفر باشد.');
        }

        return number_format((float) $cleaned, self::DECIMAL_SCALE, '.', '');
    }
}

