<?php

namespace App\Enums;

enum PhoneNumberType: string
{
    case UserStandard = 'user_standard';
    case UserService = 'user_service';
    case PlatformService = 'platform_service';

    public function label(): string
    {
        return match ($this) {
            self::UserStandard => 'شماره عادی کاربر',
            self::UserService => 'سرشماره اختصاصی کاربر',
            self::PlatformService => 'سرشماره اختصاصی سامانه',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::UserStandard => 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
            self::UserService => 'bg-violet-50 text-violet-700 border-violet-200 dark:bg-violet-950/60 dark:text-violet-300 dark:border-violet-800',
            self::PlatformService => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
        };
    }

    public function isService(): bool
    {
        return $this === self::UserService || $this === self::PlatformService;
    }
}
