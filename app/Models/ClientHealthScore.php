<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientHealthScore extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'overall_score',
        'booking_score',
        'payment_score',
        'engagement_score',
        'communication_score',
        'flags',
        'metadata',
        'last_booking_at',
        'last_payment_at',
        'last_message_at',
        'last_login_at',
        'computed_at',
    ];

    protected $casts = [
        'flags'            => 'array',
        'metadata'         => 'array',
        'last_booking_at'  => 'datetime',
        'last_payment_at'  => 'datetime',
        'last_message_at'  => 'datetime',
        'last_login_at'    => 'datetime',
        'computed_at'      => 'datetime',
    ];

    protected $appends = ['risk_level'];

    /* ─── Relationships ─── */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /* ─── Accessors ─── */

    public function getRiskLevelAttribute(): string
    {
        if ($this->overall_score < 25) return 'critical';
        if ($this->overall_score < 50) return 'at_risk';
        return 'healthy';
    }

    /* ─── Scopes ─── */

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeAtRisk($query)
    {
        return $query->where('overall_score', '<', 50);
    }

    public function scopeCritical($query)
    {
        return $query->where('overall_score', '<', 25);
    }

    public function scopeHealthy($query)
    {
        return $query->where('overall_score', '>=', 50);
    }

    public function scopeStale($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('computed_at')
              ->orWhere('computed_at', '<', now()->subDay());
        });
    }

    public function scopeWithFlag($query, string $flag)
    {
        return $query->whereJsonContains('flags', $flag);
    }
}
