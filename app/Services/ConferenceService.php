<?php

namespace App\Services;

use App\Contracts\ConferenceServiceInterface;
use App\Models\ConferenceMessage;
use App\Models\ConferenceParticipant;
use App\Models\ConferencePoll;
use App\Models\ConferencePollVote;
use App\Models\ConferenceRoom;
use App\Models\PhoneNumber;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConferenceService implements ConferenceServiceInterface
{
    public function createRoom(User $user, array $data): ConferenceRoom
    {
        $roomUuid = 'conf_' . Str::lower(Str::random(12));

        $scheduledStartAt = ! empty($data['scheduled_start_at'])
            ? Carbon::parse($data['scheduled_start_at'])
            : null;

        $scheduledEndAt = ! empty($data['scheduled_end_at'])
            ? Carbon::parse($data['scheduled_end_at'])
            : null;

        // Default 7-day room lifecycle
        $expiresAt = Carbon::now()->addDays(7);

        $rawTitle = trim((string) ($data['title'] ?? ''));
        $title = $rawTitle !== ''
            ? $rawTitle
            : ('گفتگوی سازمانی ' . str_pad((string) mt_rand(100, 999), 3, '0', STR_PAD_LEFT));

        return ConferenceRoom::create([
            'organization_id' => $data['organization_id'] ?? null,
            'created_by_user_id' => $user->id,
            'room_uuid' => $roomUuid,
            'title' => $title,
            'pin_code' => ! empty($data['pin_code']) ? (string) $data['pin_code'] : null,
            'admission_policy' => $data['admission_policy'] ?? 'open',
            'initial_audio_state' => $data['initial_audio_state'] ?? 'open',
            'webinar_mode' => false,
            'screen_share_policy' => $data['screen_share_policy'] ?? 'everyone',
            'mute_others_on_share' => (bool) ($data['mute_others_on_share'] ?? false),
            'allow_whiteboard' => (bool) ($data['allow_whiteboard'] ?? true),
            'watermark_enabled' => (bool) ($data['watermark_enabled'] ?? false),
            'is_locked' => false,
            'max_participants' => (int) ($data['max_participants'] ?? 9999),
            'is_active' => true,
            'scheduled_start_at' => $scheduledStartAt,
            'scheduled_end_at' => $scheduledEndAt,
            'expires_at' => $expiresAt,
            'last_active_at' => Carbon::now(),
        ]);
    }

    public function deleteRoom(ConferenceRoom $room, User $hostUser): void
    {
        $this->ensureHost($room, $hostUser);

        DB::transaction(function () use ($room) {
            ConferenceParticipant::where('conference_room_id', $room->id)
                ->whereIn('status', ['waiting_approval', 'admitted'])
                ->update([
                    'status' => 'left',
                    'left_at' => Carbon::now(),
                    'is_sharing_screen' => false,
                ]);

            $room->update(['is_active' => false]);
            $room->delete();
        });

        Cache::forget("conf_signals:{$room->id}");
    }

    public function joinRoom(ConferenceRoom $room, User $user, string $phoneNumber, ?string $pinCode = null, array $clientMeta = []): array
    {
        if (! $room->is_active || $room->isExpired()) {
            throw new InvalidArgumentException(__('conference.room.expired_notice'));
        }

        if ($room->is_locked && $room->created_by_user_id !== $user->id) {
            throw new InvalidArgumentException(__('conference.room.locked_notice'));
        }

        if ($room->pin_code && $room->created_by_user_id !== $user->id) {
            if (empty($pinCode) || (string) $room->pin_code !== (string) $pinCode) {
                throw new InvalidArgumentException(__('conference.room.pin_code') . ' - ' . __('conference.auth.line_not_authorized'));
            }
        }

        // Single-Seat Line Policy Check:
        // 1. If line is an assigned line in the organization, ensure caller is the assigned user
        $assignedLine = PhoneNumber::where('number', $phoneNumber)->first();
        if ($assignedLine && $assignedLine->assigned_user_id && (int) $assignedLine->assigned_user_id !== (int) $user->id) {
            throw new InvalidArgumentException(__('conference.auth.admin_line_conflict'));
        }

        // 2. Strict Single-Seat Security Policy:
        // No two participants can hold the same phone line OR user account concurrently in the same room.
        $existingActiveParticipant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where(function ($query) use ($phoneNumber, $user) {
                $query->where('phone_number', $phoneNumber)
                      ->orWhere('user_id', $user->id);
            })
            ->whereIn('status', ['waiting_approval', 'admitted'])
            ->whereNull('left_at')
            ->first();

        if ($existingActiveParticipant) {
            // Check if existing session is active (recent presence in last 25 seconds)
            $isStillActive = $existingActiveParticipant->updated_at &&
                $existingActiveParticipant->updated_at->greaterThan(Carbon::now()->subSeconds(25));

            if ($isStillActive) {
                $clientIp = $clientMeta['ip'] ?? request()->ip();
                $isSameSession = ((int) $existingActiveParticipant->user_id === (int) $user->id &&
                    (string) $existingActiveParticipant->ip_address === (string) $clientIp &&
                    (string) $existingActiveParticipant->phone_number === (string) $phoneNumber);

                if (! $isSameSession) {
                    throw new InvalidArgumentException(__('conference.auth.single_seat_violation'));
                }
            } else {
                $existingActiveParticipant->update([
                    'status' => 'left',
                    'left_at' => Carbon::now(),
                ]);
            }
        }

        $isHost = ((int) $room->created_by_user_id === (int) $user->id);

        // Check room capacity
        $currentAdmittedCount = $room->admittedParticipants()
            ->where('user_id', '!=', $user->id)
            ->count();

        if (! $isHost && $currentAdmittedCount >= $room->max_participants) {
            throw new InvalidArgumentException(__('conference.room.capacity_reached'));
        }

        $initialStatus = $isHost
            ? 'admitted'
            : ($room->admission_policy === 'open' ? 'admitted' : 'waiting_approval');

        $canShareScreen = true;
        $defaultMuted = false;
        $initialMuted = array_key_exists('is_muted', $clientMeta) && $clientMeta['is_muted'] !== null
            ? (bool) $clientMeta['is_muted']
            : $defaultMuted;

        // Camera MUST NOT turn on automatically under any circumstances unless user explicitly turned it on
        $initialCameraOn = array_key_exists('is_camera_on', $clientMeta) && $clientMeta['is_camera_on'] !== null
            ? (bool) $clientMeta['is_camera_on']
            : false;

        $participant = ConferenceParticipant::updateOrCreate(
            [
                'conference_room_id' => $room->id,
                'user_id' => $user->id,
            ],
            [
                'phone_number' => $phoneNumber,
                'display_name' => $user->name ?: ($user->account_id ?: __('conference.common.user') . ' #' . $user->id),
                'status' => $initialStatus,
                'role' => $isHost ? 'host' : 'participant',
                'is_muted' => $initialMuted,
                'is_camera_on' => $initialCameraOn,
                'can_share_screen' => $canShareScreen,
                'is_sharing_screen' => false,
                'hand_raised' => false,
                'hand_raised_at' => null,
                'ip_address' => $clientMeta['ip'] ?? request()->ip(),
                'joined_at' => Carbon::now(),
                'left_at' => null,
            ]
        );

        $room->update(['last_active_at' => Carbon::now()]);

        return [
            'room' => $room,
            'participant' => $participant,
            'is_host' => $isHost,
            'status' => $initialStatus,
        ];
    }

    public function admitParticipant(ConferenceRoom $room, User $hostUser, int $participantId): ConferenceParticipant
    {
        $this->ensureHost($room, $hostUser);

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        $participant->update([
            'status' => 'admitted',
            'joined_at' => Carbon::now(),
        ]);

        return $participant;
    }

    public function rejectParticipant(ConferenceRoom $room, User $hostUser, int $participantId): ConferenceParticipant
    {
        $this->ensureHost($room, $hostUser);

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        $participant->update([
            'status' => 'rejected',
            'left_at' => Carbon::now(),
        ]);

        return $participant;
    }

    public function kickParticipant(ConferenceRoom $room, User $hostUser, int $participantId): ConferenceParticipant
    {
        $this->ensureHost($room, $hostUser);

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        $participant->update([
            'status' => 'kicked',
            'left_at' => Carbon::now(),
            'is_sharing_screen' => false,
            'is_camera_on' => false,
            'hand_raised' => false,
        ]);

        return $participant;
    }

    public function toggleMuteParticipant(ConferenceRoom $room, User $actor, int $participantId, ?bool $muteState = null): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        $isHost = ((int) $room->created_by_user_id === (int) $actor->id);
        if (! $isHost && (int) $participant->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException(__('conference.auth.line_not_authorized'));
        }

        $newState = ($muteState !== null) ? $muteState : ! $participant->is_muted;
        $participant->update([
            'is_muted' => $newState,
            // If host unmuted someone who raised hand, lower their hand
            'hand_raised' => ($isHost && ! $newState) ? false : $participant->hand_raised,
            'hand_raised_at' => ($isHost && ! $newState) ? null : $participant->hand_raised_at,
        ]);

        return $participant;
    }

    public function updateMediaState(ConferenceRoom $room, User $user, array $state): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $updates = [];
        if (array_key_exists('is_muted', $state) && $state['is_muted'] !== null) {
            $updates['is_muted'] = (bool) $state['is_muted'];
        }
        if (array_key_exists('is_camera_on', $state) && $state['is_camera_on'] !== null) {
            $updates['is_camera_on'] = (bool) $state['is_camera_on'];
        }

        if (! empty($updates)) {
            $participant->update($updates);
        }

        return $participant;
    }

    public function muteAllParticipants(ConferenceRoom $room, User $hostUser): void
    {
        $this->ensureHost($room, $hostUser);

        ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('status', 'admitted')
            ->where('role', '!=', 'host')
            ->update(['is_muted' => true]);
    }

    public function toggleLockRoom(ConferenceRoom $room, User $hostUser): ConferenceRoom
    {
        $this->ensureHost($room, $hostUser);

        $room->update([
            'is_locked' => ! $room->is_locked,
            'last_active_at' => Carbon::now(),
        ]);

        return $room;
    }

    public function toggleWebinarMode(ConferenceRoom $room, User $hostUser): ConferenceRoom
    {
        $this->ensureHost($room, $hostUser);

        $room->update([
            'webinar_mode' => false,
            'last_active_at' => Carbon::now(),
        ]);

        return $room;
    }

    public function updateRoomSettings(ConferenceRoom $room, User $hostUser, array $settings): ConferenceRoom
    {
        $this->ensureHost($room, $hostUser);

        $updates = ['last_active_at' => Carbon::now()];

        if (array_key_exists('admission_policy', $settings)) {
            $updates['admission_policy'] = $settings['admission_policy'];
        }
        if (array_key_exists('initial_audio_state', $settings)) {
            $updates['initial_audio_state'] = $settings['initial_audio_state'];
        }
        if (array_key_exists('screen_share_policy', $settings)) {
            $updates['screen_share_policy'] = $settings['screen_share_policy'];
        }
        if (array_key_exists('mute_others_on_share', $settings)) {
            $updates['mute_others_on_share'] = (bool) $settings['mute_others_on_share'];
        }
        $updates['webinar_mode'] = false;
        if (array_key_exists('allow_whiteboard', $settings)) {
            $updates['allow_whiteboard'] = (bool) $settings['allow_whiteboard'];
        }
        if (array_key_exists('watermark_enabled', $settings)) {
            $updates['watermark_enabled'] = (bool) $settings['watermark_enabled'];
        }
        if (array_key_exists('is_locked', $settings)) {
            $updates['is_locked'] = (bool) $settings['is_locked'];
        }

        $room->update($updates);

        // Buffer signaling broadcast for all attendees
        $cacheKey = "conf_signals:{$room->id}";
        $signals = Cache::get($cacheKey, []);
        $lastSeq = empty($signals) ? 0 : (int) (end($signals)['seq'] ?? 0);
        $nextSeq = $lastSeq + 1;

        $signals[] = [
            'seq' => $nextSeq,
            'type' => 'room_settings_updated',
            'sender_participant_id' => 0,
            'sender_user_id' => $hostUser->id,
            'sender_phone' => 'SYSTEM',
            'sender_name' => 'SYSTEM',
            'target_participant_id' => null,
            'payload' => [
                'admission_policy' => $room->admission_policy,
                'initial_audio_state' => $room->initial_audio_state,
                'screen_share_policy' => $room->screen_share_policy,
                'mute_others_on_share' => $room->mute_others_on_share,
                'webinar_mode' => $room->webinar_mode,
                'allow_whiteboard' => $room->allow_whiteboard,
                'watermark_enabled' => $room->watermark_enabled,
                'is_locked' => $room->is_locked,
            ],
            'timestamp' => now()->getTimestampMs(),
        ];

        if (count($signals) > 250) {
            $signals = array_slice($signals, -250);
        }

        Cache::put($cacheKey, $signals, now()->addHours(2));

        return $room;
    }

    public function grantScreenShare(ConferenceRoom $room, User $hostUser, int $participantId, bool $canShare): ConferenceParticipant
    {
        $this->ensureHost($room, $hostUser);

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        $participant->update([
            'can_share_screen' => $canShare,
            'is_sharing_screen' => $canShare ? $participant->is_sharing_screen : false,
        ]);

        return $participant;
    }

    public function startScreenShare(ConferenceRoom $room, User $user, int $participantId): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->findOrFail($participantId);

        $allowed = $participant->isHost()
            || $room->screen_share_policy === 'everyone'
            || ($room->screen_share_policy === 'by_permission' && $participant->can_share_screen);

        if (! $allowed) {
            throw new InvalidArgumentException(__('conference.controls.screen_share_denied'));
        }

        // Stop any previous active screen share in the room so only 1 presenter shares at a time
        ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('id', '!=', $participant->id)
            ->where('is_sharing_screen', true)
            ->update(['is_sharing_screen' => false]);

        // Condition check: if mute_others_on_share is enabled, automatically mute all other participants
        if ($room->mute_others_on_share) {
            ConferenceParticipant::where('conference_room_id', $room->id)
                ->where('status', 'admitted')
                ->where('id', '!=', $participant->id)
                ->update(['is_muted' => true]);
        }

        $participant->update(['is_sharing_screen' => true]);

        return $participant;
    }

    public function stopScreenShare(ConferenceRoom $room, User $user, int $participantId): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->findOrFail($participantId);

        $participant->update(['is_sharing_screen' => false]);

        return $participant;
    }

    public function toggleRaiseHand(ConferenceRoom $room, User $user): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $newState = ! $participant->hand_raised;
        $participant->update([
            'hand_raised' => $newState,
            'hand_raised_at' => $newState ? Carbon::now() : null,
        ]);

        return $participant;
    }

    public function leaveRoom(ConferenceRoom $room, User $user): void
    {
        ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->update([
                'status' => 'left',
                'left_at' => Carbon::now(),
                'is_sharing_screen' => false,
                'is_camera_on' => false,
                'hand_raised' => false,
            ]);
    }

    public function endRoomForAll(ConferenceRoom $room, User $hostUser): void
    {
        $this->ensureHost($room, $hostUser);

        ConferenceParticipant::where('conference_room_id', $room->id)
            ->whereIn('status', ['waiting_approval', 'admitted'])
            ->update([
                'status' => 'left',
                'left_at' => Carbon::now(),
                'is_sharing_screen' => false,
                'is_camera_on' => false,
            ]);

        $room->update([
            'is_active' => false,
            'last_active_at' => Carbon::now(),
        ]);

        Cache::forget("conf_signals:{$room->id}");
    }

    public function sendMessage(ConferenceRoom $room, User $user, string $message, ?int $toUserId = null): ConferenceMessage
    {
        return ConferenceMessage::create([
            'conference_room_id' => $room->id,
            'user_id' => $user->id,
            'to_user_id' => $toUserId,
            'message' => trim($message),
        ]);
    }

    public function createPoll(ConferenceRoom $room, User $user, string $question, array $options): ConferencePoll
    {
        $this->ensureHost($room, $user);

        ConferencePoll::where('conference_room_id', $room->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return ConferencePoll::create([
            'conference_room_id' => $room->id,
            'created_by_user_id' => $user->id,
            'question' => trim($question),
            'options' => array_values(array_filter(array_map('trim', $options))),
            'is_active' => true,
        ]);
    }

    public function closePoll(ConferenceRoom $room, User $hostUser, int $pollId): ConferencePoll
    {
        $this->ensureHost($room, $hostUser);

        $poll = ConferencePoll::where('conference_room_id', $room->id)
            ->findOrFail($pollId);

        $poll->update(['is_active' => false]);

        return $poll;
    }

    public function votePoll(ConferencePoll $poll, User $user, int $optionIndex): ConferencePollVote
    {
        if (! $poll->is_active) {
            throw new InvalidArgumentException(__('conference.polls.closed_poll'));
        }

        $options = $poll->options ?? [];
        if ($optionIndex < 0 || $optionIndex >= count($options)) {
            throw new InvalidArgumentException(__('conference.polls.validation_error'));
        }

        return ConferencePollVote::updateOrCreate(
            [
                'conference_poll_id' => $poll->id,
                'user_id' => $user->id,
            ],
            [
                'option_index' => $optionIndex,
            ]
        );
    }

    public function cleanupExpiredRooms(): int
    {
        $threshold = Carbon::now();

        $expiredRooms = ConferenceRoom::where('is_active', true)
            ->where(function ($query) use ($threshold) {
                $query->where('expires_at', '<=', $threshold)
                      ->orWhere('created_at', '<=', Carbon::now()->subDays(7));
            })
            ->get();

        $count = 0;
        foreach ($expiredRooms as $room) {
            DB::transaction(function () use ($room) {
                ConferenceParticipant::where('conference_room_id', $room->id)
                    ->whereIn('status', ['waiting_approval', 'admitted'])
                    ->update([
                        'status' => 'left',
                        'left_at' => Carbon::now(),
                    ]);

                $room->update(['is_active' => false]);
                $room->delete();
            });
            Cache::forget("conf_signals:{$room->id}");
            $count++;
        }

        return $count;
    }

    private function ensureHost(ConferenceRoom $room, User $user): void
    {
        if ((int) $room->created_by_user_id !== (int) $user->id) {
            throw new InvalidArgumentException(__('conference.auth.line_not_authorized'));
        }
    }
}
