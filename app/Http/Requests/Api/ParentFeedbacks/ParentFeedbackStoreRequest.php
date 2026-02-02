<?php

namespace App\Http\Requests\Api\ParentFeedbacks;

use App\Http\Requests\Api\ApiRequest;

class ParentFeedbackStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string'],
            'feature' => ['required', 'string', 'in:general,child_profile,billing,technical,other'],
            'child_id' => ['nullable', 'exists:children,id'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:5120'],
        ];
    }
}
