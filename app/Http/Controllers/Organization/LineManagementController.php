<?php

namespace App\Http\Controllers\Organization;

use App\Enums\PhoneNumberStatus;
use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\ServiceResourceAllocation;
use Illuminate\Http\RedirectResponse;
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

        // 1. Organization phone numbers
        $phoneLines = $org->phoneNumbers()
            ->with(['assignedUser', 'phonePrefix'])
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'key' => 'phone_' . $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'display_title' => $p->display_title ?: $p->effective_title,
                'type' => 'number',
                'type_label' => __('softphone.line.type_phone'),
                'status' => $p->status->value,
                'assigned_to' => $p->assignedUser ? [
                    'id' => $p->assignedUser->id,
                    'name' => $p->assignedUser->name,
                    'email' => $p->assignedUser->email,
                ] : null,
                'allocated_at' => $p->allocated_at?->format('Y/m/d'),
            ]);

        // 2. Organization custom IDs
        $customIdLines = $org->customIdAllocations()
            ->with('assignedUser')
            ->get()
            ->map(fn ($cid) => [
                'id' => $cid->id,
                'key' => 'custom_' . $cid->id,
                'number' => $cid->resource_identifier,
                'formatted_number' => $cid->resource_identifier,
                'display_title' => $cid->display_title ?: $cid->resource_identifier,
                'type' => 'custom_id',
                'type_label' => __('softphone.line.type_custom_id'),
                'status' => $cid->status,
                'assigned_to' => $cid->assignedUser ? [
                    'id' => $cid->assignedUser->id,
                    'name' => $cid->assignedUser->name,
                    'email' => $cid->assignedUser->email,
                ] : null,
                'allocated_at' => $cid->allocated_at?->format('Y/m/d'),
            ]);

        $lines = $phoneLines->concat($customIdLines)->values();

        return Inertia::render('Organization/Lines/Index', [
            'lines' => $lines,
        ]);
    }
}
