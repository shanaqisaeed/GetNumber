<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\OrganizationMember;
use App\Models\PhoneNumber;
use App\Models\ServiceResourceAllocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TeamMemberController extends Controller
{
    /**
     * Display team members and line assignments.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $members = $org->members()
            ->with('user')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'username' => $m->user?->username ?: ($m->user?->email ?: $m->user?->account_id),
                'account_id' => $m->user?->account_id,
                'role' => $m->role,
                'status' => $m->status,
                'assigned_line' => $m->assigned_line,
                'joined_at' => $m->created_at?->format('Y/m/d'),
            ]);

        // Free lines available for assignment
        $freePhoneLines = $org->phoneNumbers()
            ->whereNull('assigned_user_id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'key' => 'phone_' . $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'type' => 'number',
                'type_label' => __('softphone.line.type_phone'),
            ]);

        $freeCustomIds = $org->customIdAllocations()
            ->whereNull('assigned_user_id')
            ->where('status', 'active')
            ->get()
            ->map(fn ($cid) => [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: $cid->resource_identifier,
                'type' => 'custom_id',
                'type_label' => __('softphone.line.type_custom_id'),
            ]);

        $availableLines = $freePhoneLines->concat($freeCustomIds)->values();

        return Inertia::render('Organization/Team/Index', [
            'members' => $members,
            'available_lines' => $availableLines,
        ]);
    }

    /**
     * Add a new employee/member to the organization.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $validated = $request->validate([
            'username' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:admin,member'],
            'password' => ['nullable', 'string', 'min:6', 'max:64'],
        ]);

        $rawIdentifier = $validated['username'] ?? $validated['email'] ?? null;
        if (! $rawIdentifier) {
            return back()->with('error', __('organization.team.identifier_required'));
        }

        $cleanIdentifier = strtolower(trim(ltrim($rawIdentifier, '@')));

        // Search for existing user by id, account_id, email, or username in identities
        $targetUser = User::where('id', $cleanIdentifier)
            ->orWhere('account_id', strtoupper($cleanIdentifier))
            ->orWhere('email', $cleanIdentifier)
            ->orWhereHas('identities', function ($q) use ($cleanIdentifier) {
                $q->whereIn('provider', ['username', 'email'])
                  ->where('provider_id', $cleanIdentifier);
            })
            ->first();

        $generatedPassword = null;

        if (! $targetUser) {
            $plainPassword = $validated['password'] ?: Str::random(10);
            if (empty($validated['password'])) {
                $generatedPassword = $plainPassword;
            }

            $targetUser = User::create([
                'name' => $validated['name'],
                'role' => \App\Enums\UserRole::User,
                'status' => \App\Enums\UserStatus::Active,
                'account_tier' => 'enterprise',
            ]);

            $targetUser->linkIdentity(
                'username',
                $cleanIdentifier,
                null,
                ['registered_with' => 'organization_member', 'org_id' => $org->id],
                $plainPassword
            );
        }

        // Check if already in this organization
        $existing = OrganizationMember::where('organization_id', $org->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if ($existing) {
            return back()->with('error', __('organization.team.already_member_error'));
        }

        $org->members()->create([
            'user_id' => $targetUser->id,
            'role' => $validated['role'],
            'status' => 'active',
        ]);

        $msg = __('organization.team.member_added');
        if ($generatedPassword) {
            $msg .= ' — ' . __('organization.team.credentials_notice', [
                'username' => $cleanIdentifier,
                'password' => $generatedPassword,
            ]);
        }

        return back()->with('success', $msg);
    }

    /**
     * Update member role or status.
     */
    public function update(Request $request, OrganizationMember $member): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        if ($member->organization_id !== $org->id) {
            abort(403);
        }

        $validated = $request->validate([
            'role' => ['sometimes', 'string', 'in:admin,member'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $member->update($validated);

        return back()->with('success', __('organization.team.member_updated'));
    }

    /**
     * Remove member from organization and release any assigned lines.
     */
    public function destroy(Request $request, OrganizationMember $member): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        if ($member->organization_id !== $org->id) {
            abort(403);
        }

        // Release any dedicated phone line
        PhoneNumber::where('organization_id', $org->id)
            ->where('assigned_user_id', $member->user_id)
            ->update(['assigned_user_id' => null]);

        // Release any dedicated custom ID line
        ServiceResourceAllocation::where('organization_id', $org->id)
            ->where('assigned_user_id', $member->user_id)
            ->update(['assigned_user_id' => null]);

        $member->delete();

        return back()->with('success', __('organization.team.member_removed'));
    }

    /**
     * Assign a dedicated line to a member (Max 1 line rule strictly enforced).
     */
    public function assignLine(Request $request, OrganizationMember $member): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        if ($member->organization_id !== $org->id) {
            abort(403);
        }

        $validated = $request->validate([
            'line_key' => ['required', 'string'],
        ]);

        // Enforce: Employee can only have 1 active assigned line
        if ($member->assigned_line !== null) {
            return back()->with('error', __('organization.team.already_has_line_error'));
        }

        [$type, $id] = explode('_', $validated['line_key'], 2);

        if ($type === 'phone') {
            $phone = PhoneNumber::where(function ($q) use ($org, $user) {
                    $q->where('organization_id', $org->id)
                      ->orWhere('user_id', $user->id);
                })
                ->where('id', $id)
                ->whereNull('assigned_user_id')
                ->firstOrFail();

            $phone->update([
                'organization_id' => $org->id,
                'is_organization' => true,
                'assigned_user_id' => $member->user_id,
            ]);

            return back()->with('success', __('organization.team.line_assigned_success', ['line' => $phone->formatted_number]));
        }

        if ($type === 'custom') {
            $cid = ServiceResourceAllocation::where(function ($q) use ($org, $user) {
                    $q->where('organization_id', $org->id)
                      ->orWhere('user_id', $user->id);
                })
                ->where('id', $id)
                ->whereNull('assigned_user_id')
                ->firstOrFail();

            $cid->update([
                'organization_id' => $org->id,
                'is_organization' => true,
                'assigned_user_id' => $member->user_id,
            ]);

            return back()->with('success', __('organization.team.line_assigned_success', ['line' => $cid->resource_identifier]));
        }

        return back();
    }

    /**
     * Unassign a dedicated line from a member.
     */
    public function unassignLine(Request $request, string $memberParam): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $member = OrganizationMember::where('organization_id', $org->id)
            ->where(function ($q) use ($memberParam) {
                $q->where('id', $memberParam)
                  ->orWhere('user_id', $memberParam);
            })
            ->firstOrFail();

        PhoneNumber::where('organization_id', $org->id)
            ->where('assigned_user_id', $member->user_id)
            ->update(['assigned_user_id' => null]);

        ServiceResourceAllocation::where('organization_id', $org->id)
            ->where('assigned_user_id', $member->user_id)
            ->update(['assigned_user_id' => null]);

        return back()->with('success', __('organization.team.line_unassigned_success'));
    }
}
