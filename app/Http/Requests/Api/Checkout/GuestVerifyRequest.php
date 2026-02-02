<?php

namespace App\Http\Requests\Api\Checkout;

use App\Http\Requests\Api\ApiRequest;

class GuestVerifyRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'children' => ['nullable', 'array'],
            'children.*.child_name' => ['required_with:children', 'string', 'max:255'],
            'children.*.date_of_birth' => ['nullable', 'date'],
            'children.*.age' => ['nullable', 'integer'],
            'children.*.year_group' => ['nullable', 'string'],
            'children.*.school_name' => ['nullable', 'string'],
            'children.*.area' => ['nullable', 'string'],
        ];
    }
}
