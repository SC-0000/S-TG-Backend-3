<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\ApiRequest;

class ForgotPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }
}
