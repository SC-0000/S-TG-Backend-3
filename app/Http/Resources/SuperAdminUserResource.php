<?php

namespace App\Http\Resources;

class SuperAdminUserResource extends ApiResource
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
            'current_organization' => $this->whenLoaded('currentOrganization', function () {
                return $this->currentOrganization
                    ? [
                        'id' => $this->currentOrganization->id,
                        'name' => $this->currentOrganization->name,
                    ]
                    : null;
            }),
            'organizations' => $this->whenLoaded('organizations', function () {
                return $this->organizations->map(function ($organization) {
                    return [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'role' => $organization->pivot?->role,
                        'status' => $organization->pivot?->status,
                        'joined_at' => $organization->pivot?->joined_at,
                    ];
                });
            }),
        ];
    }
}
