<?php

namespace App\Http\Requests\Api\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $promotionId = $this->route('promotion')?->id;

        return [
            'name'                  => 'required|string|max:255',
            'description'           => 'nullable|string|max:1000',
            'code'                  => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'type'                  => 'required|in:coupon_code,auto_discount,bulk_discount',
            'discount_type'         => 'required|in:percentage,fixed_amount',
            'discount_value'        => 'required|numeric|min:0.01',
            'min_purchase_amount'   => 'nullable|numeric|min:0',
            'max_discount_amount'   => 'nullable|numeric|min:0',
            'usage_limit'           => 'nullable|integer|min:1',
            'usage_limit_per_user'  => 'nullable|integer|min:1',
            'starts_at'             => 'nullable|date',
            'ends_at'               => 'nullable|date|after_or_equal:starts_at',
            'is_active'             => 'boolean',
            'applicable_to'         => 'required|in:all,services,products,specific',
            'applicable_item_ids'   => 'nullable|array',
            'applicable_item_ids.*' => 'integer',
            'applicable_item_type'  => 'nullable|required_if:applicable_to,specific|in:service,product',
            'organization_id'       => 'nullable|integer|exists:organizations,id',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Promotion code may only contain letters, numbers, hyphens, and underscores.',
            'discount_value.min' => 'Discount value must be greater than zero.',
            'ends_at.after_or_equal' => 'End date must be on or after the start date.',
        ];
    }
}
