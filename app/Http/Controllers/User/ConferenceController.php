<?php

namespace App\Http\Controllers\User;

use App\Contracts\ConferenceServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\ConferenceMessage;
use App\Models\ConferenceParticipant;
use App\Models\ConferencePoll;
use App\Models\ConferenceRoom;
use App\Services\Communication\IceServerProvider;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConferenceController extends Controller
{
    public function __construct(
        protected ConferenceServiceInterface $conferenceService,
        protected IceServerProvider $iceServerProvider
    ) {}

    /**
     * Display a list of active and scheduled conference rooms and room creation modal.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 7-day auto-expire check
        $this->conferenceService->cleanupExpiredRooms();

        $activeRooms = ConferenceRoom::active()
            ->where(function ($query) use ($user) {
                $query->where('created_by_user_id', $user->id)
                      ->orWhereHas('participants', function ($pq) use ($user) {
                          $pq->where('user_id', $user->id);
                      });
            })
            ->with(['creator:id,name,username', 'activeParticipants'])
            ->latest()
            ->get()
            ->map(function (ConferenceRoom $room) use ($user) {
                return [
                    'id' => $room->id,
                    'room_uuid' => $room->room_uuid,
                    'title' => $room->title,
                    'is_host' => $room->created_by_user_id === $user->id,
                    'creator_name' => $room->creator?->name ?: ($room->creator?->username ?: __('conference.common.user')),
                    'has_pin' => ! empty($room->pin_code),
                    'admission_policy' => $room->admission_policy,
                    'webinar_mode' => $room->webinar_mode,
                    'screen_share_policy' => $room->screen_share_policy,
                    'scheduled_start_at' => $room->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $room->scheduled_end_at?->toIso8601String(),
                    'expires_at' => $room->expires_at?->toIso8601String(),
                    'is_expired' => $room->isExpired(),
                    'is_future' => $room->isScheduledInFuture(),
                    'active_count' => $room->activeParticipants->count(),
                ];
            });

        $operableLines = SoftphoneDashboardController::resolveOperableLines($user);

        return Inertia::render('User/Conference/Index', [
            'rooms' => $activeRooms,
            'operableLines' => $operableLines,
        ]);
    }

    /**
     * Create a new conference room.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'pin_code' => 'nullable|string|max:16',
            'admission_policy' => 'required|in:open,approval',
            'initial_audio_state' => 'required|in:open,muted',
            'webinar_mode' => 'boolean',
            'screen_share_policy' => 'required|in:everyone,host_only,by_permission',
            'mute_others_on_share' => 'boolean',
            'allow_whiteboard' => 'boolean',
            'max_participants' => 'nullable|integer|min:2|max:100',
            'scheduled_start_at' => 'nullable|date',
            'scheduled_end_at' => 'nullable|date|after:scheduled_start_at',
        ]);

        $room = $this->conferenceService->createRoom($user, $validated);

        return redirect()->route('conference.lobby', ['uuid' => $room->room_uuid])
            ->with('success', __('conference.room.created_success'));
    }

    /**
     * Render the Lobby / Waiting Room for audio/video test and countdown.
     */
    public function lobby(Request $request, string $uuid): Response|RedirectResponse
    {
        $user = $request->user();

        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        if ($room->isExpired()) {
            return redirect()->route('conference.index')
                ->with('error', __('conference.room.expired_notice'));
        }

        $operableLines = SoftphoneDashboardController::resolveOperableLines($user);

        // Check if user is already admitted
        $existing = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        $isHost = ($room->created_by_user_id === $user->id);

        return Inertia::render('User/Conference/Lobby', [
            'room' => [
                'uuid' => $room->room_uuid,
                'title' => $room->title,
                'is_host' => $isHost,
                'has_pin' => ! empty($room->pin_code),
                'scheduled_start_at' => $room->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $room->scheduled_end_at?->toIso8601String(),
                'is_future' => $room->isScheduledInFuture(),
                'admission_policy' => $room->admission_policy,
                'webinar_mode' => $room->webinar_mode,
            ],
            'operableLines' => $operableLines,
            'existingStatus' => $existing?->status,
        ]);
    }

    /**
     * Join request: verifies Single-Seat line policy and pin code.
     */
    public function join(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'phone_number' => 'required|string',
            'pin_code' => 'nullable|string',
        ]);

        try {
            $result = $this->conferenceService->joinRoom(
                $room,
                $user,
                $validated['phone_number'],
                $validated['pin_code'] ?? null,
                ['ip' => $request->ip()]
            );

            return response()->json([
                'success' => true,
                'status' => $result['status'],
                'is_host' => $result['is_host'],
                'redirect' => $result['status'] === 'admitted'
                    ? route('conference.room', ['uuid' => $room->room_uuid])
                    : null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Render the active Conference Room UI.
     */
    public function room(Request $request, string $uuid): Response|RedirectResponse
    {
        $user = $request->user();
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        if (! $room->is_active || $room->isExpired()) {
            return redirect()->route('conference.index')
                ->with('error', __('conference.room.expired_notice'));
        }

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        $isHost = ($room->created_by_user_id === $user->id);

        if (! $isHost && (! $participant || $participant->status !== 'admitted')) {
            return redirect()->route('conference.lobby', ['uuid' => $room->room_uuid])
                ->with('error', __('conference.lobby.waiting_desc'));
        }

        $iceServers = $this->iceServerProvider->getIceServers();

        return Inertia::render('User/Conference/Room', [
            'room' => [
                'id' => $room->id,
                'uuid' => $room->room_uuid,
                'title' => $room->title,
                'is_host' => $isHost,
                'admission_policy' => $room->admission_policy,
                'webinar_mode' => $room->webinar_mode,
                'screen_share_policy' => $room->screen_share_policy,
                'mute_others_on_share' => $room->mute_others_on_share,
                'allow_whiteboard' => $room->allow_whiteboard,
                'is_locked' => $room->is_locked,
                'max_participants' => $room->max_participants,
            ],
            'currentParticipant' => [
                'id' => $participant?->id,
                'role' => $isHost ? 'host' : ($participant?->role ?? 'participant'),
                'phone_number' => $participant?->phone_number ?? ($user->username ?: '09120000000'),
                'display_name' => $participant?->display_name ?? $user->name,
                'is_muted' => $participant?->is_muted ?? false,
                'is_camera_on' => $participant?->is_camera_on ?? false,
                'can_share_screen' => $participant?->can_share_screen ?? $isHost,
                'is_sharing_screen' => $participant?->is_sharing_screen ?? false,
                'hand_raised' => $participant?->hand_raised ?? false,
            ],
            'iceServers' => $iceServers,
            'sfuConfig' => [
                'enabled' => config('services.voice_bridge.sfu_enabled', true),
                'wsUrl' => config('services.voice_bridge.sfu_ws_url', '/ws/sfu'),
            ],
        ]);
    }

    /**
     * Poll live state: returns participants, messages, and polls.
     */
    public function state(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $participants = ConferenceParticipant::where('conference_room_id', $room->id)
            ->whereIn('status', ['waiting_approval', 'admitted'])
            ->with('user:id,name,username')
            ->orderByRaw("FIELD(status, 'admitted', 'waiting_approval')")
            ->orderByDesc('hand_raised')
            ->orderBy('joined_at')
            ->get()
            ->map(function (ConferenceParticipant $p) use ($user) {
                return [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'is_self' => $p->user_id === $user->id,
                    'phone_number' => $p->phone_number,
                    'display_name' => $p->display_name,
                    'status' => $p->status,
                    'role' => $p->role,
                    'is_muted' => $p->is_muted,
                    'is_camera_on' => $p->is_camera_on,
                    'can_share_screen' => $p->can_share_screen,
                    'is_sharing_screen' => $p->is_sharing_screen,
                    'hand_raised' => $p->hand_raised,
                    'hand_raised_at' => $p->hand_raised_at?->toIso8601String(),
                ];
            });

        $messages = ConferenceMessage::where('conference_room_id', $room->id)
            ->visibleFor($user->id)
            ->with(['sender:id,name,username', 'recipient:id,name,username'])
            ->latest()
            ->take(60)
            ->get()
            ->reverse()
            ->values()
            ->map(function (ConferenceMessage $msg) use ($user) {
                return [
                    'id' => $msg->id,
                    'sender_id' => $msg->user_id,
                    'sender_name' => $msg->sender?->name ?: ($msg->sender?->username ?: __('conference.common.user')),
                    'is_self' => $msg->user_id === $user->id,
                    'is_private' => $msg->isPrivate(),
                    'recipient_name' => $msg->recipient?->name ?: $msg->recipient?->username,
                    'message' => $msg->message,
                    'created_at' => $msg->created_at->format('H:i'),
                ];
            });

        $activePoll = ConferencePoll::where('conference_room_id', $room->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $pollData = null;
        if ($activePoll) {
            $userVote = $activePoll->votes()->where('user_id', $user->id)->first();
            $pollData = [
                'id' => $activePoll->id,
                'question' => $activePoll->question,
                'options' => $activePoll->options,
                'has_voted' => $userVote !== null,
                'user_voted_index' => $userVote?->option_index,
                'results' => $activePoll->getResultsSummary(),
            ];
        }

        return response()->json([
            'room' => [
                'is_active' => $room->is_active,
                'is_locked' => $room->is_locked,
                'webinar_mode' => $room->webinar_mode,
            ],
            'participants' => $participants,
            'messages' => $messages,
            'activePoll' => $pollData,
        ]);
    }

    public function admitParticipant(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = $this->conferenceService->admitParticipant($room, $request->user(), $participantId);

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function rejectParticipant(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = $this->conferenceService->rejectParticipant($room, $request->user(), $participantId);

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function kickParticipant(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = $this->conferenceService->kickParticipant($room, $request->user(), $participantId);

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function toggleMute(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $muteState = $request->has('is_muted') ? (bool) $request->input('is_muted') : null;
        $participant = $this->conferenceService->toggleMuteParticipant($room, $request->user(), $participantId, $muteState);

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function muteAll(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $this->conferenceService->muteAllParticipants($room, $request->user());

        return response()->json(['success' => true]);
    }

    public function grantScreenShare(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $canShare = (bool) $request->input('can_share', true);
        $participant = $this->conferenceService->grantScreenShare($room, $request->user(), $participantId, $canShare);

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function startScreenShare(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $updated = $this->conferenceService->startScreenShare($room, $request->user(), $participant->id);
            return response()->json(['success' => true, 'participant' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    public function stopScreenShare(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $updated = $this->conferenceService->stopScreenShare($room, $request->user(), $participant->id);
        return response()->json(['success' => true, 'participant' => $updated]);
    }

    public function toggleRaiseHand(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = $this->conferenceService->toggleRaiseHand($room, $request->user());

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function leave(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $this->conferenceService->leaveRoom($room, $request->user());

        return response()->json(['success' => true]);
    }

    public function endRoom(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $this->conferenceService->endRoomForAll($room, $request->user());

        return response()->json(['success' => true]);
    }

    public function sendMessage(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'to_user_id' => 'nullable|exists:users,id',
        ]);

        $msg = $this->conferenceService->sendMessage(
            $room,
            $request->user(),
            $validated['message'],
            $validated['to_user_id'] ?? null
        );

        return response()->json(['success' => true, 'message' => $msg]);
    }

    public function createPoll(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'question' => 'required|string|max:255',
            'options' => 'required|array|min:2|max:6',
            'options.*' => 'required|string|max:100',
        ]);

        $poll = $this->conferenceService->createPoll($room, $request->user(), $validated['question'], $validated['options']);

        return response()->json(['success' => true, 'poll' => $poll]);
    }

    public function votePoll(Request $request, string $uuid, int $pollId): JsonResponse
    {
        $poll = ConferencePoll::where('id', $pollId)->firstOrFail();

        $validated = $request->validate([
            'option_index' => 'required|integer|min:0',
        ]);

        try {
            $vote = $this->conferenceService->votePoll($poll, $request->user(), (int) $validated['option_index']);
            return response()->json(['success' => true, 'vote' => $vote, 'results' => $poll->getResultsSummary()]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function exportAttendance(Request $request, string $uuid): StreamedResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        if ($room->created_by_user_id !== $request->user()->id) {
            abort(403);
        }

        $participants = ConferenceParticipant::where('conference_room_id', $room->id)
            ->with('user:id,name,username')
            ->orderBy('joined_at')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"conference-attendance-{$room->room_uuid}.csv\"",
        ];

        return response()->stream(function () use ($participants) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel Persian display

            fputcsv($handle, [
                'ردیف',
                'نام شرکت‌کننده',
                'شماره تلفن تاییدشده',
                'نقش',
                'وضعیت نهایی',
                'آی‌پی اتصال',
                'زمان ورود',
                'زمان خروج',
            ]);

            foreach ($participants as $index => $p) {
                fputcsv($handle, [
                    $index + 1,
                    $p->display_name,
                    $p->phone_number,
                    $p->role,
                    $p->status,
                    $p->ip_address,
                    $p->joined_at ? $p->joined_at->format('Y-m-d H:i:s') : '-',
                    $p->left_at ? $p->left_at->format('Y-m-d H:i:s') : '-',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
