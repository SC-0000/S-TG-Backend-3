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
            'content_filters.type' => ['nullable', 'in:year_group,custom,all'],
            'content_filters.course_ids' => ['nullable', 'array'],
            'content_filters.course_ids.*' => ['integer'],
            'content_filters.lesson_ids' => ['nullable', 'array'],
            'content_filters.lesson_ids.*' => ['integer'],
            'content_filters.assessment_ids' => ['nullable', 'array'],
            'content_filters.assessment_ids.*' => ['integer'],
            'content_filters.year_groups' => ['nullable', 'array'],
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
