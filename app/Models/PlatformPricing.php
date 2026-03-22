<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformPricing extends Model
{
    protected $table = 'platform_pricing';

    protected $fillable = [
        'category',
        'item_key',
        'label',
        'description',
        'price_monthly',
        'price_yearly',
        'is_active',
        'tier',
        'metadata',
        'sort_order',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeForTier($query, string $tier)
    {
        return $query->where('tier', $tier);
    }

    /**
     * Get the effective price for an organization.
     * Checks if the org has a price_override on its plan, otherwise returns this pricing.
     */
    public function getEffectivePrice(Organization $org): float
    {
        $plan = OrganizationPlan::where('organization_id', $org->id)
            ->where('category', $this->category)
            ->where('item_key', $this->item_key)
            ->where('status', 'active')
            ->first();

        if ($plan && $plan->price_override !== null) {
            return (float) $plan->price_override;
        }

        return (float) $this->price_monthly;
    }
}
