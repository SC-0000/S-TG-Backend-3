<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'child_id',
        'lesson_id',
        'status',
        'slides_viewed',
        'last_slide_id',
        'completion_percentage',
        'time_spent_seconds',
        'score',
        'checks_passed',
        'checks_total',
        'questions_attempted',
        'questions_correct',
        'questions_score',
        'uploads_submitted',
        'uploads_required',
        'started_at',
        'completed_at',
        'last_accessed_at',
        'live_lesson_session_id',
    ];

    protected $casts = [
        'slides_viewed' => 'array',
        'completion_percentage' => 'integer',
        'time_spent_seconds' => 'integer',
        'score' => 'decimal:2',
        'checks_passed' => 'integer',
        'checks_total' => 'integer',
        'questions_attempted' => 'integer',
        'questions_correct' => 'integer',
        'questions_score' => 'decimal:2',
        'uploads_submitted' => 'integer',
        'uploads_required' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
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

    public function lastSlide()
    {
        return $this->belongsTo(LessonSlide::class, 'last_slide_id');
    }

    public function liveSession()
    {
        return $this->belongsTo(LiveLessonSession::class, 'live_lesson_session_id');
    }

    public function questionResponses()
    {
        return $this->hasMany(LessonQuestionResponse::class);
    }

    public function uploads()
    {
        return $this->hasMany(LessonUpload::class, 'lesson_id', 'lesson_id')
            ->where('child_id', $this->child_id);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeForChild($query, $childId)
    {
        return $query->where('child_id', $childId);
    }

    // Helper methods
    public function markSlideViewed($slideId)
    {
        $viewed = $this->slides_viewed ?? [];
        
        if (!in_array($slideId, $viewed)) {
            $viewed[] = $slideId;
            $this->slides_viewed = $viewed;
            $this->last_slide_id = $slideId;
            $this->last_accessed_at = now();
            
            // Update completion percentage
            $totalSlides = $this->lesson->slides()->count();
            $this->completion_percentage = $totalSlides > 0 
                ? (count($viewed) / $totalSlides) * 100 
                : 0;
            
            $this->save();
        }
    }

    public function updateTimeSpent($seconds)
    {
        $this->time_spent_seconds = $seconds;
        $this->last_accessed_at = now();
        $this->save();
    }

    public function checkCompletion()
    {
        $rules = $this->lesson->completion_rules ?? [];
        
        $completed = true;
        
        if (isset($rules['min_slides_viewed'])) {
            $completed = $completed && count($this->slides_viewed ?? []) >= $rules['min_slides_viewed'];
        }
        
        if (isset($rules['min_score'])) {
            $completed = $completed && ($this->questions_score ?? 0) >= $rules['min_score'];
        }
        
        if (isset($rules['all_uploads_required'])) {
            $completed = $completed && $this->uploads_submitted >= $this->uploads_required;
        }
        
        if ($completed && $this->status !== 'completed') {
            $this->status = 'completed';
            $this->completed_at = now();
            $this->save();
        }
        
        return $completed;
    }

    public function getAccuracyAttribute()
    {
        return $this->questions_attempted > 0 
            ? ($this->questions_correct / $this->questions_attempted) * 100 
            : 0;
    }
}
