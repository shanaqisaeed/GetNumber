<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'در انتظار پاسخ کارشناس',
            self::InProgress => 'در حال بررسی',
            self::Answered => 'پاسخ داده‌شده',
            self::Closed => 'بسته‌شده',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30',
            self::InProgress => 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/30',
            self::Answered => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30',
            self::Closed => 'bg-slate-500/15 text-slate-500 dark:text-slate-400 border border-slate-500/30',
        };
    }

    public function isClosed(): bool
    {
        return self::Closed === $this;
    }
}
