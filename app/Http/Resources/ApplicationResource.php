<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class ApplicationResource extends ApiResource
{
    public function toArray($request): array
    {
        $childrenData = null;
        if (is_string($this->children_data)) {
            $decoded = json_decode($this->children_data, true);
            $childrenData = is_array($decoded) ? $decoded : null;
        } elseif (is_array($this->children_data)) {
            $childrenData = $this->children_data;
        }

        return [
            'application_id' => $this->application_id,
            'applicant_name' => $this->applicant_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'mobile_number' => $this->mobile_number,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'referral_source' => $this->referral_source,
            'application_status' => $this->application_status,
            'submitted_date' => $this->submitted_date?->toISOString(),
            'application_type' => $this->application_type,
            'signature_path' => $this->signature_path,
            'signature_url' => $this->signature_path ? Storage::url($this->signature_path) : null,
            'admin_feedback' => $this->admin_feedback,
            'reviewer_id' => $this->reviewer_id,
            'verified_at' => $this->verified_at?->toISOString(),
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'children_data' => $childrenData,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user?->id,
                    'name' => $this->user?->name,
                    'email' => $this->user?->email,
                ];
            }),
        ];
    }
}
