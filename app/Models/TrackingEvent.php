<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tracking_link_id',
        'session_hash',
        'event',
        'page_path',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    // Funnel stages in order
    public const FUNNEL_STAGES = [
        'click',
        'page_view',
        'form_start',
        'form_submit',
        'verified',
        'approved',
        'first_purchase',
    ];

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }
}
