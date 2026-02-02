<?php

namespace App\Http\Resources;

class AssessmentSubmissionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'child_id' => $this->child_id,
            'user_id' => $this->user_id,
            'retake_number' => $this->retake_number,
            'total_marks' => $this->total_marks,
            'marks_obtained' => $this->marks_obtained,
            'status' => $this->status,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'answers_json' => $this->when(
                $request->boolean('include_answers') || $this->relationLoaded('items'),
                fn () => $this->answers_json
            ),
            'assessment' => $this->whenLoaded('assessment', function () {
                return [
                    'id' => $this->assessment?->id,
                    'title' => $this->assessment?->title,
                ];
            }),
            'child' => $this->whenLoaded('child', function () {
                return [
                    'id' => $this->child?->id,
                    'child_name' => $this->child?->child_name,
                    'year_group' => $this->child?->year_group,
                ];
            }),
            'items' => $this->whenLoaded('items', function () {
                return AssessmentSubmissionItemResource::collection($this->items)->resolve();
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
