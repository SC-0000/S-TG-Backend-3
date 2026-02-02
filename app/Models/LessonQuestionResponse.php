<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonQuestionResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_id',
        'lesson_progress_id',
        'slide_id',
        'block_id',
        'question_id',
        'answer_data',
        'is_correct',
        'score_earned',
        'score_possible',
        'attempt_number',
        'time_spent_seconds',
        'feedback',
        'hints_used',
        'answered_at',
    ];

    protected $casts = [
        'answer_data' => 'array',
        'hints_used' => 'array',
        'is_correct' => 'boolean',
        'score_earned' => 'decimal:2',
        'score_possible' => 'decimal:2',
        'attempt_number' => 'integer',
        'time_spent_seconds' => 'integer',
        'answered_at' => 'datetime',
    ];

    // Relationships
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function lessonProgress()
    {
        return $this->belongsTo(LessonProgress::class);
    }

    public function slide()
    {
        return $this->belongsTo(LessonSlide::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    // Scopes
    public function scopeCorrect($query)
    {
        return $query->where('is_correct', true);
    }

    public function scopeIncorrect($query)
    {
        return $query->where('is_correct', false);
    }

    public function scopeForChild($query, $childId)
    {
        return $query->where('child_id', $childId);
    }

    // Helper methods
    public function getScorePercentageAttribute()
    {
        return $this->score_possible > 0 
            ? ($this->score_earned / $this->score_possible) * 100 
            : 0;
    }
}
