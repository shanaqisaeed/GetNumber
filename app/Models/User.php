<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'status',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'trust_tier',
        'risk_score',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'risk_score' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            if (!empty($user->username) && !$user->hasIdentity('username')) {
                $user->linkIdentity(
                    'username',
                    strtolower($user->username),
                    $user->email,
                    ['auto_created' => true],
                    $user->password
                );
            }
        });

        static::saved(function (User $user) {
            try {
                app(\App\Contracts\SystemCacheServiceInterface::class)->handleUserChanged($user);
            } catch (\Throwable) {
            }
        });

        static::deleted(function (User $user) {
            try {
                app(\App\Contracts\SystemCacheServiceInterface::class)->handleUserChanged($user);
            } catch (\Throwable) {
            }
        });
    }

    /**
     * Check if the user is an administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Check if the user is a support agent.
     */
    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent;
    }

    /**
     * Check if the user has staff privileges (Admin or Agent).
     */
    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /**
     * Check if the user account is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Check if the user account is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    /**
     * Phone numbers allocated or owned by the user.
     */
    public function phoneNumbers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    /**
     * Active allocated phone numbers.
     */
    public function activePhoneNumbers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PhoneNumber::class)->where('status', \App\Enums\PhoneNumberStatus::Allocated);
    }

    /**
     * Service number requests submitted by the user.
     */
    public function numberRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NumberRequest::class);
    }

    /**
     * API access tokens belonging to the user.
     */
    public function apiTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Inbound messages received on phone numbers allocated to the user.
     */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Message::class, PhoneNumber::class);
    }

    /**
     * Audit logs recorded for or by this user.
     */
    public function auditLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Support tickets created by this user.
     */
    public function tickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * User cryptocurrency/internal wallet.
     */
    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get user's wallet or automatically create one with 0 COIN balance.
     */
    public function getOrCreateWallet(): Wallet
    {
        return $this->wallet()->firstOrCreate(
            ['user_id' => $this->id],
            ['balance' => '0.0000', 'currency' => 'COIN']
        );
    }

    /**
     * Tickets assigned to this user (for agents/admins).
     */
    public function assignedTickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    /**
     * Security incidents linked to this user.
     */
    public function securityIncidents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SecurityIncident::class);
    }

    /**
     * Determine if the user has confirmed two-factor authentication enabled.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! empty($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Determine if account is considered trusted.
     */
    public function isTrustedTier(): bool
    {
        return $this->trust_tier === 'trusted' || $this->isAdmin();
    }

    /**
     * Determine if account is restricted.
     */
    public function isRestrictedTier(): bool
    {
        return $this->trust_tier === 'restricted';
    }

    /**
     * Connected external / multi-provider authentication identities.
     */
    public function identities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Check if a specific auth identity provider is linked to this user.
     */
    public function hasIdentity(string $provider): bool
    {
        return $this->identities()->where('provider', $provider)->exists();
    }

    /**
     * Get the identity record for a specific provider.
     */
    public function getIdentity(string $provider): ?UserIdentity
    {
        return $this->identities()->where('provider', $provider)->first();
    }

    /**
     * Link a new or existing identity provider to this user.
     */
    public function linkIdentity(string $provider, string $providerId, ?string $email = null, array $data = [], ?string $password = null): UserIdentity
    {
        $attributes = [
            'provider' => $provider,
            'provider_id' => $providerId,
        ];

        $values = [
            'provider_email' => $email,
            'provider_data' => $data,
            'linked_at' => now(),
        ];

        if ($password !== null) {
            $values['password'] = \Illuminate\Support\Facades\Hash::needsRehash($password)
                ? \Illuminate\Support\Facades\Hash::make($password)
                : $password;
        }

        return $this->identities()->updateOrCreate($attributes, $values);
    }

    /**
     * Unlink an identity provider from this user.
     */
    public function unlinkIdentity(string $provider): bool
    {
        return (bool) $this->identities()->where('provider', $provider)->delete();
    }
}