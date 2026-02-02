<?php

namespace App\Http\Requests\Api\Applications;

use Illuminate\Foundation\Http\FormRequest;

class ApplicationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:Approved,Rejected',
            'admin_feedback' => 'nullable|string',
        ];
    }
}
