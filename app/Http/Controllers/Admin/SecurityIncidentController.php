<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityIncidentController extends Controller
{
    /**
     * Display a listing of security incidents with filters.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $query = SecurityIncident::with(['user', 'phoneNumber', 'resolver']);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('type', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->whereHas('identities', fn ($iq) => $iq->where('provider_id', 'like', "%{$search}%"))->orWhere('account_id', 'like', "%{$search}%")
                         ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('resolved')) {
            if ($request->boolean('resolved')) {
                $query->whereNotNull('resolved_at');
            } else {
                $query->whereNull('resolved_at');
            }
        }

        $incidents = $query->latest('id')
            ->paginate(20)
            ->withQueryString();

        $stats = app(\App\Contracts\SystemCacheServiceInterface::class)->getSecurityIncidentsStats();

        if ($request->wantsJson()) {
            return response()->json([
                'incidents' => $incidents,
                'stats' => $stats,
            ]);
        }

        return Inertia::render('Admin/Security/Incidents', [
            'incidents' => $incidents,
            'stats' => $stats,
            'filters' => $request->only(['search', 'severity', 'type', 'resolved']),
        ]);
    }

    /**
     * Resolve a security incident.
     */
    public function resolve(Request $request, SecurityIncident $incident): JsonResponse|RedirectResponse
    {
        $admin = $request->user();

        $incident->markAsResolved($admin);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'security_incident_resolved',
            'entity_type' => SecurityIncident::class,
            'entity_id' => $incident->id,
            'payload' => [
                'incident_type' => $incident->type,
                'incident_user_id' => $incident->user_id,
            ],
            'ip_address' => $request->ip(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('controllers.admin.security.incident_resolved'),
            ]);
        }

        return back()->with('success', __('controllers.admin.security.incident_resolved'));
    }

    /**
     * Update a user's trust tier or reset risk score manually by administrator.
     */
    public function updateUserTier(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'trust_tier' => ['required', 'string', 'in:new,standard,trusted,restricted'],
            'reset_risk_score' => ['nullable', 'boolean'],
        ]);

        $prevTier = $user->trust_tier;
        $updates = ['trust_tier' => $validated['trust_tier']];

        if (! empty($validated['reset_risk_score'])) {
            $updates['risk_score'] = 0;
        }

        $user->update($updates);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'user_tier_updated',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'payload' => [
                'previous_tier' => $prevTier,
                'new_tier' => $validated['trust_tier'],
                'risk_score_reset' => ! empty($validated['reset_risk_score']),
            ],
            'ip_address' => $request->ip(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('controllers.admin.security.tier_updated', ['tier' => $validated['trust_tier']]),
            ]);
        }

        return back()->with('success', __('controllers.admin.security.tier_updated', ['tier' => $validated['trust_tier']]));
    }
}