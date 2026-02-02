<?php

namespace App\Http\Resources;

class LiveSessionMessageResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'live_session_id' => $this->live_session_id,
            'child_id' => $this->child_id,
            'message' => $this->message,
            'type' => $this->type,
            'is_answered' => (bool) $this->is_answered,
            'answer' => $this->answer,
            'answered_by' => $this->answered_by,
            'answered_at' => $this->answered_at?->toISOString(),
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'user_id' => $this->child?->user_id,
                ];
            }),
            'answered_by_user' => $this->whenLoaded('answeredBy', function () {
                return [
                    'id' => $this->answeredBy?->id,
                    'name' => $this->answeredBy?->name,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
