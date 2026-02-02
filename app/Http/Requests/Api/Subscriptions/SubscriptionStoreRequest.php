<?php

namespace App\Http\Requests\Api\Subscriptions;

use App\Http\Requests\Api\ApiRequest;

class SubscriptionStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', 'unique:subscriptions,slug'],
            'features' => ['required', 'array'],
            'content_filters' => ['nullable', 'array'],
        ];
    }
}
