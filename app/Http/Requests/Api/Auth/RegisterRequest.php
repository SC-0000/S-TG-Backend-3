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
            'role' => ['nullable', 'string', 'in:' . implode(',', [User::ROLE_BASIC, User::ROLE_PARENT, User::ROLE_ADMIN])],
            'admin_secret' => [
                'nullable',
                'string',
                'required_if:role,' . User::ROLE_ADMIN,
                function ($attribute, $value, $fail) {
                    if ($this->input('role') !== User::ROLE_ADMIN) {
                        return;
                    }

                    $expected = config('services.admin_registration.secret');
                    if (!$expected) {
                        $fail('Admin registration is not enabled.');
                        return;
                    }

                    if (!hash_equals((string) $expected, (string) $value)) {
                        $fail('Invalid admin registration secret.');
                    }
                },
            ],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
