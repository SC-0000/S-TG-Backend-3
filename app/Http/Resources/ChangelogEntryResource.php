<?php

namespace App\Http\Resources;

class ChangelogEntryResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'summary'      => $this->summary,
            'body'         => $this->body,
            'category'     => $this->category,
            'portals'      => $this->portals,
            'images'       => $this->images,
            'published_at' => $this->published_at?->toISOString(),
            'created_by'   => $this->created_by,
            'is_read'      => $this->is_read ?? false,
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
