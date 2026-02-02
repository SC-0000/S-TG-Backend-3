<?php

namespace App\Http\Requests\Api\Homework;

use App\Http\Requests\Api\ApiRequest;

class HomeworkSubmissionStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'child_id' => ['required', 'integer', 'exists:children,id'],
            'submission_status' => ['sometimes', 'in:draft,submitted'],
            'content' => ['nullable', 'string'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
