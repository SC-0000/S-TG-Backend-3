<?php

namespace App\Http\Resources;

class SubscriptionResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'features' => $this->features ?? [],
            'content_filters' => $this->content_filters ?? [],
            'owner_type' => $this->owner_type,
            'organization_id' => $this->organization_id,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_interval' => $this->billing_interval,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'stripe_price_id' => $this->stripe_price_id,
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
