<?php

namespace App\Http\Requests\Api;

class PasswordUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }
}
