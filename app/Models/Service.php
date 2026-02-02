<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    /* -----------------------------------------------------------
     |  Mass-assignable columns
     |----------------------------------------------------------- */
    protected $fillable = [
        'organization_id',
        'is_global',
        'service_name',
        '_type',                   // lesson | assessment | bundle
        'service_level',           // basic | full_membership
        'availability',
        'price',
        'instructor_id',
        'course_id',               // Link to course for course-type services
        'selection_config',        // JSON configuration for flexible service selections
        'start_datetime',
        'end_datetime',
        'display_until',
        'quantity',
        'quantity_remaining',
        'quantity_allowed_per_child',
        'restriction_type',        // All | YearGroup | Specific
        'year_groups_allowed',     // JSON array<int>
        'categories',              // JSON array<string>
        'auto_attendance',
        'description',
        'schedule',                // array or RRULE text
        'media',                   // array of file paths
    ];

    /* -----------------------------------------------------------
     |  Attribute casting
     |----------------------------------------------------------- */
    protected $casts = [
        'availability'              => 'boolean',
        'auto_attendance'           => 'boolean',
        'is_global'                 => 'boolean',
        'price'                     => 'decimal:2',
        'start_datetime'            => 'datetime',
        'end_datetime'              => 'datetime',
        'display_until'             => 'date',
        'media'                     => 'array',
        'schedule'                  => 'array',
        'categories'                => 'array',
        'year_groups_allowed'       => 'array',
        'selection_config'          => 'array',
    ];

    /* -----------------------------------------------------------
     |  Relationships
     |----------------------------------------------------------- */
    public function lessons()     { return $this->belongsToMany(Lesson::class, 'lesson_service', 'service_id', 'lesson_id'); }
    public function assessments() { return $this->belongsToMany(Assessment::class); }
    public function children()    { return $this->belongsToMany(Child::class); }
    public function course()      { return $this->belongsTo(Course::class); }
    public function organization(){ return $this->belongsTo(Organization::class); }
    // public function instructor()  { return $this->belongsTo(User::class, 'instructor_id'); }

    /* -----------------------------------------------------------
     |  Helper Methods
     |----------------------------------------------------------- */

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
    
    /**
     * Check if this service is linked to a course
     */
    public function isCourseService(): bool
    {
        return $this->course_id !== null;
    }

    /**
     * Check if this service uses flexible selection
     */
    public function isFlexibleService(): bool
    {
        return $this->_type === 'flexible';
    }

    /**
     * Get available live sessions with enrollment status
     */
    public function getAvailableLiveSessions()
    {
        return $this->lessons()
            ->with('liveLessonSession.contentLesson:id,title,description')
            ->withPivot('enrollment_limit', 'current_enrollments')
            ->get()
            ->map(function($session) {
                $session->is_available = $session->pivot->enrollment_limit === null 
                    || $session->pivot->current_enrollments < $session->pivot->enrollment_limit;
                $session->spots_remaining = $session->pivot->enrollment_limit 
                    ? ($session->pivot->enrollment_limit - $session->pivot->current_enrollments)
                    : null;
                $session->enrollment_status = $this->getEnrollmentStatus($session);
                return $session;
            });
    }

    /**
     * Get available assessments with enrollment status
     */
    public function getAvailableAssessments()
    {
        return $this->assessments()
            ->withPivot('enrollment_limit', 'current_enrollments')
            ->get()
            ->map(function($assessment) {
                $assessment->is_available = $assessment->pivot->enrollment_limit === null 
                    || $assessment->pivot->current_enrollments < $assessment->pivot->enrollment_limit;
                $assessment->spots_remaining = $assessment->pivot->enrollment_limit 
                    ? ($assessment->pivot->enrollment_limit - $assessment->pivot->current_enrollments)
                    : null;
                $assessment->enrollment_status = $this->getEnrollmentStatus($assessment);
                return $assessment;
            });
    }

    /**
     * Get selection requirements for flexible services
     */
    public function getRequiredSelections(): array
    {
        if (!$this->isFlexibleService()) {
            return ['live_sessions' => 0, 'assessments' => 0];
        }
        
        return [
            'live_sessions' => $this->selection_config['live_sessions']['selection_required'] ?? 0,
            'assessments' => $this->selection_config['assessments']['selection_required'] ?? 0,
        ];
    }

    /**
     * Get enrollment status text
     */
    private function getEnrollmentStatus($item): string
    {
        if ($item->pivot->enrollment_limit === null) {
            return 'unlimited';
        }
        
        $current = $item->pivot->current_enrollments;
        $limit = $item->pivot->enrollment_limit;
        
        if ($current >= $limit) {
            return 'full';
        }
        
        return 'available';
    }

    /**
     * Get display categories for filtering
     * Returns array like: ['lesson'] or ['lesson', 'assessment', 'bundle']
     */
    public function getDisplayCategories(): array
    {
        if ($this->_type !== 'flexible') {
            return [$this->_type];
        }
        
        // For flexible services, determine categories based on content
        $categories = [];
        $hasLessons = $this->lessons()->count() > 0;
        $hasAssessments = $this->assessments()->count() > 0;
        
        if ($hasLessons) $categories[] = 'lesson';
        if ($hasAssessments) $categories[] = 'assessment';
        if ($hasLessons && $hasAssessments) $categories[] = 'bundle';
        
        return $categories;
    }

    /**
     * Get user-friendly display name for flexible services
     */
    public function getFlexibleDisplayName(): string
    {
        if ($this->_type !== 'flexible') {
            return $this->service_name;
        }
        
        $hasLessons = $this->lessons()->count() > 0;
        $hasAssessments = $this->assessments()->count() > 0;
        
        if ($hasLessons && $hasAssessments) {
            return 'Custom Bundle';
        } elseif ($hasLessons) {
            return 'Build Your Lesson Package';
        } elseif ($hasAssessments) {
            return 'Custom Assessment Package';
        }
        
        return $this->service_name;
    }

    /**
     * Get selection description for flexible services
     */
    public function getSelectionDescription(): ?string
    {
        if ($this->_type !== 'flexible' || !$this->selection_config) {
            return null;
        }
        
        // Defensive check: ensure selection_config is actually an array
        if (!is_array($this->selection_config)) {
            return null;
        }
        
        $parts = [];
        
        if (isset($this->selection_config['live_sessions']) && is_array($this->selection_config['live_sessions'])) {
            $required = $this->selection_config['live_sessions']['selection_required'] ?? 0;
            $available = $this->lessons()->count();
            if ($required > 0) {
                $parts[] = "Choose {$required} from {$available} sessions";
            }
        }
        
        if (isset($this->selection_config['assessments']) && is_array($this->selection_config['assessments'])) {
            $required = $this->selection_config['assessments']['selection_required'] ?? 0;
            $available = $this->assessments()->count();
            if ($required > 0) {
                $parts[] = "Choose {$required} from {$available} assessments";
            }
        }
        
        return !empty($parts) ? implode(' • ', $parts) : null;
    }

    /* -----------------------------------------------------------
     |  Accessors
     |----------------------------------------------------------- */
    protected $appends = ['lessons_count', 'assessments_count', 'display_name'];

    public function getDisplayNameAttribute()      { return $this->service_name; }
    public function getLessonsCountAttribute()     { return $this->lessons()->count(); }
    public function getAssessmentsCountAttribute() { return $this->assessments()->count(); }
}
