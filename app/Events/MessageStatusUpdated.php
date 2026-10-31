<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;
    public ?int $userId;
    public int $phoneNumberId;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->phoneNumberId = $message->phone_number_id;
        $this->userId = $message->phoneNumber?->user_id;
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
        return 'message.status.updated';
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
            'processing_status' => $this->message->processing_status->value,
            'client_message_id' => $this->message->client_message_id,
            'user_id' => $this->userId,
        ];
    }
}

