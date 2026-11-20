<?php

namespace App\Http\Controllers\Organization;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMember;
use App\Models\PhoneNumber;
use App\Models\ServiceResourceAllocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LineManagementController extends Controller
{
    /**
     * Display all numbers and lines owned by the organization.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        // 0. Automatically link corporate/enterprise service numbers (strictly determined by commercial type)
        PhoneNumber::where('user_id', $user->id)
            ->whereNull('organization_id')
            ->whereIn('type', [
                PhoneNumberType::UserService->value,
                PhoneNumberType::PlatformService->value,
            ])
            ->update([
                'organization_id' => $org->id,
                'is_organization' => true,
            ]);

        // Automatically link corporate custom ID allocations
        ServiceResourceAllocation::where('user_id', $user->id)
            ->whereNull('organization_id')
            ->update([
                'organization_id' => $org->id,
                'is_organization' => true,
            ]);

        $members = $org->members()->with('user')->get();
        $membersByUserId = $members->keyBy('user_id');

        // 1. Organization phone numbers (strictly commercial/service types or org-allocated numbers)
        $phoneLines = PhoneNumber::query()
            ->with(['assignedUser', 'phonePrefix'])
            ->where(function ($q) use ($org, $user) {
                $q->where('organization_id', $org->id)
                  ->orWhere('is_organization', true)
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('user_id', $user->id)
                          ->whereIn('type', [
                              PhoneNumberType::UserService->value,
                              PhoneNumberType::PlatformService->value,
                          ]);
                  });
            })
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'key' => 'phone_' . $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'type' => 'number',
                'type_label' => __('softphone.line.type_phone'),
                'status' => $p->status instanceof PhoneNumberStatus ? $p->status->value : (string) $p->status,
                'assigned_to' => $p->assignedUser ? [
                    'id' => $p->assignedUser->id,
                    'member_id' => $membersByUserId->get($p->assignedUser->id)?->id ?? $p->assignedUser->id,
                    'name' => $p->assignedUser->name,
                    'email' => $p->assignedUser->email,
                ] : null,
                'allocated_at' => $p->allocated_at?->format('Y/m/d'),
            ]);

        // 2. Organization custom IDs
        $customIdLines = ServiceResourceAllocation::query()
            ->with('assignedUser')
            ->where(function ($q) use ($org, $user) {
                $q->where('organization_id', $org->id)
                  ->orWhere('user_id', $user->id);
            })
            ->where('service_key', 'custom_id')
            ->get()
            ->map(fn ($cid) => [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: $cid->resource_identifier,
                'type' => 'custom_id',
                'type_label' => __('softphone.line.type_custom_id'),
                'status' => (string) $cid->status,
                'assigned_to' => $cid->assignedUser ? [
                    'id' => $cid->assignedUser->id,
                    'member_id' => $membersByUserId->get($cid->assignedUser->id)?->id ?? $cid->assignedUser->id,
                    'name' => $cid->assignedUser->name,
                    'email' => $cid->assignedUser->email,
                ] : null,
                'allocated_at' => $cid->allocated_at?->format('Y/m/d'),
            ]);

        $lines = $phoneLines->concat($customIdLines)->values();

        // Calculate assigned user IDs safely without querying nonexistent columns in SQL
        $assignedUserIds = PhoneNumber::where(function ($q) use ($org, $user) {
                $q->where('organization_id', $org->id)
                  ->orWhere('user_id', $user->id);
            })
            ->whereNotNull('assigned_user_id')
            ->pluck('assigned_user_id')
            ->concat(
                ServiceResourceAllocation::where(function ($q) use ($org, $user) {
                    $q->where('organization_id', $org->id)
                      ->orWhere('user_id', $user->id);
                })
                ->whereNotNull('assigned_user_id')
                ->pluck('assigned_user_id')
            )
            ->unique()
            ->filter();

        $eligibleMembers = $members
            ->filter(fn ($m) => $m->user_id && ! $assignedUserIds->contains($m->user_id))
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
            ]);

        return Inertia::render('Organization/Lines/Index', [
            'lines' => $lines,
            'eligible_members' => $eligibleMembers,
        ]);
    }
}
