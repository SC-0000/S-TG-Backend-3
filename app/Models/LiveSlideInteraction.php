<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveSlideInteraction extends Model
{
    use HasFactory;

    public $timestamps = false;
    
    protected $fillable = [
        'live_lesson_session_id',
        'slide_id',
        'child_id',
        'interaction_type',
        'data',
        'is_teacher',
        'visible_to_students',
        'created_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_teacher' => 'boolean',
        'visible_to_students' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Relationships
    public function liveSession()
    {
        return $this->belongsTo(LiveLessonSession::class, 'live_lesson_session_id');
    }

    public function slide()
    {
        return $this->belongsTo(LessonSlide::class);
    }

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    // Scopes
    public function scopeForSession($query, $sessionId)
    {
        return $query->where('live_lesson_session_id', $sessionId);
    }

    public function scopeForSlide($query, $slideId)
    {
        return $query->where('slide_id', $slideId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('interaction_type', $type);
    }

    public function scopeFromTeacher($query)
    {
        return $query->where('is_teacher', true);
    }

    public function scopeFromStudents($query)
    {
        return $query->where('is_teacher', false);
    }

    public function scopeVisibleToStudents($query)
    {
        return $query->where('visible_to_students', true);
    }

    // Helper methods
    public static function recordPollResponse($sessionId, $slideId, $childId, $pollData)
    {
        return self::create([
            'live_lesson_session_id' => $sessionId,
            'slide_id' => $slideId,
            'child_id' => $childId,
            'interaction_type' => 'poll_response',
            'data' => $pollData,
            'is_teacher' => false,
            'visible_to_students' => false,
            'created_at' => now(),
        ]);
    }

    public static function recordWhiteboardDraw($sessionId, $slideId, $userId, $drawData, $isTeacher = false)
    {
        return self::create([
            'live_lesson_session_id' => $sessionId,
            'slide_id' => $slideId,
            'child_id' => $isTeacher ? null : $userId,
            'interaction_type' => 'whiteboard_draw',
            'data' => $drawData,
            'is_teacher' => $isTeacher,
            'visible_to_students' => true,
            'created_at' => now(),
        ]);
    }

    public static function recordQuestion($sessionId, $slideId, $childId, $questionData)
    {
        return self::create([
            'live_lesson_session_id' => $sessionId,
            'slide_id' => $slideId,
            'child_id' => $childId,
            'interaction_type' => 'question',
            'data' => $questionData,
            'is_teacher' => false,
            'visible_to_students' => false,
            'created_at' => now(),
        ]);
    }
}
