<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantMuted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sessionId;
    public $participantId;
    public $childId;
    public $muted;
    public $mutedBy;

    /**
     * Create a new event instance.
     */
    public function __construct($sessionId, $participantId, $childId, $muted, $mutedBy = 'teacher')
    {
        $this->sessionId = $sessionId;
        $this->participantId = $participantId;
        $this->childId = $childId;
        $this->muted = $muted;
        $this->mutedBy = $mutedBy;
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
        return 'participant.muted';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'participantId' => $this->participantId,
            'childId' => $this->childId,
            'muted' => $this->muted,
            'mutedBy' => $this->mutedBy,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
