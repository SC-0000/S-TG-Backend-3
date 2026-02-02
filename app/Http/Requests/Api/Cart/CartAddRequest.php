<?php

namespace App\Http\Requests\Api\Cart;

use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class CartAddRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['service', 'product'])],
            'id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
