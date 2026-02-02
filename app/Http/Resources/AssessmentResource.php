<?php

namespace App\Http\Resources;

class AssessmentResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'year_group' => $this->year_group,
            'description' => $this->description,
            'lesson_id' => $this->lesson_id,
            'type' => $this->type,
            'status' => $this->status,
            'availability' => $this->availability?->toISOString(),
            'deadline' => $this->deadline?->toISOString(),
            'time_limit' => $this->time_limit,
            'retake_allowed' => (bool) $this->retake_allowed,
            'journey_category_id' => $this->journey_category_id,
            'organization_id' => $this->organization_id,
            'is_global' => (bool) $this->is_global,
            'questions' => $this->when(
                $request->boolean('include_questions'),
                fn () => $this->getAllQuestions()
            ),
            'lesson' => $this->whenLoaded('lesson', function () {
                return [
                    'id' => $this->lesson?->id,
                    'title' => $this->lesson?->title,
                ];
            }),
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'id' => $this->organization?->id,
                    'name' => $this->organization?->name,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
