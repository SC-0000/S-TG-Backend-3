<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssessmentSubmissionItem extends Model
{
    protected $fillable = [
        'submission_id',
        'question_id', // Legacy field for backwards compatibility
        'question_type',
        'bank_question_id',
        'inline_question_index', 
        'question_data',
        'answer',
        'is_correct',
        'marks_awarded',
        'time_spent',
        'grading_metadata',
        'detailed_feedback'
    ];
    
    protected $casts = [
        'answer' => 'array',
        'is_correct' => 'boolean',
        'question_data' => 'array',
        'grading_metadata' => 'array'
    ];

    // Relationships
    public function submission(): BelongsTo
    { 
        return $this->belongsTo(AssessmentSubmission::class); 
    }

    public function bankQuestion(): BelongsTo
    { 
        return $this->belongsTo(Question::class, 'bank_question_id'); 
    }

    // Legacy relationship - kept for backwards compatibility
    public function question(): BelongsTo
    { 
        return $this->belongsTo(AssessmentQuestion::class); 
    }

    // Helper methods
    public function isFromQuestionBank(): bool
    {
        return $this->question_type === 'bank';
    }

    public function isInlineQuestion(): bool
    {
        return $this->question_type === 'inline';
    }

    public function getQuestionSource()
    {
        if ($this->isFromQuestionBank()) {
            return $this->bankQuestion;
        }
        
        // For inline questions, return the stored question data
        return $this->question_data;
    }

    public function hasDetailedGrading(): bool
    {
        return !empty($this->grading_metadata);
    }

    public function wasAutoGraded(): bool
    {
        return $this->grading_metadata['auto_graded'] ?? false;
    }

    public function needsManualReview(): bool
    {
        return $this->grading_metadata['requires_human_review'] ?? false;
    }

    public function getConfidenceScore(): ?float
    {
        return $this->grading_metadata['confidence_level'] ?? null;
    }

    public function getRubricScores(): array
    {
        return $this->grading_metadata['rubric_scores'] ?? [];
    }

    /**
     * Get the AI grading flag for this submission item (if any)
     */
    public function aiGradingFlag(): HasOne
    {
        return $this->hasOne(AIGradingFlag::class, 'assessment_submission_item_id');
    }

    /**
     * Check if this submission item has been flagged
     */
    public function isFlagged(): bool
    {
        return $this->aiGradingFlag()->exists();
    }

    /**
     * Check if this submission item has a pending flag
     */
    public function hasPendingFlag(): bool
    {
        return $this->aiGradingFlag()->where('status', 'pending')->exists();
    }

    /**
     * Check if this was AI graded
     */
    public function wasAIGraded(): bool
    {
        return ($this->grading_metadata['grading_method'] ?? '') === 'ai_powered';
    }

    /**
     * Get AI confidence level if available
     */
    public function getAIConfidence(): ?float
    {
        if ($this->wasAIGraded()) {
            return $this->grading_metadata['confidence_level'] ?? null;
        }
        return null;
    }
}
