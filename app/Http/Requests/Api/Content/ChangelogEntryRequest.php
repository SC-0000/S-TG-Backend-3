<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class ChangelogEntryRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $portals = $this->input('portals');
        if (is_string($portals)) {
            $decoded = json_decode($portals, true);
            if (is_array($decoded)) {
                $this->merge(['portals' => $decoded]);
                return;
            }

            $parts = array_filter(array_map('trim', explode(',', $portals)));
            $this->merge(['portals' => $parts]);
        }
    }

    public function rules(): array
    {
        return [
            'title'        => ['required', 'string', 'max:255'],
            'summary'      => ['required', 'string'],
            'body'         => ['nullable', 'string'],
            'category'     => ['required', 'in:new_feature,improvement,bug_fix,announcement'],
            'portals'      => ['required', 'array', 'min:1'],
            'portals.*'    => ['string', 'in:admin,teacher,parent'],
            'images'       => ['nullable', 'array'],
            'images.*'     => ['string'],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
