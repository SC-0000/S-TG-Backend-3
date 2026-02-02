<?php

namespace App\Http\Requests\Api\Checkout;

use App\Http\Requests\Api\ApiRequest;

class CheckoutStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'in:purchase,gift'],
            'serviceChildren' => ['sometimes', 'array'],
            'serviceChildren.*' => ['integer', 'exists:children,id'],
        ];
    }
}
