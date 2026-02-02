<?php

namespace App\Http\Requests\Api\SuperAdmin\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role' => 'required|in:admin,teacher,parent,super_admin',
            'current_organization_id' => 'nullable|exists:organizations,id',
        ];
    }
}
