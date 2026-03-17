<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'password' => ['sometimes', 'required', 'confirmed', 'min:8'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'avatar' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
        ];
    }
}
