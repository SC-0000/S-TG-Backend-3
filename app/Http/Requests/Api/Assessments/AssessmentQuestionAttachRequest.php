<?php

namespace App\Http\Requests\Api\Assessments;

use App\Http\Requests\ApiRequest;

class AssessmentQuestionAttachRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'question_ids' => 'required_without:items|array',
            'question_ids.*' => 'integer|exists:questions,id',
            'items' => 'required_without:question_ids|array',
            'items.*.question_id' => 'required|integer|exists:questions,id',
            'items.*.order_position' => 'nullable|integer|min:1',
            'items.*.custom_points' => 'nullable|numeric|min:0',
            'items.*.custom_settings' => 'nullable|array',
        ];
    }
}
