<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Deposit = 'DEPOSIT';
    case NumberPurchase = 'NUMBER_PURCHASE';
    case AdminCredit = 'ADMIN_CREDIT';
    case AdminDebit = 'ADMIN_DEBIT';
    case Refund = 'REFUND';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'شارژ حساب (واریز)',
            self::NumberPurchase => 'خرید شماره مجازی',
            self::AdminCredit => 'افزایش اعتبار توسط مدیر',
            self::AdminDebit => 'کسر اعتبار توسط مدیر',
            self::Refund => 'بازگشت وجه (استرداد)',
            self::Reversal => 'تراکنش اصلاحی (معکوس)',
        };
    }

    public function isCredit(): bool
    {
        return match ($this) {
            self::Deposit, self::AdminCredit, self::Refund => true,
            self::NumberPurchase, self::AdminDebit => false,
            self::Reversal => false,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Deposit => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/25',
            self::NumberPurchase => 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25',
            self::AdminCredit => 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 border border-cyan-500/25',
            self::AdminDebit => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/25',
            self::Refund => 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25',
            self::Reversal => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/25',
        };
    }
}

