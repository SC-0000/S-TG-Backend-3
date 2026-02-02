<?php

namespace App\Http\Resources;

class StudentQuestionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'grade' => $this->grade,
            'question_type' => $this->question_type,
            'question_data' => $this->question_data,
            'difficulty_level' => $this->difficulty_level,
            'estimated_time_minutes' => $this->estimated_time_minutes,
            'marks' => $this->marks,
            'tags' => $this->tags,
        ];
    }
}
