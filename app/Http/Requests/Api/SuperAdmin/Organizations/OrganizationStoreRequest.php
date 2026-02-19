<?php

namespace App\Http\Requests\Api\SuperAdmin\Organizations;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:organizations,slug',
            'public_domain' => 'nullable|string|max:255|unique:organizations,public_domain',
            'portal_domain' => 'nullable|string|max:255|unique:organizations,portal_domain',
            'status' => 'required|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ];
    }
}
