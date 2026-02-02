<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

class ArticleResource extends ApiResource
{
    public function toArray($request): array
    {
        $images = $this->images ?? [];
        $imageUrls = collect($images)->map(fn ($path) => $path ? Storage::url($path) : null)
            ->filter()
            ->values();

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'category' => $this->category,
            'tag' => $this->tag,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'body_type' => $this->body_type,
            'article_template' => $this->article_template,
            'author' => $this->author,
            'scheduled_publish_date' => $this->scheduled_publish_date?->toISOString(),
            'titles' => $this->titles,
            'bodies' => $this->bodies,
            'key_attributes' => $this->key_attributes,
            'sections' => $this->sections,
            'thumbnail' => $this->thumbnail,
            'thumbnail_url' => $this->thumbnail ? Storage::url($this->thumbnail) : null,
            'author_photo' => $this->author_photo,
            'author_photo_url' => $this->author_photo ? Storage::url($this->author_photo) : null,
            'pdf' => $this->pdf,
            'pdf_url' => $this->pdf ? Storage::url($this->pdf) : null,
            'images' => $images,
            'image_urls' => $imageUrls,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
