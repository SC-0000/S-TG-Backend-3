<?php

namespace App\Http\Requests\Api\SuperAdmin\Subscriptions;

use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class SubscriptionStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        $subscriptionId = $this->route('subscription')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'alpha_dash',
                'max:255',
                Rule::unique('subscriptions', 'slug')->ignore($subscriptionId),
            ],
            'features' => ['required', 'array'],
            'content_filters' => ['nullable', 'array'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_interval' => ['nullable', 'in:monthly,yearly,one_time'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
