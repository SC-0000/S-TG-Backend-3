<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class FaqResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'category' => $this->category,
            'tags' => $this->tags,
            'published' => (bool) $this->published,
            'author_id' => $this->author_id,
            'image' => $this->image,
            'image_url' => $this->image ? Storage::url($this->image) : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
