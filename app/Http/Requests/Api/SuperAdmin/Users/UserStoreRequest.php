<?php

namespace App\Http\Requests\Api\SuperAdmin\Users;

use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,teacher,parent,super_admin',
            'current_organization_id' => 'nullable|exists:organizations,id',
        ];
    }
}
