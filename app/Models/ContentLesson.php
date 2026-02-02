<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentLesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'new_lessons';

    protected $fillable = [
        'organization_id',
        'uid',
        'title',
        'year_group',
        'description',
        'order_position',
        'lesson_type',
        'delivery_mode',
        'status',
        'metadata',
        'estimated_minutes',
        'completion_rules',
        'enable_ai_help',
        'enable_tts',
        'journey_category_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'completion_rules' => 'array',
        'estimated_minutes' => 'integer',
        'order_position' => 'integer',
        'enable_ai_help' => 'boolean',
        'enable_tts' => 'boolean',
    ];

    // Relationships
    public function modules()
    {
        return $this->belongsToMany(Module::class, 'content_lesson_module')
            ->withPivot('order_position')
            ->withTimestamps()
            ->orderBy('order_position');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function journeyCategory()
    {
        return $this->belongsTo(JourneyCategory::class);
    }

    public function slides()
    {
        return $this->hasMany(LessonSlide::class, 'lesson_id')->orderBy('order_position');
    }

    public function liveSessions()
    {
        return $this->hasMany(LiveLessonSession::class, 'lesson_id');
    }

    public function assessments()
    {
        return $this->belongsToMany(
            Assessment::class,      // Related model
            'assessment_lesson',    // Pivot table name
            'lesson_id',           // Foreign key for ContentLesson (fixes the content_lesson_id error!)
            'assessment_id'        // Foreign key for Assessment
        )
            ->withPivot('order_position', 'timing')
            ->withTimestamps()
            ->orderBy('pivot_order_position');
    }

    public function progress()
    {
        return $this->hasMany(LessonProgress::class, 'lesson_id');
    }

    public function uploads()
    {
        return $this->hasMany(LessonUpload::class, 'lesson_id');
    }

    // Scopes
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByDeliveryMode($query, $mode)
    {
        return $query->where('delivery_mode', $mode);
    }

    public function scopeForTeacher($query, $teacherId)
    {
        return $query->whereHas('liveSessions', function ($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId);
        });
    }

    // Computed properties
    public function getTotalSlidesAttribute()
    {
        return $this->slides()->count();
    }

    public function getEstimatedDurationAttribute()
    {
        return $this->slides->sum('estimated_seconds') / 60;
    }

    // Helper method to get effective journey category
    public function getEffectiveJourneyCategoryId()
    {
        // Return direct if set
        if ($this->journey_category_id) {
            return $this->journey_category_id;
        }
        
        // Otherwise, get from first linked course via module
        $module = $this->modules()->with('course')->first();
        return $module?->course?->journey_category_id;
    }

    // Boot method for UID
    protected static function booted()
    {
        static::creating(function ($lesson) {
            if (empty($lesson->uid)) {
                $lesson->uid = 'LSN-' . strtoupper(uniqid());
            }
        });
    }
}
