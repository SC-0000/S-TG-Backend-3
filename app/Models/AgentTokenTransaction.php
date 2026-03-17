<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTokenTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'type',
        'amount',
        'balance_after',
        'source_type',
        'source_id',
        'description',
        'metadata',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_CONSUMPTION = 'consumption';
    public const TYPE_REFUND = 'refund';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCredits($query)
    {
        return $query->where('amount', '>', 0);
    }

    public function scopeDebits($query)
    {
        return $query->where('amount', '<', 0);
    }

    public function scopeForPeriod($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
