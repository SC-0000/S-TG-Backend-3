<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_COUPON       = 'coupon_code';
    const TYPE_AUTO         = 'auto_discount';
    const TYPE_BULK         = 'bulk_discount';

    const DISCOUNT_PERCENTAGE = 'percentage';
    const DISCOUNT_FIXED      = 'fixed_amount';

    const APPLY_ALL      = 'all';
    const APPLY_SERVICES = 'services';
    const APPLY_PRODUCTS = 'products';
    const APPLY_SPECIFIC = 'specific';

    protected $fillable = [
        'organization_id', 'code', 'name', 'description',
        'type', 'discount_type', 'discount_value',
        'min_purchase_amount', 'max_discount_amount',
        'usage_limit', 'usage_limit_per_user', 'used_count',
        'starts_at', 'ends_at', 'is_active',
        'applicable_to', 'applicable_item_ids', 'applicable_item_type',
        'created_by',
    ];

    protected $casts = [
        'applicable_item_ids'  => 'array',
        'starts_at'            => 'datetime',
        'ends_at'              => 'datetime',
        'is_active'            => 'boolean',
        'discount_value'       => 'float',
        'min_purchase_amount'  => 'float',
        'max_discount_amount'  => 'float',
        'usage_limit'          => 'integer',
        'usage_limit_per_user' => 'integer',
        'used_count'           => 'integer',
    ];

    /* ─── Relationships ─── */

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usages()
    {
        return $this->hasMany(PromotionUsage::class);
    }

    /* ─── Scopes ─── */

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function scopeForOrg($query, $orgId)
    {
        return $query->where(function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)->orWhereNull('organization_id');
        });
    }

    public function scopeCoupons($query)
    {
        return $query->where('type', self::TYPE_COUPON);
    }

    public function scopeAutoDiscounts($query)
    {
        return $query->where('type', self::TYPE_AUTO);
    }

    /* ─── Helpers ─── */

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;
        if (!$this->hasUsagesRemaining()) return false;
        return true;
    }

    public function hasUsagesRemaining(): bool
    {
        if ($this->usage_limit === null) return true;
        return $this->used_count < $this->usage_limit;
    }

    public function hasUserUsagesRemaining(int $userId): bool
    {
        if ($this->usage_limit_per_user === null) return true;
        $count = $this->usages()->where('user_id', $userId)->count();
        return $count < $this->usage_limit_per_user;
    }

    public function isApplicableTo(string $itemType, int $itemId): bool
    {
        if ($this->applicable_to === self::APPLY_ALL) return true;

        if ($this->applicable_to === self::APPLY_SERVICES && $itemType === 'service') return true;
        if ($this->applicable_to === self::APPLY_PRODUCTS && $itemType === 'product') return true;

        if ($this->applicable_to === self::APPLY_SPECIFIC) {
            return $this->applicable_item_type === $itemType
                && in_array($itemId, $this->applicable_item_ids ?? []);
        }

        return false;
    }

    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) return 'Inactive';
        if ($this->ends_at && $this->ends_at->isPast()) return 'Expired';
        if ($this->starts_at && $this->starts_at->isFuture()) return 'Scheduled';
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) return 'Exhausted';
        return 'Active';
    }
}
