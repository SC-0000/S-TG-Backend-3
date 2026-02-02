<?php

namespace App\Http\Requests\Api\SuperAdmin\Organizations;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => 'required|in:super_admin,org_admin,teacher,parent,student',
        ];
    }
}
