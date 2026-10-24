<?php

namespace App\Enums;

enum MessageProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'دریافت‌شده',
            self::Processed => 'پردازش‌شده',
            self::Failed => 'خطا در پردازش',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Received => 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
            self::Processed => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
            self::Failed => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800',
        };
    }

    public function badgeClass(): string
    {
        return $this->badgeClasses();
    }
}
