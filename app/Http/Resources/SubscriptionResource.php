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
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
