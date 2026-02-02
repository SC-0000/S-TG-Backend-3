<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $participant;

    /**
     * Create a new event instance.
     */
    public function __construct($sessionId, $participant)
    {
        $this->sessionId = $sessionId;
        $this->participant = $participant;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('live-session.' . $this->sessionId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'participant.joined';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'participant' => [
                'id' => $this->participant['id'],
                'child_id' => $this->participant['child_id'],
                'child_name' => $this->participant['child_name'],
                'joined_at' => $this->participant['joined_at'],
                'status' => $this->participant['status'],
                'connection_status' => $this->participant['connection_status'],
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
