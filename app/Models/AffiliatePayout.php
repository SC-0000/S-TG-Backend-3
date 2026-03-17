<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayout extends Model
{
    protected $fillable = [
        'organization_id',
        'affiliate_id',
        'amount',
        'method',
        'reference',
        'notes',
        'recorded_by',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    // --- Relationships ---

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
