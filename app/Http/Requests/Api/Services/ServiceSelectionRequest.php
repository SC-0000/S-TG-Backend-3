<?php

namespace App\Http\Requests\Api\Services;

use App\Http\Requests\Api\ApiRequest;

class ServiceSelectionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'selected_lessons' => ['nullable', 'array'],
            'selected_lessons.*' => ['integer', 'exists:live_sessions,id'],
            'selected_assessments' => ['nullable', 'array'],
            'selected_assessments.*' => ['integer', 'exists:assessments,id'],
        ];
    }
}
