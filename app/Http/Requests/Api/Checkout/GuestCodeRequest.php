<?php

namespace App\Http\Requests\Api\Checkout;

use App\Http\Requests\Api\ApiRequest;

class GuestCodeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'guest_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
