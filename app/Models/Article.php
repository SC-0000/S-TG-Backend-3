<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Article extends Model
{
    protected $fillable = [
        'category',
        'tag',
        'name',
        'title',
        'thumbnail',
        'description',
        'body_type',
        'pdf',
        'article_template',
        'author',
        'author_photo',
        'scheduled_publish_date',
        'titles',
        'bodies',
        'images',
        'key_attributes',
        'sections',
        'organization_id',
    ];

    protected $casts = [
        'titles'                => 'array',
        'bodies'                => 'array',
        'images'                => 'array',
        'key_attributes'        => 'array',
        'sections'             => 'array',
        'scheduled_publish_date'=> 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function setArticleTemplateAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['article_template'] = null;
            return;
        }

        $this->attributes['article_template'] = Str::limit((string) $value, 255, '');
    }
}
