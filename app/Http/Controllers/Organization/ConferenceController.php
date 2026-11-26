<?php

namespace App\Http\Controllers\Organization;

use App\Contracts\ConferenceServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Controllers\User\SoftphoneDashboardController;
use App\Models\ConferenceMessage;
use App\Models\ConferenceParticipant;
use App\Models\ConferencePoll;
use App\Models\ConferenceRoom;
use App\Models\SystemSetting;
use App\Services\Communication\IceServerProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * Display a list of active and scheduled organization conference rooms and room creation modal.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        // 7-day auto-expire check
        $this->conferenceService->cleanupExpiredRooms();

        $activeRooms = ConferenceRoom::active()
            ->where(function ($query) use ($user, $org) {
                $query->where('organization_id', $org->id)
                      ->orWhere('created_by_user_id', $user->id)
                      ->orWhereHas('participants', function ($pq) use ($user) {
                          $pq->where('user_id', $user->id);
                      });
            })
            ->with(['creator:id,name,account_id', 'activeParticipants'])
            ->latest()
            ->get()
            ->map(function (ConferenceRoom $room) use ($user) {
                return [
                    'id' => $room->id,
                    'room_uuid' => $room->room_uuid,
                    'title' => $room->title,
                    'is_host' => (int) $room->created_by_user_id === (int) $user->id,
                    'creator_name' => $room->creator?->name ?: ($room->creator?->account_id ?: __('conference.common.user')),
                    'has_pin' => ! empty($room->pin_code),
                    'admission_policy' => $room->admission_policy,
                    'webinar_mode' => false,
                    'screen_share_policy' => $room->screen_share_policy,
                    'watermark_enabled' => (bool) $room->watermark_enabled,
                    'is_locked' => $room->is_locked,
                    'scheduled_start_at' => $room->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $room->scheduled_end_at?->toIso8601String(),
                    'expires_at' => $room->expires_at?->toIso8601String(),
                    'is_expired' => $room->isExpired(),
                    'is_future' => $room->isScheduledInFuture(),
                    'active_count' => $room->activeParticipants->count(),
                ];
            });

        $operableLines = SoftphoneDashboardController::resolveOperableLines($user);

        return Inertia::render('Organization/Conference/Index', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'identifier' => $org->identifier,
                'status' => $org->status,
            ],
            'rooms' => $activeRooms,
            'operableLines' => $operableLines,
        ]);
    }

    /**
     * Create a new organization conference room.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $validated = $request->validate([
            'title' => 'nullable|string|max:120',
            'pin_code' => 'nullable|string|max:16',
            'admission_policy' => 'nullable|in:open,approval',
            'initial_audio_state' => 'nullable|in:open,muted',
            'webinar_mode' => 'nullable|boolean',
            'screen_share_policy' => 'nullable|in:everyone,host_only,by_permission',
            'mute_others_on_share' => 'nullable|boolean',
            'allow_whiteboard' => 'nullable|boolean',
            'watermark_enabled' => 'nullable|boolean',
            'scheduled_start_at' => 'nullable|date',
            'scheduled_end_at' => 'nullable|date|after:scheduled_start_at',
        ]);

        $validated['organization_id'] = $org->id;

        $room = $this->conferenceService->createRoom($user, $validated);

        return redirect()->route('org.conference.lobby', ['uuid' => $room->room_uuid])
            ->with('success', __('conference.room.created_success'));
    }

    /**
     * Delete/Archive a conference room by its host.
     */
    /**
     * Resolve ConferenceRoom by UUID, prefix-stripped/added identifier, or full URL snippet.
     */
    protected function resolveRoom(string $uuid): ConferenceRoom
    {
        $clean = trim($uuid);
        $clean = preg_replace('#/(lobby|room)$#i', '', $clean);
        if (str_contains($clean, 'conference/')) {
            $parts = explode('conference/', $clean);
            $clean = end($parts);
            $clean = explode('/', $clean)[0];
        }

        $room = ConferenceRoom::where('room_uuid', $clean)->first();
        if ($room) {
            return $room;
        }

        if (! str_starts_with($clean, 'conf_')) {
            $room = ConferenceRoom::where('room_uuid', 'conf_' . $clean)->first();
            if ($room) {
                return $room;
            }
        } else {
            $room = ConferenceRoom::where('room_uuid', substr($clean, 5))->first();
            if ($room) {
                return $room;
            }
        }

        abort(404, __('conference.room.not_found'));
    }

    public function destroy(Request $request, string $uuid): RedirectResponse|JsonResponse
    {
        $room = $this->resolveRoom($uuid);

        try {
            $this->conferenceService->deleteRoom($room, $request->user());
            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => __('conference.room.deleted_success')]);
            }
            return redirect()->route('org.conference.index')->with('success', __('conference.room.deleted_success'));
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
            }
            return redirect()->route('org.conference.index')->with('error', $e->getMessage());
        }
    }

    /**
     * Render the Lobby / Waiting Room for audio/video test and countdown.
     */
    public function lobby(Request $request, string $uuid): Response|RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        try {
            $room = $this->resolveRoom($uuid);
        } catch (\Throwable $e) {
            return redirect()->route('org.conference.index')
                ->with('error', __('conference.room.not_found'));
        }

        if (! $room->is_active || $room->isExpired()) {
            return redirect()->route('org.conference.index')
                ->with('error', __('conference.room.expired_notice'));
        }

        $operableLines = SoftphoneDashboardController::resolveOperableLines($user);

        $existing = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        $isHost = ((int) $room->created_by_user_id === (int) $user->id);

        return Inertia::render('Organization/Conference/Lobby', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'identifier' => $org->identifier,
                'status' => $org->status,
            ],
            'room' => [
                'uuid' => $room->room_uuid,
                'title' => $room->title,
                'is_host' => $isHost,
                'has_pin' => ! empty($room->pin_code),
                'scheduled_start_at' => $room->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $room->scheduled_end_at?->toIso8601String(),
                'is_future' => $room->isScheduledInFuture(),
                'admission_policy' => $room->admission_policy,
                'webinar_mode' => false,
                'is_locked' => $room->is_locked,
            ],
            'operableLines' => $operableLines,
            'existingStatus' => $existing?->status,
        ]);
    }

    /**
     * Join request: verifies Single-Seat line policy, pin code, and initial camera/mic preferences from lobby.
     */
    public function join(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        try {
            $room = $this->resolveRoom($uuid);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('conference.room.not_found'),
            ], 404);
        }

        $validated = $request->validate([
            'phone_number' => 'required|string',
            'pin_code' => 'nullable|string',
            'is_camera_on' => 'nullable|boolean',
            'is_muted' => 'nullable|boolean',
        ]);

        try {
            $result = $this->conferenceService->joinRoom(
                $room,
                $user,
                $validated['phone_number'],
                $validated['pin_code'] ?? null,
                [
                    'ip' => $request->ip(),
                    'is_camera_on' => $request->has('is_camera_on') ? $request->boolean('is_camera_on') : null,
                    'is_muted' => $request->has('is_muted') ? $request->boolean('is_muted') : null,
                ]
            );

            return response()->json([
                'success' => true,
                'status' => $result['status'],
                'is_host' => $result['is_host'],
                'redirect' => $result['status'] === 'admitted'
                    ? route('org.conference.room', ['uuid' => $room->room_uuid])
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
        try {
            $room = $this->resolveRoom($uuid);
        } catch (\Throwable $e) {
            return redirect()->route('org.conference.index')
                ->with('error', __('conference.room.not_found'));
        }

        if (! $room->is_active || $room->isExpired()) {
            return redirect()->route('org.conference.index')
                ->with('error', __('conference.room.expired_notice'));
        }

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        $isHost = ((int) $room->created_by_user_id === (int) $user->id);

        if ($isHost && (! $participant || $participant->status !== 'admitted')) {
            // Auto-admit host if entering room directly
            $operableLines = SoftphoneDashboardController::resolveOperableLines($user);
            $defaultPhone = $operableLines->first()['number'] ?? ($user->account_id ?: ('USER-' . $user->id));
            $joinRes = $this->conferenceService->joinRoom(
                $room,
                $user,
                $defaultPhone,
                null,
                [
                    'ip' => $request->ip(),
                    'is_camera_on' => false,
                    'is_muted' => false,
                ]
            );
            $participant = $joinRes['participant'];
        }

        if (! $participant && $room->admission_policy === 'open') {
            // Auto-admit participant when entering an open room directly
            $operableLines = SoftphoneDashboardController::resolveOperableLines($user);
            $defaultPhone = $operableLines->first()['number'] ?? ($user->account_id ?: ('USER-' . $user->id));
            try {
                $joinRes = $this->conferenceService->joinRoom(
                    $room,
                    $user,
                    $defaultPhone,
                    null,
                    [
                        'ip' => $request->ip(),
                        'is_camera_on' => false,
                        'is_muted' => false,
                    ]
                );
                $participant = $joinRes['participant'];
            } catch (\Throwable $e) {}
        }

        if (! $isHost && (! $participant || $participant->status !== 'admitted')) {
            return redirect()->route('org.conference.lobby', ['uuid' => $room->room_uuid])
                ->with('error', __('conference.lobby.waiting_desc'));
        }

        $rtcConfig = $this->iceServerProvider->rtcConfigFor($user);

        // Resolve SFU WebSocket Endpoint & Port Range from Admin SystemSettings
        $bridgeEnabled = (bool) (int) SystemSetting::get('voice_bridge_enabled', '1');
        $sfuEnabled = $bridgeEnabled && (bool) (int) SystemSetting::get('voice_sfu_enabled', '1');
        $rawSfuPath = trim((string) SystemSetting::get('voice_sfu_ws_path', '/ws/sfu'));
        $sfuWsPath = '/' . ltrim($rawSfuPath ?: 'ws/sfu', '/');
        $sfuMinPort = (int) SystemSetting::get('voice_sfu_min_port', 49152);
        $sfuMaxPort = (int) SystemSetting::get('voice_sfu_max_port', 49250);
        if ($sfuMinPort <= 0) {
            $sfuMinPort = 49152;
        }
        if ($sfuMaxPort <= 0 || $sfuMaxPort < $sfuMinPort) {
            $sfuMaxPort = 49250;
        }

        $bridgeWsPort = (int) SystemSetting::get('voice_bridge_ws_port', 49001);
        if ($bridgeWsPort <= 0) {
            $bridgeWsPort = 49001;
        }

        $explicitSfuPublicUrl = trim((string) SystemSetting::get('voice_sfu_ws_public_url', ''));
        $publicWsUrl = trim((string) SystemSetting::get('voice_bridge_ws_public_url', ''));
        $sfuWsUrl = '';

        if ($explicitSfuPublicUrl !== '') {
            $sfuWsUrl = $explicitSfuPublicUrl;
        } elseif ($publicWsUrl !== '') {
            $sfuWsUrl = preg_replace('#/ws/signaling(\?.*)?$#', $sfuWsPath, $publicWsUrl) ?: $publicWsUrl;
        } else {
            $bridgeHost = trim((string) SystemSetting::get('voice_bridge_host', ''));
            $turnHost = trim((string) SystemSetting::get('voice_turn_domain_or_ip', ''));
            $connMode = trim((string) SystemSetting::get('voice_connection_mode', ''));
            $effectiveHost = '';
            if ($bridgeHost !== '' && $bridgeHost !== '127.0.0.1' && $bridgeHost !== '0.0.0.0') {
                $effectiveHost = $bridgeHost;
            } elseif ($turnHost !== '' && $turnHost !== '127.0.0.1' && $turnHost !== '0.0.0.0') {
                $effectiveHost = $turnHost;
            }
            if ($effectiveHost !== '') {
                $cleanHost = preg_replace('#^https?://#', '', rtrim($effectiveHost, '/'));
                $isDomain = $connMode === 'domain' || (str_contains($cleanHost, '.') && ! preg_match('#^\d{1,3}(\.\d{1,3}){3}$#', $cleanHost));
                if ($isDomain) {
                    $sfuWsUrl = "wss://{$cleanHost}{$sfuWsPath}";
                } else {
                    $sfuWsUrl = "ws://{$cleanHost}:{$bridgeWsPort}{$sfuWsPath}";
                }
            }
        }

        return Inertia::render('Organization/Conference/Room', [
            'room' => [
                'id' => $room->id,
                'uuid' => $room->room_uuid,
                'title' => $room->title,
                'is_host' => $isHost,
                'host_user_id' => $room->created_by_user_id,
                'admission_policy' => $room->admission_policy,
                'initial_audio_state' => $room->initial_audio_state,
                'webinar_mode' => false,
                'screen_share_policy' => $room->screen_share_policy,
                'mute_others_on_share' => $room->mute_others_on_share,
                'allow_whiteboard' => $room->allow_whiteboard,
                'watermark_enabled' => (bool) $room->watermark_enabled,
                'is_locked' => $room->is_locked,
                'max_participants' => $room->max_participants,
            ],
            'currentParticipant' => [
                'id' => $participant->id,
                'user_id' => $user->id,
                'role' => $isHost ? 'host' : ($participant->role ?? 'participant'),
                'phone_number' => $participant->phone_number,
                'display_name' => $participant->display_name,
                'is_muted' => $participant->is_muted,
                'is_camera_on' => $participant->is_camera_on,
                'can_share_screen' => $isHost || $participant->can_share_screen || $room->screen_share_policy === 'everyone',
                'is_sharing_screen' => $participant->is_sharing_screen,
                'hand_raised' => $participant->hand_raised,
            ],
            'rtcConfig' => $rtcConfig,
            'sfuConfig' => [
                'enabled' => $sfuEnabled,
                'wsUrl' => $sfuWsUrl,
                'wsPort' => $bridgeWsPort,
                'wsPath' => $sfuWsPath,
                'minPort' => $sfuMinPort,
                'maxPort' => $sfuMaxPort,
            ],
        ]);
    }

    /**
     * Poll live state: returns participants, messages, active poll, and buffered WebRTC/whiteboard signals.
     */
    public function state(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $selfParticipant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        if ($selfParticipant && in_array($selfParticipant->status, ['waiting_approval', 'admitted'], true)) {
            $selfParticipant->touch();
        }

        $participants = ConferenceParticipant::where('conference_room_id', $room->id)
            ->whereIn('status', ['waiting_approval', 'admitted'])
            ->with('user:id,name,account_id')
            ->orderByRaw("CASE WHEN status = 'waiting_approval' THEN 0 ELSE 1 END")
            ->orderByDesc('hand_raised')
            ->orderBy('hand_raised_at')
            ->orderBy('joined_at')
            ->get()
            ->map(function (ConferenceParticipant $p) use ($user, $room) {
                $isHost = ((int) $p->user_id === (int) $room->created_by_user_id || $p->role === 'host');
                $displayName = $p->display_name ?: ($p->user?->name ?: ($p->user?->account_id ?: ('USER-' . $p->user_id)));
                return [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'is_self' => (int) $p->user_id === (int) $user->id,
                    'phone_number' => $p->phone_number,
                    'display_name' => $displayName,
                    'status' => $p->status,
                    'role' => $isHost ? 'host' : $p->role,
                    'is_muted' => $p->is_muted,
                    'is_camera_on' => $p->is_camera_on,
                    'can_share_screen' => $isHost || $p->can_share_screen || $room->screen_share_policy === 'everyone',
                    'is_sharing_screen' => $p->is_sharing_screen,
                    'hand_raised' => $p->hand_raised,
                    'hand_raised_at' => $p->hand_raised_at?->toIso8601String(),
                ];
            });

        $messages = ConferenceMessage::where('conference_room_id', $room->id)
            ->visibleFor($user->id)
            ->with(['sender:id,name,account_id', 'recipient:id,name,account_id'])
            ->latest()
            ->take(80)
            ->get()
            ->reverse()
            ->values()
            ->map(function (ConferenceMessage $msg) use ($user) {
                return [
                    'id' => $msg->id,
                    'sender_id' => $msg->user_id,
                    'sender_name' => $msg->sender?->name ?: ($msg->sender?->account_id ?: __('conference.common.user')),
                    'is_self' => (int) $msg->user_id === (int) $user->id,
                    'is_private' => $msg->isPrivate(),
                    'recipient_name' => $msg->recipient?->name ?: $msg->recipient?->account_id,
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

        // Retrieve buffered WebRTC / Whiteboard signals since client's last sequence
        $sinceSeq = (int) $request->input('since_seq', 0);
        $allSignals = Cache::get("conf_signals:{$room->id}", []);
        $latestSeq = $sinceSeq;
        $pendingSignals = [];

        $myPartId = $selfParticipant ? (int) $selfParticipant->id : 0;

        $nowMs = now()->getTimestampMs();
        foreach ($allSignals as $sig) {
            $seq = (int) ($sig['seq'] ?? 0);
            if ($seq > $latestSeq) {
                $latestSeq = $seq;
            }
            if ($seq > $sinceSeq && (int) ($sig['sender_participant_id'] ?? 0) !== $myPartId) {
                $sigType = (string) ($sig['type'] ?? '');
                $sigTime = (int) ($sig['timestamp'] ?? 0);

                // Expire transient WebRTC signaling frames older than 12 seconds
                if (in_array($sigType, ['offer', 'answer', 'candidate', 'renegotiate_needed'], true)) {
                    if ($sigTime > 0 && ($nowMs - $sigTime) > 12000) {
                        continue;
                    }
                }

                $targetId = isset($sig['target_participant_id']) && $sig['target_participant_id'] !== null
                    ? (int) $sig['target_participant_id']
                    : null;
                if ($targetId === null || $targetId === $myPartId) {
                    $pendingSignals[] = $sig;
                }
            }
        }

        return response()->json([
            'room' => [
                'is_active' => $room->is_active && ! $room->isExpired(),
                'is_locked' => $room->is_locked,
                'admission_policy' => $room->admission_policy,
                'initial_audio_state' => $room->initial_audio_state,
                'webinar_mode' => false,
                'screen_share_policy' => $room->screen_share_policy,
                'mute_others_on_share' => $room->mute_others_on_share,
                'allow_whiteboard' => $room->allow_whiteboard,
                'watermark_enabled' => (bool) $room->watermark_enabled,
            ],
            'selfStatus' => $selfParticipant?->status ?? 'left',
            'participants' => $participants,
            'messages' => $messages,
            'activePoll' => $pollData,
            'signals' => $pendingSignals,
            'latest_seq' => $latestSeq,
        ]);
    }

    /**
     * Relay WebRTC SDP (offer/answer/candidate) and whiteboard drawing events via Cache queue.
     */
    public function sendSignal(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'type' => 'required|string|max:48',
            'target_participant_id' => 'nullable|integer',
            'payload' => 'nullable',
        ]);

        $cacheKey = "conf_signals:{$room->id}";
        $signals = Cache::get($cacheKey, []);
        $lastSeq = empty($signals) ? 0 : (int) (end($signals)['seq'] ?? 0);
        $nextSeq = $lastSeq + 1;

        $signals[] = [
            'seq' => $nextSeq,
            'type' => $validated['type'],
            'sender_participant_id' => $participant->id,
            'sender_user_id' => $user->id,
            'sender_phone' => $participant->phone_number,
            'sender_name' => $participant->display_name,
            'target_participant_id' => $validated['target_participant_id'] ?? null,
            'payload' => $validated['payload'] ?? null,
            'timestamp' => now()->getTimestampMs(),
        ];

        // Keep last 250 signals to prevent unbounded memory growth
        if (count($signals) > 250) {
            $signals = array_slice($signals, -250);
        }

        Cache::put($cacheKey, $signals, now()->addHours(2));

        return response()->json([
            'success' => true,
            'seq' => $nextSeq,
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

    public function updateMediaState(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $participant = $this->conferenceService->updateMediaState($room, $request->user(), $request->only(['is_muted', 'is_camera_on']));

        return response()->json(['success' => true, 'participant' => $participant]);
    }

    public function muteAll(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $this->conferenceService->muteAllParticipants($room, $request->user());

        return response()->json(['success' => true]);
    }

    public function toggleLock(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $updated = $this->conferenceService->toggleLockRoom($room, $request->user());

        return response()->json([
            'success' => true,
            'is_locked' => $updated->is_locked,
            'message' => $updated->is_locked ? __('conference.room.lock_toggled_locked') : __('conference.room.lock_toggled_unlocked'),
        ]);
    }

    public function toggleWebinar(Request $request, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'webinar_mode' => false,
            'message' => 'Webinar mode is disabled',
        ]);
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

    public function closePoll(Request $request, string $uuid, int $pollId): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();
        $poll = $this->conferenceService->closePoll($room, $request->user(), $pollId);

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

        if ((int) $room->created_by_user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $participants = ConferenceParticipant::where('conference_room_id', $room->id)
            ->with('user:id,name,account_id')
            ->orderBy('joined_at')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"conference-attendance-{$room->room_uuid}.csv\"",
        ];

        return response()->stream(function () use ($participants) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('conference.export.col_row'),
                __('conference.export.col_name'),
                __('conference.export.col_phone'),
                __('conference.export.col_role'),
                __('conference.export.col_status'),
                __('conference.export.col_ip'),
                __('conference.export.col_joined_at'),
                __('conference.export.col_left_at'),
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

    /**
     * Unified room settings update by meeting host.
     */
    public function updateSettings(Request $request, string $uuid): JsonResponse
    {
        $room = ConferenceRoom::where('room_uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'admission_policy' => 'nullable|in:open,approval',
            'initial_audio_state' => 'nullable|in:open,muted',
            'screen_share_policy' => 'nullable|in:everyone,host_only,by_permission',
            'mute_others_on_share' => 'nullable|boolean',
            'webinar_mode' => 'nullable|boolean',
            'allow_whiteboard' => 'nullable|boolean',
            'watermark_enabled' => 'nullable|boolean',
            'is_locked' => 'nullable|boolean',
        ]);

        try {
            $updatedRoom = $this->conferenceService->updateRoomSettings($room, $request->user(), $validated);

            return response()->json([
                'success' => true,
                'room' => [
                    'admission_policy' => $updatedRoom->admission_policy,
                    'initial_audio_state' => $updatedRoom->initial_audio_state,
                    'screen_share_policy' => $updatedRoom->screen_share_policy,
                    'mute_others_on_share' => $updatedRoom->mute_others_on_share,
                    'webinar_mode' => false,
                    'allow_whiteboard' => $updatedRoom->allow_whiteboard,
                    'watermark_enabled' => $updatedRoom->watermark_enabled,
                    'is_locked' => $updatedRoom->is_locked,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }
    }
}
