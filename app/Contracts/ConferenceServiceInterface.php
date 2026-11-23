<?php

namespace App\Contracts;

use App\Models\ConferenceMessage;
use App\Models\ConferenceParticipant;
use App\Models\ConferencePoll;
use App\Models\ConferencePollVote;
use App\Models\ConferenceRoom;
use App\Models\User;

interface ConferenceServiceInterface
{
    public function createRoom(User $user, array $data): ConferenceRoom;

    public function joinRoom(ConferenceRoom $room, User $user, string $phoneNumber, ?string $pinCode = null, array $clientMeta = []): array;

    public function admitParticipant(ConferenceRoom $room, User $hostUser, int $participantId): ConferenceParticipant;

    public function rejectParticipant(ConferenceRoom $room, User $hostUser, int $participantId): ConferenceParticipant;

    public function kickParticipant(ConferenceRoom $room, User $hostUser, int $participantId): ConferenceParticipant;

    public function toggleMuteParticipant(ConferenceRoom $room, User $actor, int $participantId, ?bool $muteState = null): ConferenceParticipant;

    public function muteAllParticipants(ConferenceRoom $room, User $hostUser): void;

    public function grantScreenShare(ConferenceRoom $room, User $hostUser, int $participantId, bool $canShare): ConferenceParticipant;

    public function startScreenShare(ConferenceRoom $room, User $user, int $participantId): ConferenceParticipant;

    public function stopScreenShare(ConferenceRoom $room, User $user, int $participantId): ConferenceParticipant;

    public function toggleRaiseHand(ConferenceRoom $room, User $user): ConferenceParticipant;

    public function leaveRoom(ConferenceRoom $room, User $user): void;

    public function endRoomForAll(ConferenceRoom $room, User $hostUser): void;

    public function sendMessage(ConferenceRoom $room, User $user, string $message, ?int $toUserId = null): ConferenceMessage;

    public function createPoll(ConferenceRoom $room, User $user, string $question, array $options): ConferencePoll;

    public function votePoll(ConferencePoll $poll, User $user, int $optionIndex): ConferencePollVote;

    public function cleanupExpiredRooms(): int;
}
