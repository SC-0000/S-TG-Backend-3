<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveLessonSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'course_id',
        'teacher_id',
        'organization_id',
        'uid',
        'year_group',
        'session_code',
        'status',
        'scheduled_start_time',
        'actual_start_time',
        'end_time',
        'current_slide_id',
        'pacing_mode',
        'navigation_locked', // ✅ Added missing field
        'annotations_locked',
        'audio_enabled',
        'video_enabled',
        'allow_student_questions',
        'whiteboard_enabled',
        'connection_info',
        'session_data',
        'record_session',
        'recording_url',
    ];

    protected $casts = [
        'connection_info' => 'array',
        'session_data' => 'array',
        'navigation_locked' => 'boolean', // ✅ Cast to boolean
        'annotations_locked' => 'boolean',
        'audio_enabled' => 'boolean',
        'video_enabled' => 'boolean',
        'allow_student_questions' => 'boolean',
        'whiteboard_enabled' => 'boolean',
        'record_session' => 'boolean',
        'scheduled_start_time' => 'datetime',
        'actual_start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    // Relationships
    public function lesson()
    {
        return $this->belongsTo(ContentLesson::class, 'lesson_id');
    }

    public function contentLesson()
    {
        return $this->lesson();
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function currentSlide()
    {
        return $this->belongsTo(LessonSlide::class, 'current_slide_id');
    }

    public function participants()
    {
        return $this->hasMany(LiveSessionParticipant::class);
    }

    public function slideInteractions()
    {
        return $this->hasMany(LiveSlideInteraction::class);
    }

    public function lessonProgress()
    {
        return $this->hasMany(LessonProgress::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'live'); // ✅ Fixed: Use 'live' instead of 'active'
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeEnded($query)
    {
        return $query->where('status', 'ended');
    }

    public function scopeForTeacher($query, $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    // Helper methods
    public function startSession()
    {
        $this->status = 'live'; // ✅ Fixed: Use 'live' instead of 'active'
        $this->actual_start_time = now();
        $this->save();
    }

    public function endSession()
    {
        $this->status = 'ended';
        $this->end_time = now();
        $this->save();
    }

    public function moveToSlide($slideId)
    {
        $this->current_slide_id = $slideId;
        $this->save();
    }

    public function getActiveParticipantsCount()
    {
        return $this->participants()
            ->where('status', 'joined')
            ->where('connection_status', 'connected')
            ->count();
    }

    public function getDurationAttribute()
    {
        if ($this->actual_start_time && $this->end_time) {
            return $this->actual_start_time->diffInMinutes($this->end_time);
        }

        if ($this->actual_start_time) {
            return $this->actual_start_time->diffInMinutes(now());
        }

        return 0;
    }

    // Boot method for UID and session code
    protected static function booted()
    {
        static::creating(function ($session) {
            if (empty($session->uid)) {
                $session->uid = 'LSS-' . strtoupper(uniqid());
            }

            if (empty($session->session_code)) {
                $session->session_code = strtoupper(substr(md5(uniqid()), 0, 6));
            }
        });
    }
}
