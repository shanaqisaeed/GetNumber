<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __('enums.user_status.' . $this->value);
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
