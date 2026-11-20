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
            'email' => ['required', 'email', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:admin,member'],
        ]);

        // Find or create member user
        $targetUser = User::where('email', $validated['email'])->first();
        if (! $targetUser) {
            $targetUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make(Str::random(16)),
                'account_tier' => 'enterprise',
            ]);
        }

        // Check if already in this organization
        $existing = OrganizationMember::where('organization_id', $org->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if ($existing) {
            return back()->with('error', __('organization.team.already_has_line_error'));
        }

        $org->members()->create([
            'user_id' => $targetUser->id,
            'role' => $validated['role'],
            'status' => 'active',
        ]);

        return back()->with('success', __('organization.team.member_added'));
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
            $phone = PhoneNumber::where('organization_id', $org->id)
                ->where('id', $id)
                ->whereNull('assigned_user_id')
                ->firstOrFail();

            $phone->update(['assigned_user_id' => $member->user_id]);

            return back()->with('success', __('organization.team.line_assigned_success', ['line' => $phone->formatted_number]));
        }

        if ($type === 'custom') {
            $cid = ServiceResourceAllocation::where('organization_id', $org->id)
                ->where('id', $id)
                ->whereNull('assigned_user_id')
                ->firstOrFail();

            $cid->update(['assigned_user_id' => $member->user_id]);

            return back()->with('success', __('organization.team.line_assigned_success', ['line' => $cid->resource_identifier]));
        }

        return back();
    }

    /**
     * Unassign a dedicated line from a member.
     */
    public function unassignLine(Request $request, OrganizationMember $member): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        if ($member->organization_id !== $org->id) {
            abort(403);
        }

        PhoneNumber::where('organization_id', $org->id)
            ->where('assigned_user_id', $member->user_id)
            ->update(['assigned_user_id' => null]);

        ServiceResourceAllocation::where('organization_id', $org->id)
            ->where('assigned_user_id', $member->user_id)
            ->update(['assigned_user_id' => null]);

        return back()->with('success', __('organization.team.line_unassigned_success'));
    }
}
