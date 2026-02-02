<?php

namespace App\Events;

use App\Models\LiveLessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentInteraction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveLessonSession $session,
        public int $childId,
        public string $interactionType,
        public ?array $data = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("live-session.{$this->session->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'student.interaction';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'child_id' => $this->childId,
            'interaction_type' => $this->interactionType,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
