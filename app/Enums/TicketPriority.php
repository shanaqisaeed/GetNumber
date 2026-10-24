<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'کم',
            self::Medium => 'عادی',
            self::High => 'بالا',
            self::Urgent => 'فوری',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Low => 'bg-slate-500/15 text-slate-600 dark:text-slate-400 border border-slate-500/30',
            self::Medium => 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30',
            self::High => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30',
            self::Urgent => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30',
        };
    }
}
