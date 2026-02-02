<?php

namespace App\Http\Requests\Api\Content;

use App\Http\Requests\Api\ApiRequest;

class ArticleStoreRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'category' => ['required', 'string', 'max:255'],
            'tag' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255', 'unique:articles,name'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'body_type' => ['required', 'in:pdf,template'],
            'article_template' => ['nullable', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'scheduled_publish_date' => ['required', 'date'],
            'titles' => ['nullable', 'array'],
            'titles.*' => ['string'],
            'bodies' => ['nullable', 'array'],
            'bodies.*' => ['string'],
            'key_attributes' => ['nullable', 'array'],
            'key_attributes.*' => ['string'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.header' => ['nullable', 'string', 'max:255'],
            'sections.*.body' => ['required', 'string'],
            'thumbnail' => ['nullable', 'image', 'max:2048'],
            'author_photo' => ['nullable', 'image', 'max:2048'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:2048'],
        ];
    }
}
