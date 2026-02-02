<?php

namespace App\Http\Resources;

class HomeworkSubmissionResource extends ApiResource
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
            'assignment_id' => $this->assignment_id,
            'student_id' => $this->student_id,
            'organization_id' => $this->organization_id,
            'submission_status' => $this->submission_status,
            'content' => $this->content,
            'attachments' => $attachments,
            'grade' => $this->grade,
            'feedback' => $this->feedback,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
