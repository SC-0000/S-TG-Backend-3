<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterCampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id',
        'recipient_type',
        'recipient_id',
        'email',
        'name',
        'status',
        'sent_at',
        'failed_reason',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NewsletterCampaign::class, 'campaign_id');
    }
}
