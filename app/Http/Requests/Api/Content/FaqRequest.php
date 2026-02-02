<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class FaqRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');
        if (is_string($tags)) {
            $parts = array_filter(array_map('trim', explode(',', $tags)));
            $this->merge(['tags' => $parts]);
        }
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string'],
            'answer' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'published' => ['required', 'boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
