<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'status',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the single dedicated assigned line (if any) for this member in this organization.
     */
    public function getAssignedLineAttribute(): ?array
    {
        // 1. Check dedicated phone number
        $phone = PhoneNumber::where('organization_id', $this->organization_id)
            ->where('assigned_user_id', $this->user_id)
            ->first();

        if ($phone) {
            return [
                'id' => $phone->id,
                'key' => 'phone_' . $phone->id,
                'number' => $phone->number,
                'formatted_number' => $phone->formatted_number,
                'display_title' => $phone->display_title ?: $phone->effective_title,
                'type' => 'number',
                'type_label' => __('softphone.line.type_phone'),
            ];
        }

        // 2. Check dedicated custom ID handle
        $cid = ServiceResourceAllocation::where('organization_id', $this->organization_id)
            ->where('assigned_user_id', $this->user_id)
            ->where('service_key', 'custom_id')
            ->first();

        if ($cid) {
            return [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: $cid->resource_identifier,
                'type' => 'custom_id',
                'type_label' => __('softphone.line.type_custom_id'),
            ];
        }

        return null;
    }
}
