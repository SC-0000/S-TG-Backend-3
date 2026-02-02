<?php

namespace App\Http\Requests\Api\Questions;

use App\Http\Requests\ApiRequest;
use App\Services\QuestionTypeRegistry;
use Illuminate\Validation\Rule;

class QuestionQuickCreateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => 'nullable|exists:organizations,id',
            'title' => 'required|string|max:255',
            'question_type' => ['required', 'string', Rule::in(array_keys(QuestionTypeRegistry::getAllTypes()))],
            'question_data' => 'required|array',
            'answer_schema' => 'nullable|array',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'difficulty_level' => 'nullable|integer|min:1|max:10',
            'estimated_time_minutes' => 'nullable|integer|min:1',
            'marks' => 'required|numeric|min:0',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:draft,active,under_review,retired',
        ];
    }
}
