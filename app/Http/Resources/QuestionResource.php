<?php

namespace App\Http\Resources;

class QuestionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'grade' => $this->grade,
            'question_type' => $this->question_type,
            'question_data' => $this->question_data,
            'answer_schema' => $this->answer_schema,
            'difficulty_level' => $this->difficulty_level,
            'estimated_time_minutes' => $this->estimated_time_minutes,
            'marks' => $this->marks,
            'ai_metadata' => $this->ai_metadata,
            'image_descriptions' => $this->image_descriptions,
            'hints' => $this->hints,
            'solutions' => $this->solutions,
            'tags' => $this->tags,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator?->id,
                    'name' => $this->creator?->name,
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
