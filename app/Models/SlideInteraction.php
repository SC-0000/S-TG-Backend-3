<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlideInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_id',
        'slide_id',
        'lesson_progress_id',
        'time_spent_seconds',
        'interactions_count',
        'help_requests',
        'confidence_rating',
        'flagged_difficult',
        'block_interactions',
        'first_viewed_at',
        'last_viewed_at',
    ];

    protected $casts = [
        'help_requests' => 'array',
        'block_interactions' => 'array',
        'time_spent_seconds' => 'integer',
        'interactions_count' => 'integer',
        'confidence_rating' => 'integer',
        'flagged_difficult' => 'boolean',
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    // Relationships
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function slide()
    {
        return $this->belongsTo(LessonSlide::class);
    }

    public function lessonProgress()
    {
        return $this->belongsTo(LessonProgress::class);
    }

    // Scopes
    public function scopeDifficult($query)
    {
        return $query->where('flagged_difficult', true);
    }

    public function scopeForChild($query, $childId)
    {
        return $query->where('child_id', $childId);
    }

    // Helper methods
    public function incrementInteractions()
    {
        $this->interactions_count++;
        $this->last_viewed_at = now();
        $this->save();
    }

    public function addHelpRequest($type, $data = [])
    {
        $requests = $this->help_requests ?? [];
        $requests[] = [
            'type' => $type,
            'data' => $data,
            'timestamp' => now()->toISOString(),
        ];
        
        $this->help_requests = $requests;
        $this->save();
    }

    public function setConfidenceRating($rating)
    {
        $this->confidence_rating = max(1, min(5, $rating));
        $this->save();
    }
}
