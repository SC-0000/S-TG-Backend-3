<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_id',
        'lesson_id',
        'slide_id',
        'block_id',
        'file_path',
        'file_type',
        'file_size_kb',
        'original_filename',
        'status',
        'score',
        'rubric_data',
        'feedback',
        'feedback_audio',
        'annotations',
        'ai_analysis',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'rubric_data' => 'array',
        'annotations' => 'array',
        'ai_analysis' => 'array',
        'file_size_kb' => 'integer',
        'score' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    // Relationships
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function lesson()
    {
        return $this->belongsTo(ContentLesson::class, 'lesson_id');
    }

    public function slide()
    {
        return $this->belongsTo(LessonSlide::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeGraded($query)
    {
        return $query->where('status', 'graded');
    }

    public function scopeForChild($query, $childId)
    {
        return $query->where('child_id', $childId);
    }

    // Helper methods
    public function markAsReviewed($reviewerId, $score = null, $feedback = null)
    {
        $this->status = 'graded';
        $this->reviewed_by = $reviewerId;
        $this->reviewed_at = now();
        
        if ($score !== null) {
            $this->score = $score;
        }
        
        if ($feedback !== null) {
            $this->feedback = $feedback;
        }
        
        $this->save();
    }

    public function addAIAnalysis($analysis)
    {
        $this->ai_analysis = array_merge($this->ai_analysis ?? [], $analysis);
        $this->save();
    }

    public function getFileUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }
}
