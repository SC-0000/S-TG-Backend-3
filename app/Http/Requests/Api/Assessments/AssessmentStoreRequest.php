<?php

namespace App\Http\Requests\Api\Assessments;

use App\Http\Requests\ApiRequest;

class AssessmentStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'year_group' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'lesson_id' => 'nullable|integer|exists:live_sessions,id',
            'type' => 'required|in:mcq,short_answer,essay,mixed',
            'status' => 'required|in:active,inactive,archived',
            'journey_category_id' => 'nullable|integer|exists:journey_categories,id',
            'availability' => 'required|date',
            'deadline' => 'required|date|after:availability',
            'time_limit' => 'nullable|integer',
            'retake_allowed' => 'boolean',
            'questions' => 'nullable|array',
            'questions.*.question_text' => 'required_with:questions|string',
            'questions.*.question_image' => 'nullable|image|max:40960',
            'questions.*.type' => 'required_with:questions|in:mcq,short_answer,essay,matching,cloze,ordering,image_grid_mcq',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answer' => 'nullable',
            'questions.*.marks' => 'required_with:questions|integer',
            'questions.*.category' => 'nullable|string|max:100',
            'questions.*.question_bank_id' => 'nullable|integer|exists:questions,id',
            'is_global' => 'nullable|boolean',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ];
    }
}
