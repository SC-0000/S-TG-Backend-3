<?php

namespace App\Http\Requests\Api\SuperAdmin\Users;

use Illuminate\Foundation\Http\FormRequest;

class UserRoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => 'required|in:admin,teacher,parent,super_admin',
        ];
    }
}
