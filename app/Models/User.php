<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Builders\UserBuilder;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Temporary virtual storage for credentials during creation/hydration.
     */
    protected ?string $tempUsername = null;
    protected ?string $tempEmail = null;
    protected ?string $tempPassword = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
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
        'email_verified_at',
        'account_tier',
        'organization_name',
        'enterprise_contract_code',
        'enterprise_settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
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
            'email_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'risk_score' => 'integer',
            'enterprise_settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->attributes['account_id'])) {
                // Pre-assign or finalize in created
            }
        });

        static::created(function (User $user) {
            if (empty($user->attributes['account_id'])) {
                $user->attributes['account_id'] = 'ACC-' . str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
                $user->saveQuietly();
            }

            if (!empty($user->tempUsername) && !$user->hasIdentity('username')) {
                $user->linkIdentity(
                    'username',
                    strtolower($user->tempUsername),
                    $user->tempEmail,
                    ['auto_created' => true],
                    $user->tempPassword
                );
                $user->tempUsername = null;
            }

            if (!empty($user->tempEmail) && !$user->hasIdentity('email')) {
                $user->linkIdentity(
                    'email',
                    strtolower($user->tempEmail),
                    strtolower($user->tempEmail),
                    ['auto_created' => true],
                    $user->tempPassword
                );
                $user->tempEmail = null;
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
     * Accessor for account_id.
     */
    public function getAccountIdAttribute(): string
    {
        return $this->attributes['account_id'] ?? ('ACC-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT));
    }

    /**
     * Accessor for username (retrieved from user_identities).
     */
    public function getUsernameAttribute(): ?string
    {
        if (!$this->exists && $this->tempUsername !== null) {
            return $this->tempUsername;
        }

        if ($this->relationLoaded('identities')) {
            $ident = $this->identities->firstWhere('provider', 'username');
            if ($ident) {
                return $ident->provider_id;
            }
        }

        return $this->identities()->where('provider', 'username')->value('provider_id') ?? $this->tempUsername;
    }

    /**
     * Mutator for username.
     */
    public function setUsernameAttribute(?string $value): void
    {
        $this->tempUsername = $value !== null ? trim($value) : null;
        if ($this->exists && $this->tempUsername !== null) {
            $this->linkIdentity('username', strtolower($this->tempUsername), $this->email);
        }
    }

    /**
     * Accessor for email (retrieved from user_identities).
     */
    public function getEmailAttribute(): ?string
    {
        if (!$this->exists && $this->tempEmail !== null) {
            return $this->tempEmail;
        }

        if ($this->relationLoaded('identities')) {
            $ident = $this->identities->firstWhere('provider', 'email');
            if ($ident) {
                return $ident->provider_id;
            }
            $anyWithEmail = $this->identities->first(fn ($i) => !empty($i->provider_email));
            if ($anyWithEmail) {
                return $anyWithEmail->provider_email;
            }
        }

        return $this->identities()->where('provider', 'email')->value('provider_id')
            ?? $this->identities()->whereNotNull('provider_email')->value('provider_email')
            ?? $this->tempEmail;
    }

    /**
     * Mutator for email.
     */
    public function setEmailAttribute(?string $value): void
    {
        $this->tempEmail = $value !== null ? trim($value) : null;
        if ($this->exists && $this->tempEmail !== null) {
            $this->linkIdentity('email', strtolower($this->tempEmail), strtolower($this->tempEmail));
        }
    }

    /**
     * Retrieve the auth password from user_identities.
     */
    public function getAuthPassword(): ?string
    {
        if ($this->relationLoaded('identities')) {
            $ident = $this->identities->first(fn ($i) => !empty($i->password));
            if ($ident) {
                return $ident->password;
            }
        }

        return $this->identities()->whereNotNull('password')->value('password')
            ?? $this->identities()->first()?->password;
    }

    /**
     * Accessor for password (retrieves hashed password from identities).
     */
    public function getPasswordAttribute(): ?string
    {
        return $this->getAuthPassword();
    }

    /**
     * Mutator for password (saves to identities).
     */
    public function setPasswordAttribute(?string $value): void
    {
        if ($value === null) {
            return;
        }

        $hashed = Hash::needsRehash($value) ? Hash::make($value) : $value;
        $this->tempPassword = $hashed;

        if ($this->exists) {
            $identities = $this->identities()->get();
            if ($identities->isNotEmpty()) {
                foreach ($identities as $identity) {
                    $identity->update(['password' => $hashed]);
                }
            } else {
                $this->linkIdentity('username', $this->username ?: 'user_' . $this->id, $this->email, [], $hashed);
            }
        }
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \App\Models\Builders\UserBuilder
     */
    public function newEloquentBuilder($query): UserBuilder
    {
        return new UserBuilder($query);
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
     * Mark the given user's email as verified and sync identity.
     */
    public function markEmailAsVerified(): bool
    {
        $result = $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();

        if ($result && $this->email) {
            $this->identities()->where('provider', 'email')->update([
                'verified_at' => now(),
            ]);
        }

        return $result;
    }

    /**
     * Determine if this user requires email verification to access platform services.
     */
    public function needsEmailVerification(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        // If email verification is disabled in system settings, no verification is required
        if (! (bool) (int) SystemSetting::get('require_email_verification', '1')) {
            return false;
        }

        // Only users with an email address need verification
        if (empty($this->email)) {
            return false;
        }

        return ! $this->hasVerifiedEmail();
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
     * Voice calls initiated or received by the user.
     */
    public function voiceCalls(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VoiceCall::class);
    }

    /**
     * Web3 domains owned by the user.
     */
    public function web3Domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Web3Domain::class);
    }

    /**
     * Email mailboxes owned by the user.
     */
    public function emailMailboxes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailMailbox::class);
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
            $values['password'] = Hash::needsRehash($password)
                ? Hash::make($password)
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

    /**
     * Determine the effective service tier ('individual' or 'enterprise') for the BusinessRuleEngine.
     */
    public function effectiveServiceTier(): string
    {
        if ($this->isAdmin() || $this->account_tier === 'enterprise') {
            return 'enterprise';
        }

        return 'individual';
    }

    /**
     * Check whether the user is configured as an enterprise account.
     */
    public function isEnterpriseTier(): bool
    {
        return $this->account_tier === 'enterprise' || $this->isAdmin();
    }

    /**
     * Multi-service orders placed by this user.
     */
    public function serviceOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    /**
     * Multi-service resource allocations owned by this user.
     */
    public function serviceAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceResourceAllocation::class);
    }

    /**
     * Active multi-service resource allocations owned by this user.
     */
    public function activeServiceAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceResourceAllocation::class)->where('status', 'active');
    }

    /**
     * Sub-resources (e.g., Web3 sub-accounts) owned by this user.
     */
    public function serviceSubResources(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceSubResource::class);
    }
}
