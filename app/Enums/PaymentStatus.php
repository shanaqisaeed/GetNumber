<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PendingTransfer = 'pending_transfer';
    case TxSubmitted = 'tx_submitted';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingTransfer => 'در انتظار انتقال ترون',
            self::TxSubmitted => 'کد رهگیری ثبت‌شده',
            self::Verifying => 'در حال استعلام از شبکه',
            self::Completed => 'تأیید و شارژشده',
            self::Failed => 'ناموفق / ردشده',
            self::Expired => 'منقضی‌شده',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PendingTransfer => 'bg-amber-500/15 text-amber-500 border-amber-500/30 dark:bg-amber-500/20 dark:text-amber-400',
            self::TxSubmitted => 'bg-indigo-500/15 text-indigo-500 border-indigo-500/30 dark:bg-indigo-500/20 dark:text-indigo-400',
            self::Verifying => 'bg-blue-500/15 text-blue-500 border-blue-500/30 dark:bg-blue-500/20 dark:text-blue-400 animate-pulse',
            self::Completed => 'bg-emerald-500/15 text-emerald-500 border-emerald-500/30 dark:bg-emerald-500/20 dark:text-emerald-400',
            self::Failed => 'bg-rose-500/15 text-rose-500 border-rose-500/30 dark:bg-rose-500/20 dark:text-rose-400',
            self::Expired => 'bg-slate-500/15 text-slate-500 border-slate-500/30 dark:bg-slate-500/20 dark:text-slate-400',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Expired;
    }

    public function canSubmitTx(): bool
    {
        return $this === self::PendingTransfer || $this === self::TxSubmitted;
    }

    public function canBeVerified(): bool
    {
        return $this === self::TxSubmitted || $this === self::Verifying;
    }
}

