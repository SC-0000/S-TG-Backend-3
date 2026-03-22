<?php

namespace App\Http\Requests\Api\SuperAdmin\Subscriptions;

use App\Http\Requests\Api\ApiRequest;

class SubscriptionGrantRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'subscription_id' => ['required', 'exists:subscriptions,id'],
            'days' => ['nullable', 'integer', 'min:0'],
            'child_id' => ['nullable', 'exists:children,id'],
        ];
    }
}
