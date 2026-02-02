<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class MilestoneResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->MilestoneID,
            'organization_id' => $this->organization_id,
            'title' => $this->Title,
            'date' => $this->Date?->toDateString(),
            'description' => $this->Description,
            'image' => $this->Image,
            'image_url' => $this->Image ? Storage::url($this->Image) : null,
            'display_order' => $this->DisplayOrder,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
