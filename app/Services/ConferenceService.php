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

        return ConferenceRoom::create([
            'organization_id' => $data['organization_id'] ?? null,
            'created_by_user_id' => $user->id,
            'room_uuid' => $roomUuid,
            'title' => $data['title'] ?? __('conference.room.room_name_placeholder'),
            'pin_code' => ! empty($data['pin_code']) ? (string) $data['pin_code'] : null,
            'admission_policy' => $data['admission_policy'] ?? 'approval',
            'initial_audio_state' => $data['initial_audio_state'] ?? 'muted',
            'webinar_mode' => (bool) ($data['webinar_mode'] ?? false),
            'screen_share_policy' => $data['screen_share_policy'] ?? 'by_permission',
            'mute_others_on_share' => (bool) ($data['mute_others_on_share'] ?? true),
            'allow_whiteboard' => (bool) ($data['allow_whiteboard'] ?? true),
            'is_locked' => false,
            'max_participants' => (int) ($data['max_participants'] ?? 50),
            'is_active' => true,
            'scheduled_start_at' => $scheduledStartAt,
            'scheduled_end_at' => $scheduledEndAt,
            'expires_at' => $expiresAt,
            'last_active_at' => Carbon::now(),
        ]);
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
                throw new InvalidArgumentException(__('conference.room.pin_code') . ' ' . __('validation.custom.invalid'));
            }
        }

        // Single-Seat Line Policy Check:
        // 1. If line is an assigned line in the organization, ensure caller is the assigned user
        $assignedLine = PhoneNumber::where('number', $phoneNumber)->first();
        if ($assignedLine && $assignedLine->assigned_user_id && $assignedLine->assigned_user_id !== $user->id) {
            // Line is strictly assigned to another organization member
            throw new InvalidArgumentException(__('conference.auth.admin_line_conflict'));
        }

        // 2. Strict Single-Seat: No two participants can hold the same phone line at the same time in the same room
        $existingSeat = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('phone_number', $phoneNumber)
            ->whereIn('status', ['waiting_approval', 'admitted'])
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingSeat) {
            throw new InvalidArgumentException(__('conference.auth.single_seat_violation'));
        }

        $isHost = ($room->created_by_user_id === $user->id);

        // Check room capacity
        $currentAdmittedCount = $room->admittedParticipants()->count();
        if (! $isHost && $currentAdmittedCount >= $room->max_participants) {
            throw new InvalidArgumentException(__('conference.room.capacity_reached'));
        }

        $initialStatus = $isHost
            ? 'admitted'
            : ($room->admission_policy === 'open' ? 'admitted' : 'waiting_approval');

        $canShareScreen = $isHost || ($room->screen_share_policy === 'everyone');
        $initialMuted = ($room->initial_audio_state === 'muted');

        $participant = ConferenceParticipant::updateOrCreate(
            [
                'conference_room_id' => $room->id,
                'user_id' => $user->id,
            ],
            [
                'phone_number' => $phoneNumber,
                'display_name' => $user->name ?: ($user->username ?: __('conference.common.user') . ' #' . $user->id),
                'status' => $initialStatus,
                'role' => $isHost ? 'host' : 'participant',
                'is_muted' => $isHost ? false : $initialMuted,
                'is_camera_on' => false,
                'can_share_screen' => $canShareScreen,
                'is_sharing_screen' => false,
                'hand_raised' => false,
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
        ]);

        return $participant;
    }

    public function toggleMuteParticipant(ConferenceRoom $room, User $actor, int $participantId, ?bool $muteState = null): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        // A user can mute/unmute themselves, or host can mute anyone
        $isHost = ($room->created_by_user_id === $actor->id);
        if (! $isHost && $participant->user_id !== $actor->id) {
            throw new InvalidArgumentException(__('conference.auth.line_not_authorized'));
        }

        $newState = ($muteState !== null) ? $muteState : ! $participant->is_muted;
        $participant->update(['is_muted' => $newState]);

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

    public function grantScreenShare(ConferenceRoom $room, User $hostUser, int $participantId, bool $canShare): ConferenceParticipant
    {
        $this->ensureHost($room, $hostUser);

        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->findOrFail($participantId);

        $participant->update([
            'can_share_screen' => $canShare,
            // If revoked while sharing, immediately stop sharing
            'is_sharing_screen' => $canShare ? $participant->is_sharing_screen : false,
        ]);

        return $participant;
    }

    public function startScreenShare(ConferenceRoom $room, User $user, int $participantId): ConferenceParticipant
    {
        $participant = ConferenceParticipant::where('conference_room_id', $room->id)
            ->where('user_id', $user->id)
            ->findOrFail($participantId);

        if (! $participant->can_share_screen && ! $participant->isHost()) {
            throw new InvalidArgumentException(__('conference.controls.screen_share_denied'));
        }

        // If room policy has mute_others_on_share enabled, automatically mute all other participants
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
            ]);

        $room->update([
            'is_active' => false,
            'last_active_at' => Carbon::now(),
        ]);
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
        // Close prior active polls if any
        ConferencePoll::where('conference_room_id', $room->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return ConferencePoll::create([
            'conference_room_id' => $room->id,
            'created_by_user_id' => $user->id,
            'question' => trim($question),
            'options' => array_values(array_filter($options)),
            'is_active' => true,
        ]);
    }

    public function votePoll(ConferencePoll $poll, User $user, int $optionIndex): ConferencePollVote
    {
        if (! $poll->is_active) {
            throw new InvalidArgumentException(__('conference.polls.closed_poll'));
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
                // Mark all active participants as left
                ConferenceParticipant::where('conference_room_id', $room->id)
                    ->whereIn('status', ['waiting_approval', 'admitted'])
                    ->update([
                        'status' => 'left',
                        'left_at' => Carbon::now(),
                    ]);

                $room->update(['is_active' => false]);
                $room->delete(); // Soft delete
            });
            $count++;
        }

        return $count;
    }

    private function ensureHost(ConferenceRoom $room, User $user): void
    {
        if ($room->created_by_user_id !== $user->id) {
            throw new InvalidArgumentException(__('conference.auth.line_not_authorized'));
        }
    }
}
