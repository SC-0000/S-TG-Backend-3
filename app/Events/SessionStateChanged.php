<?php

namespace App\Events;

use App\Models\LiveLessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveLessonSession $session,
        public string $state,
        public ?string $message = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("live-session.{$this->session->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.state.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'state' => $this->state,
            'message' => $this->message,
            'navigation_locked' => $this->session->navigation_locked,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
