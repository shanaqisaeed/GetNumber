<?php

namespace App\Services;

use App\Contracts\ApiTokenServiceInterface;
use App\Enums\ApiTokenStatus;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiTokenService implements ApiTokenServiceInterface
{
    /**
     * Standard token prefix for system identification.
     */
    public const TOKEN_PREFIX = 'gn_live_';

    public function createToken(
        User $user,
        string $name,
        array $abilities = ['*'],
        ?\DateTimeInterface $expiresAt = null
    ): array {
        $entropy = Str::random(50);
        $plainTextToken = self::TOKEN_PREFIX . $entropy;
        $tokenHash = hash('sha256', $plainTextToken);
        $prefix = substr($plainTextToken, 0, 15);

        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => trim($name),
            'token_hash' => $tokenHash,
            'token_prefix' => $prefix,
            'abilities' => $abilities,
            'status' => ApiTokenStatus::Active,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'plain_text_token' => $plainTextToken,
        ];
    }

    public function rotateToken(ApiToken $token): array
    {
        return DB::transaction(function () use ($token) {
            /** @var ApiToken $locked */
            $locked = ApiToken::where('id', $token->id)->lockForUpdate()->firstOrFail();

            $entropy = Str::random(50);
            $newPlainTextToken = self::TOKEN_PREFIX . $entropy;
            $newHash = hash('sha256', $newPlainTextToken);
            $newPrefix = substr($newPlainTextToken, 0, 15);

            $locked->update([
                'token_hash' => $newHash,
                'token_prefix' => $newPrefix,
                'status' => ApiTokenStatus::Active,
                'last_used_at' => null,
                'last_used_ip' => null,
            ]);

            return [
                'token' => $locked->fresh(),
                'plain_text_token' => $newPlainTextToken,
            ];
        });
    }

    public function revokeToken(ApiToken $token): ApiToken
    {
        $token->update(['status' => ApiTokenStatus::Revoked]);

        return $token->fresh();
    }

    public function deleteToken(ApiToken $token): void
    {
        $token->delete();
    }

    public function authenticate(string $plainTextToken, ?string $ipAddress = null): ?ApiToken
    {
        $clean = trim($plainTextToken);
        if ($clean === '') {
            return null;
        }

        // Strip "Bearer " if present
        if (str_starts_with($clean, 'Bearer ')) {
            $clean = trim(substr($clean, 7));
        }

        $hash = hash('sha256', $clean);

        /** @var ApiToken|null $token */
        $token = ApiToken::with('user')->where('token_hash', $hash)->first();

        if (! $token || ! $token->isValid()) {
            return null;
        }

        // Verify owning user is active
        if (! $token->user || ! $token->user->isActive()) {
            return null;
        }

        // Update usage timestamp and IP
        $token->update([
            'last_used_at' => now(),
            'last_used_ip' => $ipAddress,
        ]);

        return $token;
    }
}
