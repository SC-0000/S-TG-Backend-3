<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class TestimonialRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'user_name' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['required', 'in:Pending,Approved,Declined'],
            'admin_comment' => ['nullable', 'string'],
            'submission_date' => ['nullable', 'date'],
            'user_ip' => ['nullable', 'string', 'max:45'],
            'display_order' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
