<?php

namespace App\Http\Requests\Api\ParentFeedbacks;

use Illuminate\Foundation\Http\FormRequest;

class ParentFeedbackUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:New,Reviewed,Closed',
            'admin_response' => 'nullable|string|max:2000',
        ];
    }
}
