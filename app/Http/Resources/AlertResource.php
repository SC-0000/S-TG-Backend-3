<?php

namespace App\Http\Resources;

class AlertResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->alert_id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'priority' => $this->priority,
            'start_time' => $this->start_time?->toISOString(),
            'end_time' => $this->end_time?->toISOString(),
            'pages' => $this->pages,
            'additional_context' => $this->additional_context,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
