<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'راهبر ارشد',
            self::Agent => 'کارشناس پشتیبانی',
            self::User => 'کاربر سازمانی',
        };
    }

    public function isAdmin(): bool
    {
        return self::Admin === $this;
    }

    public function isAgent(): bool
    {
        return self::Agent === $this;
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::Admin, self::Agent], true);
    }
}
