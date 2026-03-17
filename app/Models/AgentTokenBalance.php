<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTokenBalance extends Model
{
    protected $fillable = [
        'organization_id',
        'balance',
        'lifetime_purchased',
        'lifetime_consumed',
        'low_balance_threshold',
        'auto_topup_enabled',
        'auto_topup_amount',
    ];

    protected $casts = [
        'balance' => 'integer',
        'lifetime_purchased' => 'integer',
        'lifetime_consumed' => 'integer',
        'low_balance_threshold' => 'integer',
        'auto_topup_enabled' => 'boolean',
        'auto_topup_amount' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AgentTokenTransaction::class, 'organization_id', 'organization_id');
    }

    public static function getOrCreate(int $organizationId): self
    {
        return self::firstOrCreate(
            ['organization_id' => $organizationId],
            ['balance' => 0, 'lifetime_purchased' => 0, 'lifetime_consumed' => 0]
        );
    }

    public function hasBalance(int $required = 1): bool
    {
        return $this->balance >= $required;
    }

    public function isLowBalance(): bool
    {
        return $this->balance <= $this->low_balance_threshold;
    }
}
