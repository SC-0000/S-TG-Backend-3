<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationPlan extends Model
{
    protected $fillable = [
        'organization_id',
        'category',
        'item_key',
        'status',
        'payment_status',
        'billing_invoice_id',
        'price_override',
        'quantity',
        'ai_actions_limit',
        'ai_actions_used',
        'ai_actions_reset_at',
        'started_at',
        'expires_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'price_override' => 'decimal:2',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ai_actions_reset_at' => 'datetime',
        'ai_actions_used' => 'integer',
        'ai_actions_limit' => 'integer',
        'quantity' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the matching platform pricing record (matched on category + item_key).
     */
    public function pricing(): BelongsTo
    {
        return $this->belongsTo(PlatformPricing::class, 'item_key', 'item_key')
            ->where('platform_pricing.category', $this->category);
    }

    /**
     * Manually load the pricing relationship since it uses a composite key.
     */
    public function getPricingAttribute(): ?PlatformPricing
    {
        if (!array_key_exists('pricing_resolved', $this->attributes)) {
            $this->attributes['pricing_resolved'] = true;
            $this->setRelation('pricing', PlatformPricing::where('category', $this->category)
                ->where('item_key', $this->item_key)
                ->first());
        }

        return $this->getRelation('pricing');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get the effective monthly price (price_override or fallback to platform_pricing).
     */
    public function getEffectivePrice(): float
    {
        if ($this->price_override !== null) {
            return (float) $this->price_override;
        }

        $pricing = PlatformPricing::where('category', $this->category)
            ->where('item_key', $this->item_key)
            ->first();

        return $pricing ? (float) $pricing->price_monthly : 0.00;
    }

    /**
     * Check if the plan has remaining AI actions.
     * Returns true if limit is -1 (unlimited) or used < limit.
     */
    public function hasAiActionsRemaining(): bool
    {
        if ($this->ai_actions_limit === null || $this->ai_actions_limit === -1) {
            return true;
        }

        return $this->ai_actions_used < $this->ai_actions_limit;
    }

    /**
     * Increment the AI actions counter by 1.
     */
    public function incrementAiActions(int $count = 1): void
    {
        $this->increment('ai_actions_used', $count);
    }

    /**
     * Reset AI action counter if the reset date has passed.
     */
    public function resetAiActionsIfNeeded(): bool
    {
        if ($this->ai_actions_reset_at && $this->ai_actions_reset_at->isPast()) {
            $this->update([
                'ai_actions_used' => 0,
                'ai_actions_reset_at' => now()->addMonth()->startOfMonth(),
            ]);

            return true;
        }

        return false;
    }
}
