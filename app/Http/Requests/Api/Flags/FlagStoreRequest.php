<?php

namespace App\Http\Requests\Api\Flags;

use App\Http\Requests\Api\ApiRequest;

class FlagStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'assessment_submission_item_id' => ['required', 'exists:assessment_submission_items,id'],
            'child_id' => ['required', 'exists:children,id'],
            'flag_reason' => [
                'required',
                'in:incorrect_grade,unfair_scoring,missed_content,ai_misunderstood,partial_credit_issue,other',
            ],
            'student_explanation' => ['required', 'string', 'min:10', 'max:1000'],
            'original_grade' => ['required', 'numeric', 'min:0'],
        ];
    }
}
