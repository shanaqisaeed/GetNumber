<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case User = 'user';

    public function label(): string
    {
        return __('enums.user_role.' . $this->value);
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