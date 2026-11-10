<?php

namespace App\Enums;

enum NumberRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Human-readable Persian label.
     */
    public function label(): string
    {
        return __('enums.number_request_status.' . $this->value);
    }

    /**
     * Tailwind CSS badge classes.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
            self::Approved => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
            self::Rejected => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800',
        };
    }
}