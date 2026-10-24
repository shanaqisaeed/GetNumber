<?php

namespace App\Policies;

use App\Models\NumberRequest;
use App\Models\User;

class NumberRequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, NumberRequest $numberRequest): bool
    {
        return $user->isAdmin() || $numberRequest->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    /**
     * Determine whether the user can cancel the request.
     */
    public function cancel(User $user, NumberRequest $numberRequest): bool
    {
        if (! $numberRequest->isPending()) {
            return false;
        }

        return $user->isAdmin() || $numberRequest->user_id === $user->id;
    }

    /**
     * Determine whether the user can review (approve/reject) the request.
     */
    public function review(User $user, NumberRequest $numberRequest): bool
    {
        return $user->isAdmin() && $numberRequest->isPending();
    }
}
