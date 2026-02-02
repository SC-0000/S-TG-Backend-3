<?php

namespace App\Http\Resources;

class HomeworkAssignmentResource extends ApiResource
{
    public function toArray($request): array
    {
        $attachments = $this->attachments ?? [];
        $attachments = collect($attachments)->map(function ($path) {
            if (!$path) {
                return null;
            }
            return str_starts_with($path, '/storage/')
                ? $path
                : "/storage/{$path}";
        })->filter()->values()->all();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'description' => $this->description,
            'subject' => $this->subject,
            'due_date' => $this->due_date?->toISOString(),
            'attachments' => $attachments,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
