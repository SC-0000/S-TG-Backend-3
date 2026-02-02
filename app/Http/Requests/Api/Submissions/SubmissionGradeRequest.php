<?php

namespace App\Http\Requests\Api\Submissions;

use App\Http\Requests\ApiRequest;

class SubmissionGradeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'items' => 'required|array',
            'items.*' => 'nullable|numeric|min:0',
            'overall_comment' => 'nullable|string',
        ];
    }
}
