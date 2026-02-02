<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    // Since we're using UUIDs, disable auto-increment and set key type.
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'question',
        'answer',
        'category',
        'tags',
        'published',
        'author_id',
        'image',
    ];

    protected $casts = [
        'tags'      => 'array',
        'published' => 'boolean',
    ];
}