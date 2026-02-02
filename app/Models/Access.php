<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Access extends Model
{
    protected $table = 'access';

    protected $fillable = [
        'child_id',
        'lesson_id',
        'content_lesson_id',
        'assessment_id',
        'lesson_ids',
        'course_ids',
        'module_ids',
        'assessment_ids',
        'transaction_id',
        'invoice_id',
        'purchase_date',
        'due_date',
        'access',
        'payment_status',
        'refund_id',
        'metadata',
    ];

    protected $casts = [
        'purchase_date' => 'datetime',
        'due_date' => 'date',
        'access' => 'boolean',
        'metadata' => 'array',
        'lesson_ids' => 'array',
        'course_ids' => 'array',
        'module_ids' => 'array',
        'assessment_ids' => 'array',
    ];

    // Relationships
    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function lesson()
    {
        return $this->belongsTo(LiveLessonSession::class, 'lesson_id');
    }

    public function contentLesson()
    {
        return $this->belongsTo(ContentLesson::class, 'content_lesson_id');
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    // Helper methods for checking access
    public function hasAccessToCourse($courseId)
    {
        return in_array($courseId, $this->course_ids ?? []);
    }

    public function hasAccessToLesson($lessonId)
    {
        return in_array($lessonId, $this->lesson_ids ?? []) 
            || $this->content_lesson_id == $lessonId;
    }

    public function hasAccessToAssessment($assessmentId)
    {
        return in_array($assessmentId, $this->assessment_ids ?? [])
            || $this->assessment_id == $assessmentId;
    }

    public function hasAccessToModule($moduleId)
    {
        return in_array($moduleId, $this->module_ids ?? []);
    }

    // Scopes for querying access
    public function scopeForChild($query, $childId)
    {
        return $query->where('child_id', $childId)->where('access', true);
    }

    public function scopeWithCourseAccess($query, $courseId)
    {
        return $query->whereJsonContains('course_ids', $courseId);
    }

    public function scopeWithLessonAccess($query, $lessonId)
    {
        return $query->where(function($q) use ($lessonId) {
            $q->whereJsonContains('lesson_ids', $lessonId)
              ->orWhere('content_lesson_id', $lessonId);
        });
    }

    public function scopeWithAssessmentAccess($query, $assessmentId)
    {
        return $query->where(function($q) use ($assessmentId) {
            $q->whereJsonContains('assessment_ids', $assessmentId)
              ->orWhere('assessment_id', $assessmentId);
        });
    }
}
