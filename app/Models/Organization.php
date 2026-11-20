<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'name',
        'identifier',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $org) {
            if (empty($org->identifier)) {
                $org->identifier = 'org_' . Str::lower(Str::random(12));
            }
        });

        static::created(function (self $org) {
            // Automatically initialize a corporate wallet
            $org->wallet()->create([
                'balance' => 0,
                'currency' => 'COIN',
            ]);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'organization_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members', 'organization_id', 'user_id')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(OrganizationWallet::class, 'organization_id');
    }

    public function getOrCreateWallet(): OrganizationWallet
    {
        if ($this->wallet) {
            return $this->wallet;
        }

        return $this->wallet()->create([
            'balance' => 0,
            'currency' => 'COIN',
        ]);
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'organization_id');
    }

    public function customIdAllocations(): HasMany
    {
        return $this->hasMany(ServiceResourceAllocation::class, 'organization_id')
            ->where('service_key', 'custom_id');
    }

    public function sharedContacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'organization_id')
            ->where('is_shared', true);
    }
}
