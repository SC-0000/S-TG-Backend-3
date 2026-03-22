<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChangelogEntry extends Model
{
    protected $fillable = [
        'title',
        'summary',
        'body',
        'category',
        'portals',
        'images',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'portals'      => 'array',
        'images'       => 'array',
        'published_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ChangelogRead::class);
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopeForPortal($query, string $portal)
    {
        return $query->whereJsonContains('portals', $portal);
    }
}
