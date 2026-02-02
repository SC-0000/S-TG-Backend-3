<?php

namespace App\Http\Requests\Api\UserSubscriptions;

use App\Http\Requests\Api\ApiRequest;

class UserSubscriptionGrantRequest extends ApiRequest
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
