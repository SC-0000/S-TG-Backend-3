<?php

namespace App\Events;

use App\Models\LiveLessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnnotationStroke implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveLessonSession $session,
        public int $slideId,
        public array $strokeData,
        public int $userId,
        public string $userRole
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("live-session.{$this->session->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'annotation.stroke';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'slide_id' => $this->slideId,
            'stroke_data' => $this->strokeData,
            'user_id' => $this->userId,
            'user_role' => $this->userRole,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
