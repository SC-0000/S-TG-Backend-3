<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Access;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveLessonSession;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Models\LessonQuestionResponse;
use App\Models\SlideInteraction;
use App\Support\ApiPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ChildController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $request->attributes->get('organization_id') ?: $user->current_organization_id;
        if ($user->isSuperAdmin() && $request->filled('organization_id')) {
            $orgId = $request->integer('organization_id');
        }

        $query = Child::query()
            ->with('user:id,name,email')
            ->whereHas('user', fn ($q) => $q->whereNull('deleted_at'))
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('child_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $children = $query->paginate(ApiPagination::perPage($request, 12));
        $data = collect($children->items())->map(function (Child $child) {
            return [
                'id' => $child->id,
                'child_name' => $child->child_name,
                'age' => $child->age,
                'school_name' => $child->school_name,
                'area' => $child->area,
                'year_group' => $child->year_group,
                'organization_id' => $child->organization_id,
                'user' => $child->user ? [
                    'id' => $child->user->id,
                    'name' => $child->user->name,
                    'email' => $child->user->email,
                ] : null,
                'created_at' => $child->created_at?->toISOString(),
            ];
        })->all();

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);
        }

        return $this->paginated($children, $data, [
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id', 'search']),
        ]);
    }

    public function createData(Request $request): JsonResponse
    {
        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        $applications = Application::select('application_id', 'applicant_name')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success([
            'users' => $users,
            'applications' => $applications,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_name' => 'required|string|max:255',
            'age' => 'nullable|integer|min:1',
            'school_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'year_group' => 'nullable|string|max:255',
            'learning_difficulties' => 'nullable|string',
            'focus_targets' => 'nullable|string',
            'other_information' => 'nullable|string',
            'user_id' => 'nullable|exists:users,id',
            'date_of_birth' => 'nullable|date',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'academic_info' => 'nullable|string',
            'previous_grades' => 'nullable|string',
            'medical_info' => 'nullable|string',
            'additional_info' => 'nullable|string',
        ]);

        $application = null;
        if (!empty($data['user_id'])) {
            $application = Application::query()
                ->where('user_id', $data['user_id'])
                ->where('application_status', 'Approved')
                ->latest('submitted_date')
                ->first();

            if (!$application) {
                return $this->error('That guardian has no approved application yet.', [
                    ['field' => 'user_id', 'message' => 'That guardian has no approved application yet.'],
                ], 422);
            }
        }

        if ($application) {
            $data['application_id'] = $application->application_id;
            if (empty($data['organization_id'])) {
                $data['organization_id'] = $application->organization_id;
            }
        }

        $child = Child::create($data);
        $child->load('user:id,name,email');

        return $this->success([
            'child' => [
                'id' => $child->id,
                'child_name' => $child->child_name,
                'age' => $child->age,
                'school_name' => $child->school_name,
                'area' => $child->area,
                'year_group' => $child->year_group,
                'organization_id' => $child->organization_id,
                'user' => $child->user ? [
                    'id' => $child->user->id,
                    'name' => $child->user->name,
                    'email' => $child->user->email,
                ] : null,
            ],
        ], [], 201);
    }

    public function show(Request $request, Child $child): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $child->load('user:id,name,email');

        $accessRecords = Access::query()
            ->where('child_id', $child->id)
            ->where('access', true)
            ->get();

        $serviceIds = $accessRecords
            ->map(fn ($access) => data_get($access->metadata ?? [], 'service_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $serviceNames = Service::query()
            ->whereIn('id', $serviceIds)
            ->pluck('service_name', 'id');

        $resolveService = function (Access $access) use ($serviceNames) {
            $meta = $access->metadata ?? [];
            $serviceId = data_get($meta, 'service_id');
            if (!$serviceId) {
                return null;
            }
            $serviceId = (int) $serviceId;
            return [
                'id' => $serviceId,
                'name' => data_get($meta, 'service_name') ?? $serviceNames->get($serviceId),
            ];
        };

        $mapAccessById = function (Collection $records, string $key): array {
            $map = [];
            foreach ($records as $record) {
                $value = data_get($record, $key);
                if (!$value) {
                    continue;
                }
                $map[(string) $value][] = $record;
            }
            return $map;
        };

        $contentLessonAccess = $mapAccessById($accessRecords, 'content_lesson_id');
        $assessmentAccess = $mapAccessById($accessRecords, 'assessment_id');
        $lessonAccess = $mapAccessById($accessRecords, 'lesson_id');
        $liveSessionAccess = [];
        foreach ($accessRecords as $access) {
            $sessionIds = data_get($access->metadata ?? [], 'live_lesson_session_ids', []);
            if (!is_array($sessionIds)) {
                continue;
            }
            foreach ($sessionIds as $sessionId) {
                $liveSessionAccess[(string) $sessionId][] = $access;
            }
        }

        $contentLessonIds = $accessRecords
            ->pluck('content_lesson_id')
            ->filter()
            ->unique()
            ->values();

        $assessmentIds = $accessRecords
            ->flatMap(function ($access) {
                $ids = [];
                if ($access->assessment_id) {
                    $ids[] = $access->assessment_id;
                }
                if (is_array($access->assessment_ids)) {
                    $ids = array_merge($ids, $access->assessment_ids);
                }
                return $ids;
            })
            ->filter()
            ->unique()
            ->values();

        $courseIds = $accessRecords
            ->flatMap(function ($access) {
                return is_array($access->course_ids) ? $access->course_ids : [];
            })
            ->filter()
            ->unique()
            ->values();

        $lessonIds = $accessRecords
            ->flatMap(function ($access) {
                $ids = [];
                if ($access->lesson_id) {
                    $ids[] = $access->lesson_id;
                }
                if (is_array($access->lesson_ids)) {
                    $ids = array_merge($ids, $access->lesson_ids);
                }
                return $ids;
            })
            ->filter()
            ->unique()
            ->values();

        $contentLessons = ContentLesson::query()
            ->whereIn('id', $contentLessonIds)
            ->when($child->organization_id, fn ($q) => $q->where('organization_id', $child->organization_id))
            ->get();

        $assessments = Assessment::query()
            ->whereIn('id', $assessmentIds)
            ->when($child->organization_id, fn ($q) => $q->where('organization_id', $child->organization_id))
            ->get();

        $courses = Course::query()
            ->withCount(['modules', 'assessments'])
            ->whereIn('id', $courseIds)
            ->when($child->organization_id, fn ($q) => $q->where('organization_id', $child->organization_id))
            ->get();

        $lessons = Lesson::query()
            ->whereIn('id', $lessonIds)
            ->when($child->organization_id, fn ($q) => $q->where('organization_id', $child->organization_id))
            ->get(['id', 'title', 'start_time', 'end_time', 'status', 'year_group', 'service_id', 'live_lesson_session_id']);

        $liveSessionIds = collect()
            ->merge($accessRecords->flatMap(function ($access) {
                $meta = $access->metadata ?? [];
                return data_get($meta, 'live_lesson_session_ids', []);
            }))
            ->merge($lessons->pluck('live_lesson_session_id'))
            ->filter()
            ->unique()
            ->values();

        $liveSessions = LiveLessonSession::query()
            ->whereIn('id', $liveSessionIds)
            ->when($child->organization_id, fn ($q) => $q->where('organization_id', $child->organization_id))
            ->with(['teacher:id,name', 'lesson:id,title'])
            ->get();

        $relatedItems = [
            'content_lessons' => $contentLessons->map(function (ContentLesson $lesson) use ($contentLessonAccess, $resolveService) {
                $access = $contentLessonAccess[(string) $lesson->id][0] ?? null;
                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'status' => $lesson->status,
                    'year_group' => $lesson->year_group,
                    'lesson_type' => $lesson->lesson_type,
                    'delivery_mode' => $lesson->delivery_mode,
                    'estimated_minutes' => $lesson->estimated_minutes,
                    'service' => $access ? $resolveService($access) : null,
                    'link' => "/admin/content-lessons/{$lesson->id}",
                ];
            })->values(),
            'live_lessons' => $lessons->map(function (Lesson $lesson) use ($lessonAccess, $resolveService) {
                $access = $lessonAccess[(string) $lesson->id][0] ?? null;
                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'status' => $lesson->status,
                    'year_group' => $lesson->year_group,
                    'start_time' => $lesson->start_time,
                    'end_time' => $lesson->end_time,
                    'service' => $access ? $resolveService($access) : null,
                    'link' => "/admin/lessons/{$lesson->id}",
                ];
            })->values(),
            'assessments' => $assessments->map(function (Assessment $assessment) use ($assessmentAccess, $resolveService) {
                $access = $assessmentAccess[(string) $assessment->id][0] ?? null;
                return [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'status' => $assessment->status,
                    'year_group' => $assessment->year_group,
                    'availability' => $assessment->availability,
                    'deadline' => $assessment->deadline,
                    'time_limit' => $assessment->time_limit,
                    'service' => $access ? $resolveService($access) : null,
                    'link' => "/admin/assessments/{$assessment->id}",
                ];
            })->values(),
            'courses' => $courses->map(function (Course $course) use ($accessRecords, $resolveService) {
                $access = $accessRecords->first(function ($record) use ($course) {
                    return is_array($record->course_ids) && in_array($course->id, $record->course_ids, true);
                });
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'status' => $course->status,
                    'year_group' => $course->year_group,
                    'modules_count' => $course->modules_count,
                    'assessments_count' => $course->assessments_count,
                    'estimated_duration_minutes' => $course->estimated_duration_minutes,
                    'service' => $access ? $resolveService($access) : null,
                    'link' => "/admin/courses/{$course->id}/edit",
                ];
            })->values(),
            'live_sessions' => $liveSessions->map(function (LiveLessonSession $session) use ($lessons, $lessonAccess, $liveSessionAccess, $resolveService) {
                $lesson = $session->lesson;
                $access = null;
                if ($lesson) {
                    $access = $lessonAccess[(string) $lesson->id][0] ?? null;
                }
                if (!$access) {
                    $access = $liveSessionAccess[(string) $session->id][0] ?? null;
                }
                return [
                    'id' => $session->id,
                    'title' => $lesson?->title ?? $session->uid ?? "Session {$session->id}",
                    'status' => $session->status,
                    'year_group' => $session->year_group,
                    'scheduled_start_time' => $session->scheduled_start_time,
                    'end_time' => $session->end_time,
                    'teacher' => $session->teacher ? [
                        'id' => $session->teacher->id,
                        'name' => $session->teacher->name,
                    ] : null,
                    'service' => $access ? $resolveService($access) : null,
                    'link' => "/admin/live-sessions/{$session->id}/edit",
                ];
            })->values(),
        ];

        $teachers = $child->assignedTeachers()
            ->select('users.id', 'users.name', 'users.email')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'assigned_at' => $teacher->pivot?->assigned_at,
                    'notes' => $teacher->pivot?->notes,
                ];
            })
            ->values();

        $assessmentSubmissions = AssessmentSubmission::query()
            ->where('child_id', $child->id)
            ->with('assessment:id,title')
            ->latest('finished_at')
            ->take(10)
            ->get()
            ->map(function (AssessmentSubmission $submission) {
                return [
                    'id' => $submission->id,
                    'assessment' => $submission->assessment ? [
                        'id' => $submission->assessment->id,
                        'title' => $submission->assessment->title,
                    ] : null,
                    'status' => $submission->status,
                    'marks_obtained' => $submission->marks_obtained,
                    'total_marks' => $submission->total_marks,
                    'started_at' => $submission->started_at,
                    'finished_at' => $submission->finished_at,
                ];
            })
            ->values();

        $lessonProgress = LessonProgress::query()
            ->where('child_id', $child->id)
            ->with('lesson:id,title')
            ->latest('last_accessed_at')
            ->take(10)
            ->get()
            ->map(function (LessonProgress $progress) {
                return [
                    'id' => $progress->id,
                    'lesson' => $progress->lesson ? [
                        'id' => $progress->lesson->id,
                        'title' => $progress->lesson->title,
                    ] : null,
                    'status' => $progress->status,
                    'completion_percentage' => $progress->completion_percentage,
                    'last_accessed_at' => $progress->last_accessed_at,
                    'completed_at' => $progress->completed_at,
                ];
            })
            ->values();

        $attendanceRecords = Attendance::query()
            ->where('child_id', $child->id)
            ->with(['lesson:id,lesson_id,scheduled_start_time,end_time,status', 'lesson.lesson:id,title'])
            ->latest('date')
            ->take(10)
            ->get()
            ->map(function (Attendance $attendance) {
                $session = $attendance->lesson;
                $lesson = $session?->lesson;
                return [
                    'id' => $attendance->id,
                    'date' => $attendance->date,
                    'status' => $attendance->status,
                    'notes' => $attendance->notes,
                    'session' => $session ? [
                        'id' => $session->id,
                        'status' => $session->status,
                        'scheduled_start_time' => $session->scheduled_start_time,
                        'end_time' => $session->end_time,
                        'lesson' => $lesson ? [
                            'id' => $lesson->id,
                            'title' => $lesson->title,
                        ] : null,
                    ] : null,
                ];
            })
            ->values();

        // Get analytics data
        $analytics = [
            'lesson_progress_stats' => $this->getEnhancedLessonProgress($child),
            'question_analytics' => $this->getQuestionAnalytics($child),
            'engagement_analytics' => $this->getEngagementAnalytics($child),
            'attendance_stats' => $this->getAttendanceStats($child),
            'assessment_trend' => $this->getAssessmentTrend($child),
            'retake_analytics' => $this->getRetakeAnalytics($child),
            'activity_heatmap' => $this->getActivityHeatmap($child),
            'struggling_areas' => $this->getStrugglingAreas($child),
            'lesson_completion_stats' => $this->getLessonCompletionStats($child),
            'peak_performance_times' => $this->getPeakPerformanceTimes($child),
            'learning_style_profile' => $this->getLearningStyleProfile($child),
        ];

        return $this->success([
            'child' => $child,
            'user' => $child->user,
            'related_items' => $relatedItems,
            'teachers' => $teachers,
            'assessment_submissions' => $assessmentSubmissions,
            'lesson_progress' => $lessonProgress,
            'attendance' => $attendanceRecords,
            'analytics' => $analytics,
        ]);
    }

    public function update(Request $request, Child $child): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $data = $request->validate([
            'child_name' => 'required|string|max:255',
            'age' => 'nullable|integer|min:1',
            'school_name' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'year_group' => 'nullable|string|max:255',
            'learning_difficulties' => 'nullable|string',
            'focus_targets' => 'nullable|string',
            'other_information' => 'nullable|string',
            'application_id' => 'nullable|exists:applications,application_id',
            'user_id' => 'nullable|exists:users,id',
            'date_of_birth' => 'nullable|date',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'academic_info' => 'nullable|string',
            'previous_grades' => 'nullable|string',
            'medical_info' => 'nullable|string',
            'additional_info' => 'nullable|string',
        ]);

        $child->update($data);
        $child->load('user:id,name,email');

        return $this->success([
            'child' => $child,
        ]);
    }

    public function destroy(Request $request, Child $child): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if ($orgId && (int) $child->organization_id !== (int) $orgId) {
            return $this->error('Not found.', [], 404);
        }

        $child->delete();

        return $this->success(['message' => 'Child deleted successfully.']);
    }

    /**
     * Get enhanced lesson progress analytics
     */
    private function getEnhancedLessonProgress(Child $child): array
    {
        $progress = LessonProgress::where('child_id', $child->id)->get();
        
        if ($progress->isEmpty()) {
            return [
                'total_time_hours' => 0,
                'total_time_minutes' => 0,
                'avg_session_minutes' => 0,
                'question_accuracy' => 0,
                'completion_rate' => 0,
                'abandoned_count' => 0,
                'checks_success_rate' => 0,
                'upload_completion_rate' => 0,
            ];
        }

        $totalSeconds = $progress->sum('time_spent_seconds');
        $totalQuestions = $progress->sum('questions_attempted');
        $correctQuestions = $progress->sum('questions_correct');
        $totalChecks = $progress->sum('checks_total');
        $passedChecks = $progress->sum('checks_passed');
        $totalUploadsRequired = $progress->sum('uploads_required');
        $totalUploadsSubmitted = $progress->sum('uploads_submitted');

        return [
            'total_time_hours' => round($totalSeconds / 3600, 1),
            'total_time_minutes' => round($totalSeconds / 60, 0),
            'avg_session_minutes' => round($progress->avg('time_spent_seconds') / 60, 0),
            'question_accuracy' => $totalQuestions > 0 
                ? round(($correctQuestions / $totalQuestions) * 100, 1) 
                : 0,
            'completion_rate' => round(($progress->where('status', 'completed')->count() / $progress->count()) * 100, 1),
            'abandoned_count' => $progress->where('status', 'abandoned')->count(),
            'checks_success_rate' => $totalChecks > 0 
                ? round(($passedChecks / $totalChecks) * 100, 1) 
                : 0,
            'upload_completion_rate' => $totalUploadsRequired > 0 
                ? round(($totalUploadsSubmitted / $totalUploadsRequired) * 100, 1) 
                : 0,
        ];
    }

    /**
     * Get question-level analytics
     */
    private function getQuestionAnalytics(Child $child): array
    {
        $responses = LessonQuestionResponse::where('child_id', $child->id)->get();
        
        if ($responses->isEmpty()) {
            return [
                'total_questions' => 0,
                'accuracy' => 0,
                'avg_attempts' => 0,
                'avg_time_seconds' => 0,
                'hint_usage_rate' => 0,
            ];
        }

        $correct = $responses->where('is_correct', true)->count();
        $withHints = $responses->whereNotNull('hints_used')->count();

        return [
            'total_questions' => $responses->count(),
            'accuracy' => round(($correct / $responses->count()) * 100, 1),
            'avg_attempts' => round($responses->avg('attempt_number'), 1),
            'avg_time_seconds' => round($responses->avg('time_spent_seconds'), 0),
            'hint_usage_rate' => round(($withHints / $responses->count()) * 100, 1),
        ];
    }

    /**
     * Get engagement analytics
     */
    private function getEngagementAnalytics(Child $child): array
    {
        $interactions = SlideInteraction::where('child_id', $child->id)->get();
        
        if ($interactions->isEmpty()) {
            return [
                'total_interactions' => 0,
                'avg_confidence' => 0,
                'difficult_slides_count' => 0,
                'help_requests_count' => 0,
                'avg_time_per_slide' => 0,
                'engagement_score' => 0,
            ];
        }

        $totalInteractions = $interactions->sum('interactions_count');
        $avgConfidence = $interactions->avg('confidence_rating');
        $difficultCount = $interactions->where('flagged_difficult', true)->count();
        $helpCount = $interactions->whereNotNull('help_requests')->count();
        
        // Calculate engagement score (0-10)
        $engagementScore = min(10, max(0, (
            ($avgConfidence ?? 5) * 0.4 +
            (min(10, $totalInteractions / 10)) * 0.3 +
            ((10 - min(10, $difficultCount)) * 0.3)
        )));

        return [
            'total_interactions' => $totalInteractions,
            'avg_confidence' => round($avgConfidence ?? 0, 1),
            'difficult_slides_count' => $difficultCount,
            'help_requests_count' => $helpCount,
            'avg_time_per_slide' => round($interactions->avg('time_spent_seconds'), 0),
            'engagement_score' => round($engagementScore, 1),
        ];
    }

    /**
     * Get attendance statistics
     */
    private function getAttendanceStats(Child $child): array
    {
        $records = Attendance::where('child_id', $child->id)->get();
        
        if ($records->isEmpty()) {
            return [
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0,
                'rate' => 0,
            ];
        }

        $present = $records->where('status', 'present')->count();
        $total = $records->count();

        return [
            'total' => $total,
            'present' => $present,
            'absent' => $records->where('status', 'absent')->count(),
            'late' => $records->where('status', 'late')->count(),
            'excused' => $records->where('status', 'excused')->count(),
            'rate' => round(($present / $total) * 100, 1),
        ];
    }

    /**
     * Get assessment trend data for charts
     */
    private function getAssessmentTrend(Child $child): array
    {
        $submissions = AssessmentSubmission::where('child_id', $child->id)
            ->with('assessment:id,title')
            ->whereNotNull('finished_at')
            ->orderBy('finished_at')
            ->get();

        return $submissions->map(function ($sub) {
            $score = $sub->total_marks > 0 
                ? round(($sub->marks_obtained / $sub->total_marks) * 100) 
                : 0;
            
            return [
                'date' => $sub->finished_at->format('Y-m-d'),
                'score' => $score,
                'assessment' => $sub->assessment->title ?? 'Assessment',
                'assessment_id' => $sub->assessment_id,
                'marks' => "{$sub->marks_obtained}/{$sub->total_marks}",
            ];
        })->toArray();
    }

    /**
     * Get retake analytics
     */
    private function getRetakeAnalytics(Child $child): array
    {
        $submissions = AssessmentSubmission::where('child_id', $child->id)
            ->with('assessment:id,title')
            ->get()
            ->groupBy('assessment_id');
        
        $retakes = [];
        foreach ($submissions as $assessmentId => $attempts) {
            if ($attempts->count() > 1) {
                $sorted = $attempts->sortBy('retake_number');
                $scores = $sorted->map(function($a) {
                    return $a->total_marks > 0 ? round(($a->marks_obtained / $a->total_marks) * 100) : 0;
                })->toArray();
                
                $retakes[] = [
                    'assessment' => $attempts->first()->assessment->title ?? 'Assessment',
                    'assessment_id' => $assessmentId,
                    'attempts' => $attempts->count(),
                    'scores' => array_values($scores),
                    'improvement' => count($scores) > 1 ? end($scores) - reset($scores) : 0,
                    'best_score' => max($scores),
                    'first_score' => reset($scores),
                    'latest_score' => end($scores),
                ];
            }
        }
        
        return $retakes;
    }

    /**
     * Get activity heatmap data (last 12 weeks)
     */
    private function getActivityHeatmap(Child $child): array
    {
        $activities = LessonProgress::where('child_id', $child->id)
            ->where('last_accessed_at', '>=', now()->subWeeks(12))
            ->select(DB::raw('DATE(last_accessed_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->get();
        
        return $activities->map(function ($a) {
            return [
                'date' => $a->date,
                'count' => $a->count,
            ];
        })->toArray();
    }

    /**
     * Get struggling areas detection
     */
    private function getStrugglingAreas(Child $child): array
    {
        $struggling = [];
        
        // Check 1: Low confidence + high time on slides
        $difficultSlides = SlideInteraction::where('child_id', $child->id)
            ->where(function($q) {
                $q->where('confidence_rating', '<', 5)
                  ->orWhere('flagged_difficult', true)
                  ->orWhereNotNull('help_requests');
            })
            ->with('slide.lesson:id,title')
            ->get();
        
        if ($difficultSlides->isNotEmpty()) {
            $struggling[] = [
                'type' => 'difficult_content',
                'severity' => 'medium',
                'count' => $difficultSlides->count(),
                'message' => "Student flagged {$difficultSlides->count()} slides as difficult",
                'details' => $difficultSlides->take(5)->map(fn($s) => [
                    'lesson' => $s->slide->lesson->title ?? 'Unknown',
                    'confidence' => $s->confidence_rating,
                ])->toArray(),
            ];
        }
        
        // Check 2: Multiple question attempts
        $multipleAttempts = LessonQuestionResponse::where('child_id', $child->id)
            ->where('attempt_number', '>', 2)
            ->with('question')
            ->get();
        
        if ($multipleAttempts->count() > 5) {
            $struggling[] = [
                'type' => 'question_difficulty',
                'severity' => 'high',
                'count' => $multipleAttempts->count(),
                'message' => "{$multipleAttempts->count()} questions required multiple attempts",
            ];
        }
        
        // Check 3: Low assessment scores
        $lowScores = AssessmentSubmission::where('child_id', $child->id)
            ->whereNotNull('total_marks')
            ->where('total_marks', '>', 0)
            ->get()
            ->filter(function($sub) {
                return ($sub->marks_obtained / $sub->total_marks) < 0.6;
            });
        
        if ($lowScores->count() > 2) {
            $struggling[] = [
                'type' => 'low_assessment_scores',
                'severity' => 'high',
                'count' => $lowScores->count(),
                'message' => "{$lowScores->count()} assessments scored below 60%",
            ];
        }
        
        return $struggling;
    }

    /**
     * Get detailed lesson completion statistics
     */
    private function getLessonCompletionStats(Child $child): array
    {
        $progress = LessonProgress::where('child_id', $child->id)->get();
        
        return [
            'total' => $progress->count(),
            'completed' => $progress->where('status', 'completed')->count(),
            'in_progress' => $progress->where('status', 'in_progress')->count(),
            'not_started' => $progress->where('status', 'not_started')->count(),
            'abandoned' => $progress->where('status', 'abandoned')->count(),
            'avg_completion_time_minutes' => round($progress->where('status', 'completed')->avg('time_spent_seconds') / 60, 0),
        ];
    }

    /**
     * Get peak performance times (when student performs best)
     */
    private function getPeakPerformanceTimes(Child $child): array
    {
        $responses = LessonQuestionResponse::where('child_id', $child->id)
            ->whereNotNull('created_at')
            ->get();
        
        if ($responses->isEmpty()) {
            return ['peak_hour' => null, 'performance_by_hour' => []];
        }

        $hourlyPerformance = [];
        foreach (range(0, 23) as $hour) {
            $hourResponses = $responses->filter(fn($r) => $r->created_at->hour == $hour);
            if ($hourResponses->count() > 0) {
                $accuracy = round(($hourResponses->where('is_correct', true)->count() / $hourResponses->count()) * 100, 1);
                $hourlyPerformance[$hour] = [
                    'hour' => $hour,
                    'accuracy' => $accuracy,
                    'total_questions' => $hourResponses->count(),
                ];
            }
        }
        
        $peakHour = collect($hourlyPerformance)->sortByDesc('accuracy')->first();
        
        return [
            'peak_hour' => $peakHour ? $peakHour['hour'] : null,
            'peak_accuracy' => $peakHour ? $peakHour['accuracy'] : 0,
            'performance_by_hour' => array_values($hourlyPerformance),
        ];
    }

    /**
     * Get AI-generated learning style profile
     */
    private function getLearningStyleProfile(Child $child): array
    {
        $interactions = SlideInteraction::where('child_id', $child->id)->get();
        $progress = LessonProgress::where('child_id', $child->id)->get();
        
        if ($interactions->isEmpty() && $progress->isEmpty()) {
            return [
                'primary_style' => 'unknown',
                'confidence' => 0,
                'indicators' => [],
            ];
        }

        // Analyze patterns
        $avgSessionTime = $progress->avg('time_spent_seconds') / 60;
        $avgConfidence = $interactions->avg('confidence_rating') ?? 5;
        $helpRequests = $interactions->whereNotNull('help_requests')->count();
        $totalInteractions = $interactions->sum('interactions_count');
        
        // Simple heuristics for learning style
        $visual = 0;
        $kinesthetic = 0;
        $auditory = 0;
        
        // High interactions = kinesthetic learner (hands-on)
        if ($totalInteractions > 100) $kinesthetic += 3;
        
        // Quick sessions + high confidence = visual learner
        if ($avgSessionTime < 20 && $avgConfidence > 7) $visual += 3;
        
        // Many help requests = auditory learner (prefers guidance)
        if ($helpRequests > 10) $auditory += 2;
        
        // Long sessions = kinesthetic (thorough, hands-on)
        if ($avgSessionTime > 40) $kinesthetic += 2;
        
        $scores = ['visual' => $visual, 'kinesthetic' => $kinesthetic, 'auditory' => $auditory];
        arsort($scores);
        $primaryStyle = array_key_first($scores);
        
        return [
            'primary_style' => $primaryStyle,
            'confidence' => min(100, max($scores) * 20),
            'scores' => $scores,
            'indicators' => [
                'avg_session_minutes' => round($avgSessionTime, 0),
                'avg_confidence' => round($avgConfidence, 1),
                'help_requests' => $helpRequests,
                'total_interactions' => $totalInteractions,
            ],
        ];
    }
}
