<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIGradingFlag extends Model
{
    use HasFactory;

    protected $table = 'ai_grading_flags';

    protected $fillable = [
        'assessment_submission_item_id',
        'user_id',
        'child_id',
        'flag_reason',
        'student_explanation',
        'status',
        'admin_response',
        'admin_user_id',
        'reviewed_at',
        'original_grade',
        'final_grade',
        'grade_changed',
    ];

    protected $casts = [
        'original_grade' => 'decimal:2',
        'final_grade' => 'decimal:2',
        'grade_changed' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Flag reason options for UI dropdowns
     */
    public static function getFlagReasons(): array
    {
        return [
            'incorrect_grade' => 'AI gave incorrect grade',
            'unfair_scoring' => 'Scoring seems unfair',
            'missed_content' => 'AI missed important content',
            'ai_misunderstood' => 'AI misunderstood my answer',
            'partial_credit_issue' => 'Should have received partial credit',
            'other' => 'Other concern',
        ];
    }

    /**
     * Status options for UI
     */
    public static function getStatusOptions(): array
    {
        return [
            'pending' => 'Pending Review',
            'resolved' => 'Resolved',
            'dismissed' => 'Dismissed',
        ];
    }

    /**
     * Relationship to the submission item being flagged
     */
    public function submissionItem(): BelongsTo
    {
        return $this->belongsTo(AssessmentSubmissionItem::class, 'assessment_submission_item_id');
    }

    /**
     * Relationship to the user (parent) who created the flag
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship to the child whose submission is flagged
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Relationship to the admin who reviewed the flag
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * Scope for pending flags
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for resolved flags
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Check if flag is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if flag is resolved
     */
    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    /**
     * Mark flag as resolved
     */
    public function resolve(User $admin, ?string $response = null, ?float $finalGrade = null): void
    {
        $this->update([
            'status' => 'resolved',
            'admin_user_id' => $admin->id,
            'admin_response' => $response,
            'final_grade' => $finalGrade,
            'grade_changed' => $finalGrade !== null && $finalGrade != $this->original_grade,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Get human-readable flag reason
     */
    public function getReasonLabelAttribute(): string
    {
        return self::getFlagReasons()[$this->flag_reason] ?? $this->flag_reason;
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatusOptions()[$this->status] ?? $this->status;
    }
}
