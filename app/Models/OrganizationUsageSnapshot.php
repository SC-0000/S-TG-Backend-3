<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationUsageSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'period_type',
        'period_date',
        'metrics',
        'calculated_cost',
        'created_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'period_date' => 'date',
        'calculated_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForPeriod($query, string $type)
    {
        return $query->where('period_type', $type);
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('period_date', '>=', now()->subDays($days));
    }
}
