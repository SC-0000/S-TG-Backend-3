<?php

namespace App\Http\Requests\Api\Questions;

use App\Http\Requests\ApiRequest;
use App\Services\QuestionTypeRegistry;
use Illuminate\Validation\Rule;

class QuestionUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => 'nullable|exists:organizations,id',
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'question_type' => ['required', 'string', Rule::in(array_keys(QuestionTypeRegistry::getAllTypes()))],
            'question_data' => 'required|array',
            'answer_schema' => 'required|array',
            'difficulty_level' => 'integer|min:1|max:10',
            'estimated_time_minutes' => 'nullable|integer|min:1',
            'marks' => 'numeric|min:0',
            'ai_metadata' => 'nullable|array',
            'image_descriptions' => 'nullable|array',
            'image_descriptions.*' => 'nullable|string|max:500',
            'hints' => 'nullable|array',
            'solutions' => 'nullable|array',
            'tags' => 'nullable|array',
            'status' => 'in:draft,active,retired,under_review',
            'images.*' => 'nullable|image|max:2048',
        ];
    }
}
