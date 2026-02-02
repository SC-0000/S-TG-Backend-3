<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\ApiRequest;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['nullable', 'string', 'in:' . implode(',', [User::ROLE_BASIC, User::ROLE_PARENT])],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
