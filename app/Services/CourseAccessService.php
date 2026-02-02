<?php

namespace App\Services;

use App\Models\Access;
use App\Models\Course;
use Illuminate\Support\Facades\Log;

class CourseAccessService
{
    /**
     * Grant full access to all course content using array-based approach:
     * - All ContentLessons (self-paced)
     * - All LiveLessonSessions (scheduled live classes)
     * - All Assessments (module + course level)
     * - All Modules
     * 
     * Creates ONE access record with all IDs stored in JSON arrays
     *
     * @param int $childId
     * @param int $courseId
     * @param int|null $transactionId
     * @return array Statistics about access granted
     */
    public function grantCourseAccess(int $childId, int $courseId, ?int $transactionId = null,?string $invoiceId = null ): array
    {
        // Load course with all related content
        $course = Course::with([
            'modules.lessons.liveSessions',
            'modules.assessments',
            'assessments'
        ])->findOrFail($courseId);

        // Collect all IDs
        $contentLessonIds = $course->getAllLessonIds();        // ContentLesson IDs
        $liveSessionIds = $course->getAllLiveSessionIds();     // LiveLessonSession IDs
        $assessmentIds = $course->getAllAssessmentIds();
        $moduleIds = $course->getAllModuleIds();

        // Prepare access data
        $accessData = [
            'child_id' => $childId,
            'course_ids' => [$courseId],
            
            // Live sessions (Lesson model) - empty for courses (no direct link yet)
            'lesson_id' => null,
            'lesson_ids' => [],
            
            // ContentLessons - store first ID in content_lesson_id column
            'content_lesson_id' => !empty($contentLessonIds) ? $contentLessonIds[0] : null,
            
            // Assessments
            'assessment_id' => !empty($assessmentIds) ? $assessmentIds[0] : null,
            'assessment_ids' => $assessmentIds,
            
            // Modules
            'module_ids' => $moduleIds,
            
            'transaction_id' => $transactionId,
            'invoice_id' => $invoiceId, 
            'access' => true,
            'purchase_date' => now(),
            'payment_status' => 'paid',
            'metadata' => [
                'course_title' => $course->title,
                'content_lesson_ids' => $contentLessonIds,           // All ContentLesson IDs
                'live_lesson_session_ids' => $liveSessionIds,        // LiveLessonSession IDs
            ],
        ];

        // Log before saving
        Log::info('CourseAccessService: saving access record', [
            'source' => 'CourseAccessService::grantCourseAccess',
            'child_id' => $childId,
            'course_id' => $courseId,
            'transaction_id' => $transactionId,
            'content_lesson_ids' => $contentLessonIds,
            'assessment_ids' => $assessmentIds,
            'data' => $accessData,
        ]);

        // Create single access record with all IDs
        Access::create($accessData);

        Log::info('CourseAccessService: access record saved successfully', [
            'source' => 'CourseAccessService::grantCourseAccess',
            'child_id' => $childId,
            'course_id' => $courseId,
            'transaction_id' => $transactionId,
        ]);

        $stats = [
            'content_lessons' => count($contentLessonIds),
            'live_sessions' => count($liveSessionIds),
            'assessments' => count($assessmentIds),
            'modules' => count($moduleIds),
        ];

        Log::info('Course access granted (array-based)', [
            'child_id' => $childId,
            'course_id' => $courseId,
            'transaction_id' => $transactionId,
            'stats' => $stats,
        ]);
        
        return [
            'total_access_granted' => 1, // Single record created
            'content_lessons' => $stats['content_lessons'],
            'live_sessions' => $stats['live_sessions'],
            'assessments' => $stats['assessments'],
            'modules' => $stats['modules'],
            'breakdown' => $stats,
        ];
    }

    /**
     * Check if a child has access to a specific content lesson
     */
    public function hasLessonAccess(int $childId, int $lessonId): bool
    {
        return Access::forChild($childId)
            ->withLessonAccess($lessonId)
            ->exists();
    }

    /**
     * Check if a child has access to a specific assessment
     */
    public function hasAssessmentAccess(int $childId, int $assessmentId): bool
    {
        return Access::forChild($childId)
            ->withAssessmentAccess($assessmentId)
            ->exists();
    }

    /**
     * Check if a child has access to a specific course
     */
    public function hasCourseAccess(int $childId, int $courseId): bool
    {
        return Access::forChild($childId)
            ->withCourseAccess($courseId)
            ->exists();
    }
}
