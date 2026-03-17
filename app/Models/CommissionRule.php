<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRule extends Model
{
    // Available trigger events
    public const TRIGGER_SIGNUP_APPROVED = 'signup_approved';
    public const TRIGGER_FIRST_PURCHASE = 'first_purchase';
    public const TRIGGER_SPEND_THRESHOLD = 'spend_threshold';
    public const TRIGGER_EVERY_PURCHASE = 'every_purchase';

    public const TRIGGERS = [
        self::TRIGGER_SIGNUP_APPROVED,
        self::TRIGGER_FIRST_PURCHASE,
        self::TRIGGER_SPEND_THRESHOLD,
        self::TRIGGER_EVERY_PURCHASE,
    ];

    public const TRIGGER_LABELS = [
        self::TRIGGER_SIGNUP_APPROVED => 'Signup Approved',
        self::TRIGGER_FIRST_PURCHASE => 'First Purchase',
        self::TRIGGER_SPEND_THRESHOLD => 'Spend Threshold Reached',
        self::TRIGGER_EVERY_PURCHASE => 'Every Purchase',
    ];

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FLAT = 'flat';

    protected $fillable = [
        'organization_id',
        'name',
        'trigger',
        'commission_type',
        'commission_value',
        'conditions',
        'priority',
        'active',
        'one_time',
    ];

    protected $casts = [
        'conditions' => 'array',
        'commission_value' => 'decimal:2',
        'priority' => 'integer',
        'active' => 'boolean',
        'one_time' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class, 'commission_rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForTrigger($query, string $trigger)
    {
        return $query->where('trigger', $trigger);
    }

    /**
     * Calculate commission amount for a given transaction total.
     */
    public function calculateCommission(?float $transactionTotal = null): float
    {
        if ($this->commission_type === self::TYPE_FLAT) {
            return (float) $this->commission_value;
        }

        if ($this->commission_type === self::TYPE_PERCENTAGE && $transactionTotal !== null) {
            return round(($transactionTotal * (float) $this->commission_value) / 100, 2);
        }

        return 0;
    }

    /**
     * Check if a spend threshold condition is met.
     */
    public function meetsConditions(?float $transactionTotal = null, ?float $lifetimeSpend = null): bool
    {
        $conditions = $this->conditions ?? [];

        if (isset($conditions['min_spend']) && $transactionTotal !== null) {
            if ($transactionTotal < (float) $conditions['min_spend']) {
                return false;
            }
        }

        if (isset($conditions['min_total_spend']) && $lifetimeSpend !== null) {
            if ($lifetimeSpend < (float) $conditions['min_total_spend']) {
                return false;
            }
        }

        return true;
    }
}
