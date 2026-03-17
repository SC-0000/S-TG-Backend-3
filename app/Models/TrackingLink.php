<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TrackingLink extends Model
{
    protected $fillable = [
        'organization_id',
        'affiliate_id',
        'code',
        'label',
        'destination_path',
        'type',
        'click_count',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'click_count' => 'integer',
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

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeAffiliate($query)
    {
        return $query->where('type', 'affiliate');
    }

    public function scopeInternal($query)
    {
        return $query->where('type', 'internal');
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    // --- Methods ---

    public static function generateCode(): string
    {
        do {
            $code = strtolower(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function fullUrl(): string
    {
        // Use the backend app URL so the /r/{code} web route is hit directly
        $domain = rtrim((string) config('app.url'), '/');

        if (!$domain || !str_starts_with($domain, 'http')) {
            // Fallback to org public domain if app.url isn't set
            $domain = $this->organization?->public_domain ?? 'https://localhost';
            $domain = rtrim($domain, '/');
            if (!str_starts_with($domain, 'http')) {
                $domain = 'https://' . $domain;
            }
        }

        return $domain . '/r/' . $this->code;
    }

    public function incrementClicks(): void
    {
        $this->increment('click_count');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
