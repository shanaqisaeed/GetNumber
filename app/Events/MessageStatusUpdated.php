<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStatusUpdated implements ShouldBroadcast
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
     * Name of the queue on which broadcast jobs should be processed.
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
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
            'peer_number' => $this->message->peer_number,
            'processing_status' => $this->message->processing_status->value,
            'processing_status_label' => $this->message->processing_status->label(),
            'updated_at' => $this->message->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
