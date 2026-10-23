<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'راهبر ارشد',
            self::User => 'کاربر سازمانی',
        };
    }

    public function isAdmin(): bool
    {
        return self::Admin === $this;
    }
}
