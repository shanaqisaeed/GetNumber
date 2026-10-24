<?php

namespace App\Policies;

use App\Models\PhoneNumber;
use App\Models\User;

class PhoneNumberPolicy
{
    /**
     * Determine whether the user can view any phone numbers.
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Determine whether the user can view the specific phone number.
     */
    public function view(User $user, PhoneNumber $phoneNumber): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isActive() && $phoneNumber->user_id === $user->id;
    }

    /**
     * Determine whether the user can allocate a standard number.
     */
    public function allocate(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Determine whether the user can release their allocated number.
     */
    public function release(User $user, PhoneNumber $phoneNumber): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isActive() && $phoneNumber->user_id === $user->id;
    }

    /**
     * Determine whether the user can update the phone number.
     */
    public function update(User $user, PhoneNumber $phoneNumber): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isActive() && $phoneNumber->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete or permanently modify numbers.
     */
    public function delete(User $user, PhoneNumber $phoneNumber): bool
    {
        return $user->isAdmin();
    }
}
