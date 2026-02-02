<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    protected $fillable = [
        'application_id', 'user_id','child_name', 'age', 'school_name', 'area',
        'year_group', 'learning_difficulties', 'focus_targets', 'other_information',
        'date_of_birth',
  'emergency_contact_name','emergency_contact_phone',
  'academic_info','previous_grades',
  'medical_info','additional_info',
  'organization_id',
    ];

    // public function application()
    // {
    //     // Inverse of the relationship: A child belongs to an application
    //     return $this->belongsTo(Application::class, 'application_id');
    // }
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function accesses()
    {
        return $this->hasMany(Access::class);
    }

    /* -----------------------------------------------------------
     |  Access Checking Methods
     |----------------------------------------------------------- */

    /**
     * Check if child has access to a ContentLesson (self-paced)
     * Uses JSON array-based access system
     */
    public function hasAccessToContentLesson(int $lessonId): bool
    {
        return $this->accesses()
            ->where('access', true)
            ->whereJsonContains('lesson_ids', $lessonId)
            ->exists();
    }

    /**
     * Check if child has access to a LiveLessonSession
     * Uses metadata JSON field
     */
    public function hasAccessToLiveSession(int $sessionId): bool
    {
        return $this->accesses()
            ->where('access', true)
            ->whereJsonContains('metadata->live_session_ids', $sessionId)
            ->exists();
    }

    /**
     * Check if child has access to an Assessment
     * Uses JSON array-based access system
     */
    public function hasAccessToAssessment(int $assessmentId): bool
    {
        return $this->accesses()
            ->where('access', true)
            ->whereJsonContains('assessment_ids', $assessmentId)
            ->exists();
    }

    /**
     * Check if child has access to a Course
     * Uses JSON array-based access system
     */
    public function hasAccessToCourse(int $courseId): bool
    {
        return $this->accesses()
            ->where('access', true)
            ->whereJsonContains('course_ids', $courseId)
            ->exists();
    }

    /**
     * Get all courses the child is enrolled in
     * Uses JSON array-based access system
     */
    public function enrolledCourses()
    {
        // Get all access records for this child
        $accessRecords = $this->accesses()
            ->where('access', true)
            ->whereNotNull('course_ids')
            ->get();

        // Extract all course IDs from JSON arrays
        $courseIds = $accessRecords->flatMap(function($access) {
            return $access->course_ids ?? [];
        })->unique()->toArray();

        // Return query builder for courses
        return Course::whereIn('id', $courseIds)
            ->with('modules.lessons');
    }
    public function services()
{
    return $this->belongsToMany(Service::class);
}
public function permissions()
{
  return $this->hasMany(Permission::class);
}
public function attendances() { return $this->hasMany(Attendance::class); }
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
    public function assessmentSubmissions()
{
    return $this->hasMany(AssessmentSubmission::class, 'child_id');
}
public function chatSessions()
{
    return $this->hasMany(ChatSession::class);
}
public function tutorSession()
{
    return $this->hasOne(ChatSession::class)
                ->where('section', 'tutor');
}

public function organization()
{
    return $this->belongsTo(Organization::class);
}

public function lessonProgress()
{
    return $this->hasMany(LessonProgress::class);
}

/**
 * Get teachers directly assigned to this student
 */
public function assignedTeachers()
{
    return $this->belongsToMany(User::class, 'child_teacher', 'child_id', 'teacher_id')
                ->where('users.role', 'teacher')
                ->withPivot(['assigned_by', 'assigned_at', 'notes', 'organization_id'])
                ->withTimestamps();
}

/* -----------------------------------------------------------
 |  Performance Calculation Methods for Teacher Dashboard
 |----------------------------------------------------------- */

/**
 * Get full name (combining first_name and last_name or using child_name)
 */
public function getFullNameAttribute()
{
    // If first_name and last_name exist, use them
    if (isset($this->attributes['first_name']) && isset($this->attributes['last_name'])) {
        return trim("{$this->attributes['first_name']} {$this->attributes['last_name']}");
    }
    // Otherwise use child_name
    return $this->child_name ?? 'Unknown Student';
}

/**
 * Calculate attendance score (0-100)
 */
public function calculateAttendanceScore()
{
    $totalSessions = $this->attendances()->count();
    if ($totalSessions === 0) return 0;
    
    $attendedSessions = $this->attendances()
        ->where('status', 'present')
        ->count();
    
    return round(($attendedSessions / $totalSessions) * 100);
}

/**
 * Calculate attendance trend (-100 to +100)
 */
public function calculateAttendanceTrend()
{
    // Get last 10 attendances vs previous 10
    $recent = $this->attendances()
        ->latest()
        ->take(10)
        ->get();
    
    $previous = $this->attendances()
        ->latest()
        ->skip(10)
        ->take(10)
        ->get();
    
    if ($recent->isEmpty() || $previous->isEmpty()) return 0;
    
    $recentRate = ($recent->where('status', 'present')->count() / $recent->count()) * 100;
    $previousRate = ($previous->where('status', 'present')->count() / $previous->count()) * 100;
    
    return round($recentRate - $previousRate);
}

/**
 * Calculate assignment score (0-100) based on lesson uploads
 */
public function calculateAssignmentScore()
{
    $submissions = $this->hasMany(LessonUpload::class, 'child_id')->get();
    if ($submissions->isEmpty()) return 0;
    
    $graded = $submissions->whereIn('status', ['graded', 'approved']);
    if ($graded->isEmpty()) return 0;
    
    $totalScore = $graded->sum('score');
    $maxScore = $graded->count() * 100;
    
    return $maxScore > 0 ? round(($totalScore / $maxScore) * 100) : 0;
}

/**
 * Calculate assignment trend
 */
public function calculateAssignmentTrend()
{
    $recent = $this->hasMany(LessonUpload::class, 'child_id')
        ->whereIn('status', ['graded', 'approved'])
        ->latest()
        ->take(5)
        ->get();
    
    $previous = $this->hasMany(LessonUpload::class, 'child_id')
        ->whereIn('status', ['graded', 'approved'])
        ->latest()
        ->skip(5)
        ->take(5)
        ->get();
    
    if ($recent->isEmpty() || $previous->isEmpty()) return 0;
    
    $recentAvg = $recent->avg('score');
    $previousAvg = $previous->avg('score');
    
    return round($recentAvg - $previousAvg);
}

/**
 * Calculate assessment score (0-100)
 */
public function calculateAssessmentScore()
{
    $submissions = $this->assessmentSubmissions()
        ->whereNotNull('marks_obtained')
        ->whereNotNull('total_marks')
        ->where('total_marks', '>', 0)
        ->where('status', 'completed')
        ->get();
    
    if ($submissions->isEmpty()) return 0;
    
    // Calculate average percentage score
    $totalPercentage = $submissions->sum(function($submission) {
        return ($submission->marks_obtained / $submission->total_marks) * 100;
    });
    
    return round($totalPercentage / $submissions->count());
}

/**
 * Calculate assessment trend
 */
public function calculateAssessmentTrend()
{
    $recent = $this->assessmentSubmissions()
        ->whereNotNull('marks_obtained')
        ->whereNotNull('total_marks')
        ->where('total_marks', '>', 0)
        ->where('status', 'completed')
        ->latest()
        ->take(3)
        ->get();
    
    $previous = $this->assessmentSubmissions()
        ->whereNotNull('marks_obtained')
        ->whereNotNull('total_marks')
        ->where('total_marks', '>', 0)
        ->where('status', 'completed')
        ->latest()
        ->skip(3)
        ->take(3)
        ->get();
    
    if ($recent->isEmpty() || $previous->isEmpty()) return 0;
    
    // Calculate average percentage for recent submissions
    $recentAvg = $recent->sum(function($submission) {
        return ($submission->marks_obtained / $submission->total_marks) * 100;
    }) / $recent->count();
    
    // Calculate average percentage for previous submissions
    $previousAvg = $previous->sum(function($submission) {
        return ($submission->marks_obtained / $submission->total_marks) * 100;
    }) / $previous->count();
    
    return round($recentAvg - $previousAvg);
}

/**
 * Calculate participation score (0-100) based on lesson progress
 */
public function calculateParticipationScore()
{
    $progress = $this->lessonProgress()->get();
    if ($progress->isEmpty()) return 0;
    
    $completed = $progress->where('status', 'completed')->count();
    $total = $progress->count();
    
    return round(($completed / $total) * 100);
}

/**
 * Calculate participation trend
 */
public function calculateParticipationTrend()
{
    // Based on recent activity
    $recentWeek = $this->lessonProgress()
        ->where('updated_at', '>=', now()->subWeek())
        ->count();
    
    $previousWeek = $this->lessonProgress()
        ->whereBetween('updated_at', [now()->subWeeks(2), now()->subWeek()])
        ->count();
    
    if ($previousWeek === 0) return $recentWeek > 0 ? 10 : 0;
    
    $percentChange = (($recentWeek - $previousWeek) / $previousWeek) * 100;
    return round(min(max($percentChange, -100), 100));
}

/**
 * Calculate overall performance score (average of all metrics)
 */
public function calculateOverallPerformance()
{
    $scores = [
        $this->calculateAttendanceScore(),
        $this->calculateAssignmentScore(),
        $this->calculateAssessmentScore(),
        $this->calculateParticipationScore(),
    ];
    
    $scores = array_filter($scores, fn($s) => $s > 0);
    
    return !empty($scores) ? round(array_sum($scores) / count($scores)) : 0;
}

/**
 * Identify risk factors for this student
 */
public function identifyRiskFactors()
{
    $factors = [];
    
    if ($this->calculateAttendanceScore() < 70) {
        $factors[] = 'Low attendance';
    }
    
    if ($this->calculateAssignmentScore() < 60) {
        $factors[] = 'Struggling with assignments';
    }
    
    if ($this->calculateAssessmentScore() < 60) {
        $factors[] = 'Low assessment scores';
    }
    
    if ($this->calculateParticipationScore() < 50) {
        $factors[] = 'Low participation';
    }
    
    if ($this->calculateAttendanceTrend() < -10) {
        $factors[] = 'Declining attendance';
    }
    
    return $factors;
}

/**
 * Get recent achievements
 */
public function getRecentAchievements()
{
    $achievements = [];
    
    if ($this->calculateAttendanceScore() >= 95) {
        $achievements[] = 'Perfect Attendance';
    }
    
    if ($this->calculateAssessmentScore() >= 90) {
        $achievements[] = 'High Achiever';
    }
    
    if ($this->calculateAssignmentScore() >= 90) {
        $achievements[] = 'Excellent Work';
    }
    
    if ($this->calculateOverallPerformance() >= 90) {
        $achievements[] = 'Outstanding Student';
    }
    
    if ($this->calculateAttendanceTrend() > 10) {
        $achievements[] = 'Improving Fast';
    }
    
    return $achievements;
}

/**
 * Calculate improvement percentage (based on trends)
 */
public function calculateImprovement()
{
    $trends = [
        $this->calculateAttendanceTrend(),
        $this->calculateAssignmentTrend(),
        $this->calculateAssessmentTrend(),
        $this->calculateParticipationTrend(),
    ];
    
    $trends = array_filter($trends, fn($t) => $t !== 0);
    
    return !empty($trends) ? round(array_sum($trends) / count($trends)) : 0;
}

}
