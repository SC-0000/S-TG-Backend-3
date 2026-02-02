<?php

namespace App\Events;

use App\Models\LiveLessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlockHighlighted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LiveLessonSession $session,
        public int $slideId,
        public ?string $blockId,
        public bool $highlighted = true
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("live-session.{$this->session->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'block.highlighted';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'slide_id' => $this->slideId,
            'block_id' => $this->blockId,
            'highlighted' => $this->highlighted,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
