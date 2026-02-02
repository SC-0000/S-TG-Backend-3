<?php

namespace App\Http\Resources;

class UserResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'current_organization_id' => $this->current_organization_id,
            'email_verified_at' => $this->email_verified_at,
        ];
    }
}
