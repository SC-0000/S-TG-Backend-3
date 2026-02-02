<?php

namespace App\Events;

use App\Models\LiveLessonSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HandRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $session;
    public $studentId;
    public $studentName;
    public $raised;

    /**
     * Create a new event instance.
     */
    public function __construct(LiveLessonSession $session, $studentId, $studentName, $raised = true)
    {
        $this->session = $session;
        $this->studentId = $studentId;
        $this->studentName = $studentName;
        $this->raised = $raised;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('live-session.' . $this->session->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'hand.raised';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'studentId' => $this->studentId,
            'studentName' => $this->studentName,
            'raised' => $this->raised,
            'timestamp' => now()->toISOString(),
        ];
    }
}
