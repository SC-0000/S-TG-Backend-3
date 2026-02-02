<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIUploadSession extends Model
{
    protected $table = 'ai_upload_sessions';

    protected $fillable = [
        'user_id',
        'organization_id',
        'content_type',
        'status',
        'user_prompt',
        'input_settings',
        'source_data',
        'source_type',
        'quality_threshold',
        'max_iterations',
        'early_stop_patience',
        'current_iteration',
        'current_quality_score',
        'items_generated',
        'items_approved',
        'items_rejected',
        'error_message',
        'validation_errors',
        'started_at',
        'completed_at',
        'processing_time_seconds',
        'metadata',
    ];

    protected $casts = [
        'input_settings' => 'array',
        'source_data' => 'array',
        'validation_errors' => 'array',
        'metadata' => 'array',
        'quality_threshold' => 'decimal:2',
        'current_quality_score' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Content types
    const TYPE_QUESTION = 'question';
    const TYPE_ASSESSMENT = 'assessment';
    const TYPE_COURSE = 'course';
    const TYPE_MODULE = 'module';
    const TYPE_LESSON = 'lesson';
    const TYPE_SLIDE = 'slide';
    const TYPE_ARTICLE = 'article';

    // Statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REVIEW_PENDING = 'review_pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    // Source types
    const SOURCE_PROMPT = 'prompt';
    const SOURCE_TEXT = 'text';
    const SOURCE_FILE = 'file';
    const SOURCE_URL = 'url';

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AIUploadProposal::class, 'session_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AIUploadLog::class, 'session_id');
    }

    // Scopes
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        if ($organizationId) {
            return $query->where('organization_id', $organizationId);
        }
        return $query;
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_REVIEW_PENDING,
        ]);
    }

    public function scopeByContentType($query, string $contentType)
    {
        return $query->where('content_type', $contentType);
    }

    // Helpers
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_REVIEW_PENDING,
            self::STATUS_APPROVED,
        ]);
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_REVIEW_PENDING,
            'completed_at' => now(),
            'processing_time_seconds' => $this->started_at 
                ? now()->diffInSeconds($this->started_at) 
                : null,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function incrementIteration(): void
    {
        $this->increment('current_iteration');
    }

    public function updateQualityScore(float $score): void
    {
        $this->update(['current_quality_score' => $score]);
    }

    public function getSetting(string $key, $default = null)
    {
        return data_get($this->input_settings, $key, $default);
    }

    public function getYearGroup(): ?string
    {
        return $this->getSetting('year_group');
    }

    public function getCategory(): ?string
    {
        return $this->getSetting('category');
    }

    public function getDifficultyRange(): array
    {
        return [
            'min' => $this->getSetting('difficulty_min', 1),
            'max' => $this->getSetting('difficulty_max', 10),
        ];
    }

    public function getItemCount(): int
    {
        return $this->getSetting('item_count', 10);
    }

    /**
     * Get the content type label for display
     */
    public function getContentTypeLabel(): string
    {
        return match($this->content_type) {
            self::TYPE_QUESTION => 'Question',
            self::TYPE_ASSESSMENT => 'Assessment',
            self::TYPE_COURSE => 'Course',
            self::TYPE_MODULE => 'Module',
            self::TYPE_LESSON => 'Lesson',
            self::TYPE_SLIDE => 'Slide',
            self::TYPE_ARTICLE => 'Article',
            default => ucfirst($this->content_type),
        };
    }

    /**
     * Get approved proposals ready for upload
     */
    public function getApprovedProposals()
    {
        return $this->proposals()
            ->where('status', AIUploadProposal::STATUS_APPROVED)
            ->orderBy('order_position')
            ->get();
    }

    /**
     * Get pending proposals for review
     */
    public function getPendingProposals()
    {
        return $this->proposals()
            ->where('status', AIUploadProposal::STATUS_PENDING)
            ->orderBy('order_position')
            ->get();
    }

    /**
     * Log an action for this session
     */
    public function log(string $level, string $action, string $message, array $context = []): AIUploadLog
    {
        return $this->logs()->create([
            'level' => $level,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
