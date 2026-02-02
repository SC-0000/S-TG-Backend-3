<?php

namespace App\Http\Requests\Api\Cart;

use App\Http\Requests\Api\ApiRequest;

class CartUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
