<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class SlideRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['content', 'template_id', 'tags', 'schedule'] as $field) {
            $value = $this->input($field);
            if (!is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $this->merge([$field => $decoded]);
                continue;
            }

            if (in_array($field, ['template_id', 'tags', 'schedule'], true)) {
                $parts = array_filter(array_map('trim', explode(',', $value)));
                $this->merge([$field => $parts]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required'],
            'template_id' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'schedule' => ['nullable', 'array'],
            'order' => ['required', 'integer'],
            'status' => ['required', 'in:active,draft,archived'],
            'version' => ['required', 'integer'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:2048'],
        ];
    }
}
