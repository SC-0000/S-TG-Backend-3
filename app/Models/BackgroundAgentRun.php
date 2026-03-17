<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackgroundAgentRun extends Model
{
    protected $fillable = [
        'organization_id',
        'agent_type',
        'trigger_type',
        'trigger_reference',
        'status',
        'started_at',
        'completed_at',
        'items_processed',
        'items_affected',
        'error_message',
        'summary',
        'platform_tokens_used',
    ];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'items_processed' => 'integer',
        'items_affected' => 'integer',
        'platform_tokens_used' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(BackgroundAgentAction::class, 'run_id');
    }

    public function qualityIssues(): HasMany
    {
        return $this->hasMany(ContentQualityIssue::class, 'run_id');
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $summary = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'summary' => $summary,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $error,
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'completed_at' => now(),
            'error_message' => $reason,
        ]);
    }

    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->completed_at);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForAgent($query, string $agentType)
    {
        return $query->where('agent_type', $agentType);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
