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
        '_type',                   // lesson | assessment | bundle | course | flexible
        'booking_mode',            // fixed_schedule | flexible_booking | self_paced | none
        'service_level',           // basic | full_membership
        'availability',
        'price',
        'instructor_id',
        'teacher_ids',             // JSON array — eligible teachers for flexible booking
        'course_id',               // Link to course for course-type services
        'selection_config',        // JSON configuration for flexible service selections
        'start_datetime',
        'end_datetime',
        'display_until',
        'session_duration_minutes',
        'default_lesson_mode',     // online | in_person | both — controls parent booking mode choice
        'max_participants',
        'quantity',
        'quantity_remaining',
        'quantity_allowed_per_child',
        'credits_per_purchase',    // If set, purchase grants credits instead of direct access
        'restriction_type',        // All | YearGroup | Specific
        'year_groups_allowed',     // JSON array<int>
        'categories',              // JSON array<string>
        'auto_attendance',
        'allow_recurring',
        'cancellation_hours',
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
        'allow_recurring'           => 'boolean',
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
        'teacher_ids'               => 'array',
        'session_duration_minutes'  => 'integer',
        'default_lesson_mode'       => 'string',
        'max_participants'          => 'integer',
        'cancellation_hours'        => 'integer',
        'credits_per_purchase'      => 'integer',
    ];

    /* -----------------------------------------------------------
     |  Relationships
     |----------------------------------------------------------- */
    public function lessons()      { return $this->belongsToMany(Lesson::class, 'lesson_service', 'service_id', 'lesson_id'); }
    public function assessments()  { return $this->belongsToMany(Assessment::class); }
    public function children()     { return $this->belongsToMany(Child::class); }
    public function course()       { return $this->belongsTo(Course::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function instructor()   { return $this->belongsTo(User::class, 'instructor_id'); }
    public function credits()      { return $this->hasMany(ServiceCredit::class); }

    /** Get all lessons directly linked via service_id on live_sessions (booking slots) */
    public function bookingSlots()
    {
        return $this->hasMany(Lesson::class, 'service_id');
    }

    /** Get eligible teachers for this service */
    public function getEligibleTeachers()
    {
        if (!empty($this->teacher_ids)) {
            return User::whereIn('id', $this->teacher_ids)->get();
        }
        if ($this->instructor_id) {
            return User::where('id', $this->instructor_id)->get();
        }
        if ($this->organization_id) {
            return User::whereHas('organizations', function ($q) {
                $q->where('organizations.id', $this->organization_id)
                  ->where('organization_users.role', 'teacher');
            })->get();
        }
        return collect();
    }

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
     |  Booking Mode Helpers
     |----------------------------------------------------------- */

    public function isFlexibleBooking(): bool
    {
        return $this->booking_mode === 'flexible_booking';
    }

    public function isFixedSchedule(): bool
    {
        return $this->booking_mode === 'fixed_schedule';
    }

    public function isSelfPaced(): bool
    {
        return $this->booking_mode === 'self_paced';
    }

    public function isCreditBased(): bool
    {
        return $this->credits_per_purchase !== null && $this->credits_per_purchase > 0;
    }

    public function getRemainingCredits(int $childId): int
    {
        $credit = $this->credits()
            ->where('child_id', $childId)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first();

        return $credit ? ($credit->total_credits - $credit->used_credits) : 0;
    }

    /* -----------------------------------------------------------
     |  Accessors
     |----------------------------------------------------------- */
    protected $appends = ['lessons_count', 'assessments_count', 'display_name'];

    public function getDisplayNameAttribute()      { return $this->service_name; }
    public function getLessonsCountAttribute()     { return $this->lessons()->count(); }
    public function getAssessmentsCountAttribute() { return $this->assessments()->count(); }
}
