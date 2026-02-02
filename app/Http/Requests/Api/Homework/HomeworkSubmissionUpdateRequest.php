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
        ];
    }
}
