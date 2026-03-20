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
            'teacher_profile' => ['sometimes', 'nullable', 'array'],
            'teacher_profile.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'teacher_profile.category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'teacher_profile.bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'teacher_profile.specialties' => ['sometimes', 'nullable', 'array'],
            'teacher_profile.specialties.*' => ['nullable', 'string', 'max:255'],
            'teacher_profile.metadata' => ['sometimes', 'nullable', 'array'],
            'teacher_profile.metadata.address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
