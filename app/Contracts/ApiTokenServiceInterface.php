<?php

namespace App\Contracts;

use App\Models\ApiToken;
use App\Models\User;

interface ApiTokenServiceInterface
{
    /**
     * Generates a new API token for the user.
     * Returns an array with:
     * - 'token': ApiToken model instance
     * - 'plain_text_token': raw unhashed token string (displayed ONLY ONCE)
     *
     * @return array{token: ApiToken, plain_text_token: string}
     */
    public function createToken(
        User $user,
        string $name,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null
    ): array;

    /**
     * Rotates (regenerates) an existing token.
     *
     * @return array{token: ApiToken, plain_text_token: string}
     */
    public function rotateToken(ApiToken $token): array;

    /**
     * Immediately revokes an API token.
     */
    public function revokeToken(ApiToken $token): ApiToken;

    /**
     * Deletes an API token completely.
     */
    public function deleteToken(ApiToken $token): void;

    /**
     * Authenticates a plain text token string from request header.
     */
    public function authenticate(string $plainTextToken, ?string $ipAddress = null): ?ApiToken;
}
