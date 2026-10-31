<?php

namespace App\Events;

use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;
    public ?int $userId;
    public int $phoneNumberId;
    public int $unreadCount;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->phoneNumberId = $message->phone_number_id;

        $phoneNumber = $message->phoneNumber;
        $this->userId = $phoneNumber?->user_id;

        if ($this->userId) {
            $this->unreadCount = Message::whereHas('phoneNumber', fn ($q) => $q->where('user_id', $this->userId))
                ->where('direction', MessageDirection::Inbound)
                ->where('processing_status', MessageProcessingStatus::Received)
                ->count();
        } else {
            $this->unreadCount = 0;
        }
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.messages'),
            new PrivateChannel('lines.'.$this->phoneNumberId),
        ];

        if ($this->userId) {
            $channels[] = new PrivateChannel('users.'.$this->userId);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /**
     * Get the data that should be broadcast with the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'phone_number_id' => $this->message->phone_number_id,
            'destination_number' => $this->message->destination_number,
            'sender' => $this->message->sender,
            'peer_number' => $this->message->peer_number,
            'direction' => $this->message->direction->value,
            'body' => $this->message->body,
            'processing_status' => $this->message->processing_status->value,
            'server_received_at' => $this->message->server_received_at?->toIso8601String() ?? now()->toIso8601String(),
            'server_received_at_formatted' => $this->message->server_received_at?->diffForHumans() ?? 'هم‌اکنون',
            'client_message_id' => $this->message->client_message_id,
            'user_id' => $this->userId,
            'unread_count' => $this->unreadCount,
        ];
    }
}

