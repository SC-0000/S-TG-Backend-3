<?php

namespace App\Http\Resources;

class LessonProgressResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'child_id' => $this->child_id,
            'lesson_id' => $this->lesson_id,
            'status' => $this->status,
            'slides_viewed' => $this->slides_viewed ?? [],
            'last_slide_id' => $this->last_slide_id,
            'completion_percentage' => $this->completion_percentage,
            'time_spent_seconds' => $this->time_spent_seconds,
            'score' => $this->score,
            'checks_passed' => $this->checks_passed,
            'checks_total' => $this->checks_total,
            'questions_attempted' => $this->questions_attempted,
            'questions_correct' => $this->questions_correct,
            'questions_score' => $this->questions_score,
            'accuracy' => $this->accuracy,
            'uploads_submitted' => $this->uploads_submitted,
            'uploads_required' => $this->uploads_required,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'last_accessed_at' => $this->last_accessed_at?->toISOString(),
        ];
    }
}
