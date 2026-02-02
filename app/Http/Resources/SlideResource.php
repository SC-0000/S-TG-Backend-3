<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class SlideResource extends ApiResource
{
    public function toArray($request): array
    {
        $images = $this->images ?? [];
        $imageUrls = collect($images)->map(fn ($path) => $path ? Storage::url($path) : null)
            ->filter()
            ->values();

        return [
            'id' => $this->slide_id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'content' => $this->content,
            'template_id' => $this->template_id,
            'order' => $this->order,
            'tags' => $this->tags,
            'schedule' => $this->schedule,
            'status' => $this->status,
            'last_modified' => $this->last_modified?->toISOString(),
            'created_by' => $this->created_by,
            'version' => $this->version,
            'images' => $images,
            'image_urls' => $imageUrls,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
