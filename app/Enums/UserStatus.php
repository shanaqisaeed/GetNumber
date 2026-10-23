<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'فعال',
            self::Suspended => 'تعلیق‌شده',
            self::Inactive => 'غیرفعال',
        };
    }

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    public function isSuspended(): bool
    {
        return self::Suspended === $this;
    }
}
