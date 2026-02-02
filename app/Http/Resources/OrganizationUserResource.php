<?php

namespace App\Http\Resources;

class OrganizationUserResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'current_organization_id' => $this->current_organization_id,
            'role' => $this->pivot?->role,
            'status' => $this->pivot?->status,
            'joined_at' => $this->pivot?->joined_at,
            'invited_by' => $this->pivot?->invited_by,
        ];
    }
}
