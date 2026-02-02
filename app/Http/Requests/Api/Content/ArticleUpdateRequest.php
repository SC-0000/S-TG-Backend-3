<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class ArticleUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'category' => ['sometimes', 'string', 'max:255'],
            'tag' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255', 'unique:articles,name,' . $this->route('article')?->id],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'body_type' => ['sometimes', 'in:pdf,template'],
            'article_template' => ['nullable', 'string', 'max:255'],
            'author' => ['sometimes', 'string', 'max:255'],
            'scheduled_publish_date' => ['sometimes', 'date'],
            'titles' => ['nullable', 'array'],
            'titles.*' => ['string'],
            'bodies' => ['nullable', 'array'],
            'bodies.*' => ['string'],
            'key_attributes' => ['nullable', 'array'],
            'key_attributes.*' => ['string'],
            'sections' => ['sometimes', 'array', 'min:1'],
            'sections.*.header' => ['nullable', 'string', 'max:255'],
            'sections.*.body' => ['required_with:sections', 'string'],
            'thumbnail' => ['nullable', 'image', 'max:2048'],
            'author_photo' => ['nullable', 'image', 'max:2048'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:2048'],
        ];
    }
}
