<?php

namespace App\Http\Resources;

class TeacherApplicationResource extends ApiResource
{
    public function toArray($request): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'task_id' => $this->id,
            'task_type' => $this->task_type,
            'status' => $this->status,
            'title' => $this->title,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'organization_id' => $this->organization_id,
            'related_entity' => $this->related_entity,
            'metadata' => $metadata,
            'applicant' => $this->relationLoaded('applicant') && $this->applicant ? [
                'id' => $this->applicant->id,
                'name' => $this->applicant->name,
                'email' => $this->applicant->email,
                'mobile_number' => $this->applicant->mobile_number,
                'organization_id' => $this->applicant->current_organization_id,
                'metadata' => $this->applicant->metadata,
            ] : null,
        ];
    }
}
