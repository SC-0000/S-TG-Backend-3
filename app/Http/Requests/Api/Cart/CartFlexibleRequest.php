<?php

namespace App\Http\Requests\Api\Cart;

use App\Http\Requests\Api\ApiRequest;

class CartFlexibleRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'selected_lessons' => ['nullable', 'array'],
            'selected_lessons.*' => ['integer', 'exists:live_sessions,id'],
            'selected_assessments' => ['nullable', 'array'],
            'selected_assessments.*' => ['integer', 'exists:assessments,id'],
        ];
    }
}
