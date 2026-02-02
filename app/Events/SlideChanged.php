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

class SlideChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveLessonSession $session,
        public int $slideId,
        public int $teacherId
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("live-session.{$this->session->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'slide.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'slide_id' => $this->slideId,
            'teacher_id' => $this->teacherId,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
