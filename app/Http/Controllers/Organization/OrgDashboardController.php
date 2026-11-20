<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\ServiceResourceAllocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgDashboardController extends Controller
{
    /**
     * Display the Organization Management Dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();
        $wallet = $org->getOrCreateWallet();

        // 1. Calculate Line Statistics
        $totalPhoneLines = $org->phoneNumbers()->count();
        $assignedPhoneLines = $org->phoneNumbers()->whereNotNull('assigned_user_id')->count();
        $freePhoneLines = $totalPhoneLines - $assignedPhoneLines;

        $totalCustomIds = $org->customIdAllocations()->count();
        $assignedCustomIds = $org->customIdAllocations()->whereNotNull('assigned_user_id')->count();
        $freeCustomIds = $totalCustomIds - $assignedCustomIds;

        $totalLines = $totalPhoneLines + $totalCustomIds;
        $assignedLines = $assignedPhoneLines + $assignedCustomIds;
        $freeLines = $freePhoneLines + $freeCustomIds;

        // 2. Team Members Count
        $totalMembers = $org->members()->count();

        // 3. Recent Transactions
        $recentTransactions = $wallet->transactions()
            ->with('user')
            ->limit(8)
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'is_credit' => $tx->isCredit(),
                'description' => $tx->description,
                'user_name' => $tx->user?->name ?: __('organization.wallet.title'),
                'created_at' => $tx->created_at?->format('Y/m/d H:i'),
            ]);

        // 4. Quick Member List Preview
        $membersPreview = $org->members()
            ->with('user')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'status' => $m->status,
                'assigned_line' => $m->assigned_line,
            ]);

        return Inertia::render('Organization/Dashboard', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'identifier' => $org->identifier,
                'status' => $org->status,
            ],
            'stats' => [
                'total_lines' => $totalLines,
                'assigned_lines' => $assignedLines,
                'free_lines' => $freeLines,
                'total_members' => $totalMembers,
                'balance' => (string) $wallet->balance,
                'currency' => $wallet->currency,
            ],
            'recent_transactions' => $recentTransactions,
            'members_preview' => $membersPreview,
        ]);
    }
}
