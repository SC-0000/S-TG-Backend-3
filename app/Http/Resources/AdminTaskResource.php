<?php

namespace App\Http\Resources;

class AdminTaskResource extends ApiResource
{
    public function toArray($request): array
    {
        $assignedUser = null;

        if ($this->relationLoaded('assignedUser')) {
            $assignedUser = $this->assignedUser;
        } elseif ($this->relationLoaded('admin')) {
            $assignedUser = $this->admin;
        }

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'task_type' => $this->task_type,
            'assigned_to' => $this->assigned_to,
            'status' => $this->status,
            'related_entity' => $this->related_entity,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'created_at_formatted' => $this->created_at_formatted ?? null,
            'updated_at_formatted' => $this->updated_at_formatted ?? null,
            'assigned_user' => $assignedUser ? [
                'id' => $assignedUser->id,
                'name' => $assignedUser->name,
                'email' => $assignedUser->email,
            ] : null,
        ];
    }
}
