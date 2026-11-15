<?php

namespace App\Events;

use App\Models\VoiceCall;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public VoiceCall $call) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.' . $this->call->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'call.received';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->call->id,
            'caller' => $this->call->source_identifier,
            'callerName' => $this->call->source_identifier,
            'line' => $this->call->destination,
            'status' => $this->call->status,
            'created_at' => $this->call->created_at?->toIso8601String(),
        ];
    }
}
