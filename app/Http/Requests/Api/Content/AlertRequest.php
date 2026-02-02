<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class AlertRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $pages = $this->input('pages');
        if (is_string($pages)) {
            $decoded = json_decode($pages, true);
            if (is_array($decoded)) {
                $this->merge(['pages' => $decoded]);
                return;
            }

            $parts = array_filter(array_map('trim', explode(',', $pages)));
            $this->merge(['pages' => $parts]);
        }
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'type' => ['required', 'in:info,warning,success,error'],
            'priority' => ['required', 'integer'],
            'start_time' => ['required', 'date'],
            'end_time' => ['nullable', 'date', 'after_or_equal:start_time'],
            'pages' => ['nullable', 'array'],
            'pages.*' => ['string'],
            'additional_context' => ['nullable', 'string', 'max:64'],
        ];
    }
}
