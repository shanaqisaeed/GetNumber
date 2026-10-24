<?php

namespace App\Enums;

enum TicketDepartment: string
{
    case Technical = 'technical';
    case Numbers = 'numbers';
    case Api = 'api';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Technical => 'پشتیبانی فنی خطوط',
            self::Numbers => 'امور سرشماره‌های اختصاصی',
            self::Api => 'وب‌سرویس و API',
            self::General => 'امور عمومی و مالی',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Technical => 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/30',
            self::Numbers => 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30',
            self::Api => 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/30',
            self::General => 'bg-slate-500/15 text-slate-600 dark:text-slate-400 border border-slate-500/30',
        };
    }
}
