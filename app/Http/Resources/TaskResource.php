<?php

namespace App\Http\Resources;

class TaskResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'assigned_to' => $this->assigned_to,
            'created_by' => $this->created_by,
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->due_date?->toISOString(),
            'priority' => $this->priority,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
