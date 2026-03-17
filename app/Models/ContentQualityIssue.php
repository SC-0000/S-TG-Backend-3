<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentQualityIssue extends Model
{
    protected $fillable = [
        'organization_id',
        'run_id',
        'target_type',
        'target_id',
        'issue_type',
        'severity',
        'description',
        'auto_fixable',
        'status',
        'fixed_at',
        'fixed_by',
        'metadata',
    ];

    protected $casts = [
        'auto_fixable' => 'boolean',
        'fixed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    public const STATUS_OPEN = 'open';
    public const STATUS_AUTO_FIXED = 'auto_fixed';
    public const STATUS_MANUALLY_FIXED = 'manually_fixed';
    public const STATUS_DISMISSED = 'dismissed';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackgroundAgentRun::class, 'run_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function markFixed(string $by = 'agent'): void
    {
        $this->update([
            'status' => $by === 'agent' ? self::STATUS_AUTO_FIXED : self::STATUS_MANUALLY_FIXED,
            'fixed_at' => now(),
            'fixed_by' => $by,
        ]);
    }

    public function dismiss(): void
    {
        $this->update(['status' => self::STATUS_DISMISSED]);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeFixable($query)
    {
        return $query->where('auto_fixable', true)->where('status', self::STATUS_OPEN);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }
}
