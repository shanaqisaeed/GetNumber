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
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
        ];
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
     * Tickets assigned to this user (for agents/admins).
     */
    public function assignedTickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }
}
