<?php

namespace App\Http\Requests\Api\Assessments;

use App\Http\Requests\ApiRequest;

class AssessmentAttemptSubmitRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'child_id' => 'required|exists:children,id',
            'answers' => 'required|array',
            'started_at' => 'required|date',
        ];
    }
}
