<?php

namespace App\Http\Resources;

class LiveSessionParticipantResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'live_lesson_session_id' => $this->live_lesson_session_id,
            'child_id' => $this->child_id,
            'status' => $this->status,
            'connection_status' => $this->connection_status,
            'hand_raised' => (bool) ($this->hand_raised ?? false),
            'hand_raised_at' => $this->hand_raised_at?->toISOString(),
            'joined_at' => $this->joined_at?->toISOString(),
            'left_at' => $this->left_at?->toISOString(),
            'current_slide_id' => $this->current_slide_id,
            'audio_muted' => (bool) $this->audio_muted,
            'video_off' => (bool) $this->video_off,
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'user_id' => $this->child?->user_id,
                ];
            }),
        ];
    }
}
