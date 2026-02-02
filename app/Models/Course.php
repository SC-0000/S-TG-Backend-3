<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'is_global',
        'journey_category_id',
        'uid',
        'title',
        'year_group',
        'description',
        'thumbnail',
        'cover_image',
        'status',
        'metadata',
        'category',
        'level',
        'estimated_duration_minutes',
        'is_featured',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_featured' => 'boolean',
        'estimated_duration_minutes' => 'integer',
        'order_position' => 'integer',
        'is_global' => 'boolean',
    ];

    // Relationships
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function journeyCategory()
    {
        return $this->belongsTo(JourneyCategory::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function modules()
    {
        return $this->hasMany(Module::class)->orderBy('order_position');
    }

    public function assessments()
    {
        return $this->belongsToMany(Assessment::class, 'assessment_course')
            ->withPivot('timing')
            ->withTimestamps();
    }

    public function accesses()
    {
        return $this->hasMany(Access::class);
    }

    public function service()
    {
        return $this->hasOne(Service::class);
    }

    // Computed properties
    public function getTotalLessonsAttribute()
    {
        return $this->modules->sum(function ($module) {
            return $module->lessons->count();
        });
    }

    public function getTotalDurationAttribute()
    {
        return $this->modules->sum('estimated_duration_minutes');
    }

    // Helper methods to collect all content IDs
    public function getAllLessonIds()
    {
        return $this->modules()
            ->with('lessons')
            ->get()
            ->flatMap(function ($module) {
                return $module->lessons->pluck('id');
            })
            ->unique()
            ->values()
            ->toArray();
    }

    public function getAllAssessmentIds()
    {
        // Get course-level assessments
        $courseAssessments = $this->assessments->pluck('id');
        
        // Get module-level assessments
        $moduleAssessments = $this->modules()
            ->with('assessments')
            ->get()
            ->flatMap(function ($module) {
                return $module->assessments->pluck('id');
            });
        
        return $courseAssessments
            ->merge($moduleAssessments)
            ->unique()
            ->values()
            ->toArray();
    }

    public function getAllModuleIds()
    {
        return $this->modules->pluck('id')->toArray();
    }

    public function getAllLiveSessionIds()
    {
        return $this->modules()
            ->with('lessons.liveSessions')
            ->get()
            ->flatMap(function ($module) {
                return $module->lessons->flatMap(function ($lesson) {
                    return $lesson->liveSessions->pluck('id');
                });
            })
            ->unique()
            ->values()
            ->toArray();
    }

    // Scopes
    public function scopeForTeacher($query, $teacherId)
    {
        return $query->whereHas('modules.lessons.liveSessions', function ($q) use ($teacherId) {
            $q->where('teacher_id', $teacherId);
        });
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeVisibleToOrg($query, ?int $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where('is_global', true);
            if ($organizationId) {
                $q->orWhere('organization_id', $organizationId);
            }
        });
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    // Boot method for UID observer
    protected static function booted()
    {
        static::creating(function ($course) {
            if (empty($course->uid)) {
                $course->uid = 'CRS-' . strtoupper(uniqid());
            }
        });
    }
}
