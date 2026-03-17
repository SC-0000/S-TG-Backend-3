<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Affiliate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'phone',
        'magic_token',
        'magic_token_expires_at',
        'commission_rate',
        'status',
        'meta',
        'last_login_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'magic_token_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'commission_rate' => 'decimal:2',
    ];

    // --- Relationships ---

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function trackingLinks(): HasMany
    {
        return $this->hasMany(TrackingLink::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForOrg($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    // --- Methods ---

    public function effectiveCommissionRate(): float
    {
        if ($this->commission_rate !== null) {
            return (float) $this->commission_rate;
        }

        $orgRate = $this->organization?->getSetting('affiliates.commission_rate');

        return $orgRate !== null ? (float) $orgRate : 0.0;
    }

    public function generateMagicToken(): string
    {
        $raw = Str::random(64);

        $this->update([
            'magic_token' => hash('sha256', $raw),
            'magic_token_expires_at' => now()->addMinutes(15),
        ]);

        return $raw;
    }

    public function totalOwed(): float
    {
        $earned = $this->conversions()
            ->whereIn('status', ['pending', 'approved'])
            ->sum('commission_amount');

        return max(0, (float) $earned);
    }

    public function totalPaid(): float
    {
        return (float) $this->payouts()->sum('amount');
    }

    public function totalEarned(): float
    {
        return (float) $this->conversions()
            ->whereIn('status', ['approved', 'paid'])
            ->sum('commission_amount');
    }
}
