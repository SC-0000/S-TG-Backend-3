<?php

namespace App\Http\Requests\Api\Subscriptions;

use App\Http\Requests\Api\ApiRequest;

class SubscriptionUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        $subscription = $this->route('subscription');
        $id = $subscription?->id ?? null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', 'unique:subscriptions,slug,' . $id],
            'features' => ['required', 'array'],
            'content_filters' => ['nullable', 'array'],
        ];
    }
}
