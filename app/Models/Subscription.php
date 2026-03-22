<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'features',
        'content_filters',
        'owner_type',
        'organization_id',
        'description',
        'price',
        'currency',
        'billing_interval',
        'is_active',
        'sort_order',
        'stripe_price_id',
    ];

    protected $casts = [
        'features'        => 'array',
        'content_filters' => 'array',
        'price'           => 'decimal:2',
        'is_active'       => 'boolean',
        'sort_order'      => 'integer',
    ];

    // ── Relationships ──

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_subscriptions')
                    ->withPivot(['starts_at', 'ends_at', 'status', 'child_id'])
                    ->wherePivot('status', 'active');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    // ── Scopes ──

    public function scopePlatform($query)
    {
        return $query->where('owner_type', 'platform');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('owner_type', 'organization')
                     ->where('organization_id', $organizationId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Constants ──

    /**
     * Features reserved for platform-owned subscriptions only.
     * Organisation subscriptions must not include these.
     */
    public const PLATFORM_ONLY_FEATURES = [
        'ai_tutoring',
        'ai_analysis',
        'ai',
        'enhanced_reports',
    ];

    // ── Helpers ──

    /**
     * Remove platform-only features from a features array.
     * Used when saving organisation subscriptions to prevent orgs from selling AI.
     */
    public static function stripPlatformFeatures(array $features): array
    {
        return array_diff_key($features, array_flip(self::PLATFORM_ONLY_FEATURES));
    }

    public function isPlatform(): bool
    {
        return $this->owner_type === 'platform';
    }

    public function isOrganization(): bool
    {
        return $this->owner_type === 'organization';
    }

    public function subscriberCount(): int
    {
        return $this->users()->count();
    }
}
