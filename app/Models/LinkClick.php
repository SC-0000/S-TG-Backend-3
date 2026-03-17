<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tracking_link_id',
        'ip_hash',
        'user_agent',
        'referer_url',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }
}
