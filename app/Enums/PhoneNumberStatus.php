<?php

namespace App\Enums;

enum PhoneNumberStatus: string
{
    case Available = 'available';
    case Allocated = 'allocated';
    case Reserved = 'reserved';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'آزاد برای تخصیص',
            self::Allocated => 'تخصیص‌یافته',
            self::Reserved => 'رزروشده',
            self::Suspended => 'تعلیق‌شده',
            self::Inactive => 'غیرفعال',
            self::Expired => 'منقضی‌شده',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Available => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
            self::Allocated => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800',
            self::Reserved => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
            self::Suspended => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800',
            self::Inactive => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
            self::Expired => 'bg-zinc-100 text-zinc-700 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:border-zinc-700',
        };
    }

    public function isAllocatable(): bool
    {
        return $this === self::Available;
    }
}
