<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ConferenceServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\ConferenceParticipant;
use App\Models\ConferenceRoom;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConferenceManagementController extends Controller
{
    public function __construct(
        protected ConferenceServiceInterface $conferenceService
    ) {}

    /**
     * Display all conference rooms across organizations with monitoring metrics.
     */
    public function index(Request $request): Response
    {
        $this->conferenceService->cleanupExpiredRooms();

        $query = ConferenceRoom::withTrashed()->with(['creator:id,name,account_id', 'organization', 'activeParticipants']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('room_uuid', 'like', "%{$search}%")
                  ->orWhereHas('creator', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('account_id', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->input('filter') === 'active') {
            $query->whereNull('deleted_at')->where('is_active', true)->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            });
        } elseif ($request->input('filter') === 'expired') {
            $query->where(function ($q) {
                $q->where('is_active', false)
                  ->orWhereNotNull('deleted_at')
                  ->orWhere('expires_at', '<=', Carbon::now());
            });
        }

        $rooms = $query->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (ConferenceRoom $r) {
                return [
                    'id' => $r->id,
                    'room_uuid' => $r->room_uuid,
                    'title' => $r->title,
                    'organization' => $r->organization ? [
                        'id' => $r->organization->id,
                        'name' => $r->organization->name,
                    ] : null,
                    'creator' => $r->creator ? [
                        'id' => $r->creator->id,
                        'name' => $r->creator->name ?: ($r->creator->account_id ?: __('conference.common.user')),
                        'account_id' => $r->creator->account_id,
                    ] : null,
                    'has_pin' => ! empty($r->pin_code),
                    'admission_policy' => $r->admission_policy,
                    'initial_audio_state' => $r->initial_audio_state,
                    'webinar_mode' => (bool) $r->webinar_mode,
                    'screen_share_policy' => $r->screen_share_policy,
                    'allow_whiteboard' => (bool) $r->allow_whiteboard,
                    'watermark_enabled' => (bool) $r->watermark_enabled,
                    'is_locked' => (bool) $r->is_locked,
                    'is_active' => (bool) $r->is_active && ! $r->trashed() && ! $r->isExpired(),
                    'is_expired' => $r->isExpired() || $r->trashed(),
                    'active_count' => $r->activeParticipants->count(),
                    'created_at' => $r->created_at->format('Y-m-d H:i'),
                    'expires_at' => $r->expires_at?->format('Y-m-d H:i'),
                ];
            });

        $activeRoomsCount = ConferenceRoom::active()->count();
        $activeParticipantsCount = ConferenceParticipant::where('status', 'admitted')->count();

        return Inertia::render('Admin/Conferences/Index', [
            'rooms' => $rooms,
            'filters' => $request->only(['search', 'filter']),
            'stats' => [
                'active_rooms' => $activeRoomsCount,
                'active_participants' => $activeParticipantsCount,
            ],
        ]);
    }

    /**
     * Terminate or permanently delete a conference room as admin.
     */
    public function destroy(Request $request, string $uuid): RedirectResponse|JsonResponse
    {
        $room = ConferenceRoom::withTrashed()->where('room_uuid', $uuid)->firstOrFail();

        $room->participants()->whereIn('status', ['waiting_approval', 'admitted'])->update([
            'status' => 'left',
            'left_at' => Carbon::now(),
            'is_sharing_screen' => false,
        ]);

        $room->update(['is_active' => false]);
        $room->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => __('admin.conferences.deleted_success')]);
        }

        return redirect()->back()->with('success', __('admin.conferences.deleted_success'));
    }
}
