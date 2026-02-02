<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class MilestoneRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'description' => ['required', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
            'display_order' => ['nullable', 'integer'],
        ];
    }
}
