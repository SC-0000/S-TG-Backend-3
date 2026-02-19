<?php

namespace App\Http\Requests\Api\SuperAdmin\Organizations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organization = $this->route('organization');

        return [
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organizations', 'slug')->ignore($organization?->id),
            ],
            'public_domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organizations', 'public_domain')->ignore($organization?->id),
            ],
            'portal_domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organizations', 'portal_domain')->ignore($organization?->id),
            ],
            'status' => 'required|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ];
    }
}
