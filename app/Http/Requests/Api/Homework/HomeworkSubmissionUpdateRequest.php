<?php

namespace App\Http\Requests\Api\Homework;

use App\Http\Requests\Api\ApiRequest;

class HomeworkSubmissionUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'submission_status' => ['sometimes', 'in:draft,submitted,graded'],
            'content' => ['nullable', 'string'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:10240'],
            'grade' => ['nullable', 'string', 'max:50'],
            'feedback' => ['nullable', 'string'],
            'manual_grades' => ['sometimes', 'array'],
            'manual_grades.*.score' => ['nullable', 'numeric', 'min:0'],
            'manual_grades.*.max_score' => ['nullable', 'numeric', 'min:0'],
            'manual_grades.*.feedback' => ['nullable', 'string'],
            'manual_grades.*.rubric' => ['nullable', 'array'],
            'manual_grades.*.rubric.*.label' => ['nullable', 'string', 'max:120'],
            'manual_grades.*.rubric.*.score' => ['nullable', 'numeric', 'min:0'],
            'manual_grades.*.rubric.*.max_score' => ['nullable', 'numeric', 'min:0'],
            'manual_grades.*.rubric.*.comment' => ['nullable', 'string'],
        ];
    }
}
