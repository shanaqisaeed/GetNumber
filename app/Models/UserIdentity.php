<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'password',
        'provider_email',
        'provider_data',
        'linked_at',
    ];

    /**
     * Hidden attributes for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'provider_data' => 'array',
            'linked_at' => 'datetime',
        ];
    }

    /**
     * Check if a dedicated password is set for this identity.
     */
    public function hasPassword(): bool
    {
        return !empty($this->password);
    }

    /**
     * The user who owns this identity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human-readable provider label in formal Persian.
     */
    public function providerLabel(): string
    {
        return match ($this->provider) {
            'username' => 'شناسه کاربری و گذرواژه',
            'email' => 'پست الکترونیک (ایمیل)',
            'ldap' => 'سامانه سازمانی LDAP / اکتیو دایرکتوری',
            'keycloak' => 'ورود یکپارچه Keycloak SSO',
            default => $this->provider,
        };
    }
}

