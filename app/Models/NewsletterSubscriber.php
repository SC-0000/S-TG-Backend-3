<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'organization_id',
        'email',
        'name',
        'status',
        'unsubscribe_token',
        'source',
        'tags',
        'subscribed_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
