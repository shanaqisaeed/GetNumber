<?php

namespace App\Contracts;

use App\Models\NumberRequest;
use App\Models\User;

interface NumberRequestServiceInterface
{
    /**
     * Creates a new pending service number request for the user.
     */
    public function createRequest(
        User $user,
        int $requestedLength,
        string $serviceTitle,
        string $description,
        ?string $preferredNumber = null
    ): NumberRequest;

    /**
     * Approves a pending request and allocates a matching service number.
     */
    public function approveRequest(
        NumberRequest $request,
        User $admin,
        string $mode = 'auto',
        ?string $customNumber = null,
        ?int $poolNumberId = null
    ): NumberRequest;

    /**
     * Rejects a pending request with a mandatory reason.
     */
    public function rejectRequest(
        NumberRequest $request,
        User $admin,
        string $reason
    ): NumberRequest;

    /**
     * Cancels a pending request by the user.
     */
    public function cancelRequest(
        NumberRequest $request,
        User $user
    ): void;
}
