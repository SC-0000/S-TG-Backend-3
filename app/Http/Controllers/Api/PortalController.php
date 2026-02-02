<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Access;
use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\LiveLessonSession;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalController extends ApiController
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $childIds = $this->resolveChildIds($request);
        if ($childIds instanceof JsonResponse) {
            return $childIds;
        }

        $accessRecords = $this->accessForChildren($childIds);
        [$lessonChildMap, $assessmentChildMap] = $this->accessMaps($accessRecords);

        $sessions = $this->liveSessionsForAccess($accessRecords, now(), null, $lessonChildMap)
            ->sortBy('scheduled_start_time')
            ->take(5)
            ->values();

        $assessments = $this->assessmentsForAccess($accessRecords, now(), null, $childIds, $assessmentChildMap)
            ->sortBy('deadline')
            ->take(5)
            ->values();

        $allLessons = $this->liveSessionsForAccess($accessRecords, null, null, $lessonChildMap)
            ->sortBy('scheduled_start_time')
            ->values();

        $allAssessments = $this->assessmentsForAccess($accessRecords, null, null, $childIds, $assessmentChildMap)
            ->sortBy('deadline')
            ->values();

        $progressSummary = $this->progressSummary($childIds);

        $notifications = AppNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'type' => $n->type,
                'status' => $n->status,
                'created_at' => $n->created_at?->toISOString(),
            ]);

        $unreadCount = AppNotification::where('user_id', $user->id)
            ->where('status', 'unread')
            ->count();

        $childrenData = $this->childrenDataFromAccess($childIds, $accessRecords);

        $hasUnpaidDue = false;
        if ($user->billing_customer_id) {
            $billing = app(BillingService::class);
            $customerResp = $billing->getCustomerById($user->billing_customer_id);
            if ($customerResp && isset($customerResp['data']['invoices'])) {
                foreach ($customerResp['data']['invoices'] as $invoice) {
                    if (in_array($invoice['status'], ['open', 'draft'], true)
                        || (isset($invoice['amount_due']) && $invoice['amount_due'] > 0)
                    ) {
                        $hasUnpaidDue = true;
                        break;
                    }
                }
            }
        }

        return $this->success([
            'children' => $user->children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->child_name,
                'year_group' => $c->year_group,
            ]),
            'metrics' => $progressSummary,
            'overall_progress' => $progressSummary['average_completion'] ?? 0,
            'upcoming_sessions' => $sessions,
            'upcoming_assessments' => $assessments,
            'lessons' => $allLessons,
            'assessments' => $allAssessments,
            'notifications' => $notifications,
            'unread_notifications' => $unreadCount,
            'children_data' => $childrenData,
            'has_unpaid_due' => $hasUnpaidDue,
        ]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $childIds = $this->resolveChildIds($request);
        if ($childIds instanceof JsonResponse) {
            return $childIds;
        }

        $accessRecords = $this->accessForChildren($childIds);
        [$lessonChildMap, $assessmentChildMap] = $this->accessMaps($accessRecords);
        $range = $this->parseDateRange($request, now()->subDays(7), null);
        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;
        $sessions = $this->liveSessionsForAccess($accessRecords, $from, $to, $lessonChildMap)->values();
        $calendarSessions = $sessions->map(function ($session) {
            return [
                'id' => $session['id'],
                'title' => $session['title'],
                'start_time' => $session['scheduled_start_time'],
                'end_time' => $session['scheduled_end_time'],
                'status' => $session['status'],
                'child_ids' => $session['child_ids'],
            ];
        })->values();

        $calendarAssessments = $this->assessmentsForAccess($accessRecords, null, null, $childIds, $assessmentChildMap)->values();
        $incompleteContentLessons = $this->incompleteContentLessonsForAccess($accessRecords, $childIds);

        $https = route('calendar.feed', ['token' => encrypt($user->id)]);
        $feedUrl = preg_replace('#^https?://#', 'webcal://', $https);

        return $this->success([
            'sessions' => $sessions,
            'calendar_events' => [
                'live_sessions' => $calendarSessions,
                'assessments' => $calendarAssessments,
            ],
            'incomplete_content_lessons' => $incompleteContentLessons,
            'feed_url' => $feedUrl,
            'children' => $user->children->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->child_name,
                'year_group' => $child->year_group,
            ]),
        ]);
    }

    public function deadlines(Request $request): JsonResponse
    {
        $childIds = $this->resolveChildIds($request);
        if ($childIds instanceof JsonResponse) {
            return $childIds;
        }

        $accessRecords = $this->accessForChildren($childIds);
        [$lessonChildMap, $assessmentChildMap] = $this->accessMaps($accessRecords);
        $windowDays = $request->integer('days', 30);
        $defaultTo = $windowDays > 0 ? now()->addDays($windowDays) : null;

        $range = $this->parseDateRange($request, now(), $defaultTo);
        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;
        $sessions = $this->liveSessionsForAccess($accessRecords, $from, $to, $lessonChildMap)->values();
        $assessments = $this->assessmentsForAccess($accessRecords, $from, $to, $childIds, $assessmentChildMap)->values();

        return $this->success([
            'live_sessions' => $sessions,
            'assessments' => $assessments,
        ]);
    }

    public function calendarFeed(Request $request): JsonResponse
    {
        $childIds = $this->resolveChildIds($request);
        if ($childIds instanceof JsonResponse) {
            return $childIds;
        }

        $accessRecords = $this->accessForChildren($childIds);
        [$lessonChildMap, $assessmentChildMap] = $this->accessMaps($accessRecords);
        $range = $this->parseDateRange($request, now()->subDays(7), null);
        if ($range instanceof JsonResponse) {
            return $range;
        }

        [$from, $to] = $range;
        $sessions = $this->liveSessionsForAccess($accessRecords, $from, $to, $lessonChildMap);
        $assessments = $this->assessmentsForAccess($accessRecords, $from, $to, $childIds, $assessmentChildMap);

        $events = collect();

        foreach ($sessions as $session) {
            $events->push([
                'type' => 'live_session',
                'id' => $session['id'],
                'title' => $session['title'],
                'start' => $session['scheduled_start_time'],
                'end' => $session['scheduled_end_time'],
                'status' => $session['status'],
            ]);
        }

        foreach ($assessments as $assessment) {
            $events->push([
                'type' => 'assessment',
                'id' => $assessment['id'],
                'title' => $assessment['title'],
                'start' => $assessment['availability'],
                'end' => $assessment['deadline'],
                'status' => $assessment['status'],
            ]);
        }

        $events = $events->sortBy('start')->values();

        return $this->success([
            'events' => $events,
        ]);
    }

    public function tracker(Request $request): JsonResponse
    {
        $childIds = $this->resolveChildIds($request);
        if ($childIds instanceof JsonResponse) {
            return $childIds;
        }

        $accessRecords = $this->accessForChildren($childIds);
        $courseIds = $this->courseIdsFromAccess($accessRecords);
        if ($courseIds->isEmpty()) {
            return $this->success(['courses' => []]);
        }

        $courses = Course::with([
            'modules' => function ($q) {
                $q->orderBy('order_position')->with([
                    'lessons' => function ($lq) {
                        $lq->select('new_lessons.id', 'new_lessons.title', 'new_lessons.estimated_minutes', 'new_lessons.status');
                    },
                ]);
            },
        ])->whereIn('id', $courseIds)->get();

        $courseData = $courses->map(function ($course) use ($childIds) {
            $allLessonIds = $course->modules->flatMap(fn ($module) => $module->lessons->pluck('id'));

            $progressRecords = LessonProgress::whereIn('child_id', $childIds)
                ->whereIn('lesson_id', $allLessonIds)
                ->get();

            $totalLessons = $allLessonIds->count();
            $completedLessons = $progressRecords->where('status', 'completed')->count();
            $totalTimeSpent = $progressRecords->sum('time_spent_seconds');
            $avgCompletion = $totalLessons > 0
                ? $progressRecords->avg('completion_percentage')
                : 0;

            $modules = $course->modules->map(function ($module) use ($progressRecords, $childIds) {
                $moduleLessonIds = $module->lessons->pluck('id');
                $moduleProgressData = $progressRecords->whereIn('lesson_id', $moduleLessonIds);

                $total = $moduleLessonIds->count();
                $completed = $moduleProgressData->where('status', 'completed')->count();

                $contentLessons = ContentLesson::whereHas('modules', function ($q) use ($module) {
                    $q->where('modules.id', $module->id);
                })
                    ->with(['progress' => function ($q) use ($childIds) {
                        $q->whereIn('child_id', $childIds);
                    }])
                    ->get()
                    ->map(function ($lesson) {
                        $progress = $lesson->progress->first();
                        return [
                            'id' => $lesson->id,
                            'title' => $lesson->title,
                            'status' => $progress?->status ?? 'not_started',
                            'completion_percentage' => $progress?->completion_percentage ?? 0,
                        ];
                    });

                return [
                    'id' => $module->id,
                    'title' => $module->title,
                    'order' => $module->order_position,
                    'lessons_total' => $total,
                    'lessons_completed' => $completed,
                    'completion_percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
                    'status' => $completed === $total ? 'completed' : ($completed > 0 ? 'in_progress' : 'not_started'),
                    'content_lessons' => $contentLessons,
                ];
            });

            return [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
                'total_modules' => $course->modules->count(),
                'completed_modules' => $modules->where('status', 'completed')->count(),
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'overall_completion' => round($avgCompletion),
                'time_spent_minutes' => round($totalTimeSpent / 60),
                'last_accessed' => $progressRecords->max('last_accessed_at')?->toISOString(),
                'modules' => $modules,
            ];
        });

        return $this->success([
            'courses' => $courseData,
        ]);
    }

    private function resolveChildIds(Request $request): array|JsonResponse
    {
        $user = $request->user();
        $children = $user?->children ?? collect();

        if ($children->isEmpty()) {
            return [];
        }

        if ($request->filled('child_id')) {
            $child = $children->firstWhere('id', $request->integer('child_id'));
            if (!$child) {
                return $this->error('Invalid child selection.', [], 422);
            }
            return [$child->id];
        }

        if ($children->count() > 1 && $request->boolean('require_child', false)) {
            return $this->error('child_id is required when multiple children exist.', [], 422);
        }

        return $children->pluck('id')->all();
    }

    private function accessForChildren(array $childIds)
    {
        if (empty($childIds)) {
            return collect();
        }

        return Access::whereIn('child_id', $childIds)
            ->where('access', true)
            ->where('payment_status', 'paid')
            ->get();
    }

    private function courseIdsFromAccess($accessRecords)
    {
        $courseIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->course_ids) {
                $courseIds = $courseIds->merge($access->course_ids);
            }
        }

        return $courseIds->unique()->filter();
    }

    private function liveSessionsForAccess($accessRecords, ?Carbon $from = null, ?Carbon $to = null, array $lessonChildMap = [])
    {
        $sessionIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->lesson_id) {
                $sessionIds->push($access->lesson_id);
            }
            if (is_array($access->lesson_ids)) {
                $sessionIds = $sessionIds->merge($access->lesson_ids);
            }
            if (isset($access->metadata['live_lesson_session_ids'])) {
                $sessionIds = $sessionIds->merge($access->metadata['live_lesson_session_ids']);
            }
        }

        $sessionIds = $sessionIds->unique()->filter();
        if ($sessionIds->isEmpty()) {
            return collect();
        }

        $query = LiveLessonSession::whereIn('id', $sessionIds);

        if ($from) {
            $query->where('scheduled_start_time', '>=', $from);
        }

        if ($to) {
            $query->where('scheduled_start_time', '<=', $to);
        }

        return $query
            ->orderBy('scheduled_start_time')
            ->get()
            ->map(function ($session) use ($lessonChildMap) {
                $start = $session->scheduled_start_time;
                $end = $session->end_time ?? ($start ? $start->copy()->addHour() : null);

                return [
                    'id' => $session->id,
                    'title' => $session->title ?? $session->lesson?->title,
                    'status' => $session->status,
                    'scheduled_start_time' => $start?->toISOString(),
                    'scheduled_end_time' => $end?->toISOString(),
                    'course_id' => $session->course_id,
                    'lesson_id' => $session->lesson_id,
                    'child_ids' => array_values(array_unique($lessonChildMap[$session->id] ?? [])),
                ];
            });
    }

    private function assessmentsForAccess($accessRecords, ?Carbon $from = null, ?Carbon $to = null, array $childIds = [], array $assessmentChildMap = [])
    {
        $assessmentIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if (is_array($access->assessment_ids)) {
                $assessmentIds = $assessmentIds->merge($access->assessment_ids);
            }
        }

        $assessmentIds = $assessmentIds->unique()->filter();
        if ($assessmentIds->isEmpty()) {
            return collect();
        }

        $query = Assessment::whereIn('id', $assessmentIds);

        if ($from) {
            $query->where('deadline', '>=', $from);
        }

        if ($to) {
            $query->where('deadline', '<=', $to);
        }

        $submissionCounts = collect();
        if (!empty($childIds)) {
            $submissionCounts = AssessmentSubmission::whereIn('assessment_id', $assessmentIds)
                ->whereIn('child_id', $childIds)
                ->selectRaw('assessment_id, count(*) as submissions_count')
                ->groupBy('assessment_id')
                ->pluck('submissions_count', 'assessment_id');
        }

        return $query
            ->orderBy('deadline')
            ->get()
            ->map(function ($assessment) use ($assessmentChildMap, $submissionCounts) {
                return [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'status' => $assessment->status,
                    'availability' => $assessment->availability?->toISOString(),
                    'deadline' => $assessment->deadline?->toISOString(),
                    'time_limit' => $assessment->time_limit,
                    'child_ids' => array_values(array_unique($assessmentChildMap[$assessment->id] ?? [])),
                    'submissions_count' => (int) ($submissionCounts[$assessment->id] ?? 0),
                ];
            });
    }

    private function incompleteContentLessonsForAccess($accessRecords, array $childIds)
    {
        if (empty($childIds)) {
            return collect();
        }

        $contentLessonIds = collect();
        foreach ($accessRecords as $access) {
            if ($access->content_lesson_id) {
                $contentLessonIds->push($access->content_lesson_id);
            }
        }

        $contentLessonIds = $contentLessonIds->unique()->filter();
        if ($contentLessonIds->isEmpty()) {
            return collect();
        }

        $lessons = ContentLesson::whereIn('id', $contentLessonIds)
            ->with(['modules.course'])
            ->get();

        $progressByLesson = LessonProgress::whereIn('lesson_id', $contentLessonIds)
            ->whereIn('child_id', $childIds)
            ->get()
            ->groupBy('lesson_id');

        return $lessons
            ->filter(function ($lesson) use ($childIds, $progressByLesson) {
                $records = $progressByLesson->get($lesson->id, collect());
                if ($records->isEmpty()) {
                    return true;
                }

                foreach ($childIds as $childId) {
                    $record = $records->firstWhere('child_id', $childId);
                    if (! $record || (int) $record->completion_percentage < 100) {
                        return true;
                    }
                }

                return false;
            })
            ->map(function ($lesson) use ($childIds, $progressByLesson) {
                $records = $progressByLesson->get($lesson->id, collect());
                $progressRecord = null;
                foreach ($childIds as $childId) {
                    $progressRecord = $records->firstWhere('child_id', $childId);
                    if ($progressRecord) {
                        break;
                    }
                }

                $module = $lesson->modules->first();
                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description ?? '',
                    'course_name' => $module?->course?->title,
                    'module_name' => $module?->title,
                    'progress' => (int) ($progressRecord?->completion_percentage ?? 0),
                    'estimated_duration' => $lesson->estimated_minutes,
                ];
            })
            ->values();
    }

    private function accessMaps($accessRecords): array
    {
        $lessonMap = [];
        $assessmentMap = [];

        foreach ($accessRecords as $access) {
            $cid = (string) $access->child_id;

            $lessonIds = collect();
            if ($access->lesson_id) {
                $lessonIds->push($access->lesson_id);
            }
            if (is_array($access->lesson_ids)) {
                $lessonIds = $lessonIds->merge($access->lesson_ids);
            }
            if (isset($access->metadata['live_lesson_session_ids'])) {
                $lessonIds = $lessonIds->merge($access->metadata['live_lesson_session_ids']);
            }

            foreach ($lessonIds->filter() as $lid) {
                $lessonMap[$lid][] = $cid;
            }

            $assessmentIds = collect();
            if ($access->assessment_id) {
                $assessmentIds->push($access->assessment_id);
            }
            if (is_array($access->assessment_ids)) {
                $assessmentIds = $assessmentIds->merge($access->assessment_ids);
            }

            foreach ($assessmentIds->filter() as $aid) {
                $assessmentMap[$aid][] = $cid;
            }
        }

        return [$lessonMap, $assessmentMap];
    }

    private function childrenDataFromAccess(array $childIds, $accessRecords): array
    {
        $accessByChild = $accessRecords->groupBy('child_id');
        $childrenData = [];

        foreach ($childIds as $cid) {
            $lessonIds = collect();
            $assessmentIds = collect();

            if (isset($accessByChild[$cid])) {
                foreach ($accessByChild[$cid] as $access) {
                    if ($access->lesson_id) {
                        $lessonIds->push($access->lesson_id);
                    }
                    if (is_array($access->lesson_ids)) {
                        $lessonIds = $lessonIds->merge($access->lesson_ids);
                    }
                    if (isset($access->metadata['live_lesson_session_ids'])) {
                        $lessonIds = $lessonIds->merge($access->metadata['live_lesson_session_ids']);
                    }

                    if ($access->assessment_id) {
                        $assessmentIds->push($access->assessment_id);
                    }
                    if (is_array($access->assessment_ids)) {
                        $assessmentIds = $assessmentIds->merge($access->assessment_ids);
                    }
                }
            }

            $childrenData[] = [
                'child_id' => $cid,
                'lesson_ids' => $lessonIds->unique()->values(),
                'assessment_ids' => $assessmentIds->unique()->values(),
            ];
        }

        return $childrenData;
    }

    private function progressSummary(array $childIds): array
    {
        if (empty($childIds)) {
            return [
                'lessons_completed' => 0,
                'lessons_in_progress' => 0,
                'average_completion' => 0,
            ];
        }

        $progress = LessonProgress::whereIn('child_id', $childIds)->get();

        return [
            'lessons_completed' => $progress->where('status', 'completed')->count(),
            'lessons_in_progress' => $progress->where('status', 'in_progress')->count(),
            'average_completion' => $progress->count() > 0
                ? round($progress->avg('completion_percentage'))
                : 0,
        ];
    }

    private function parseDateRange(Request $request, ?Carbon $defaultFrom, ?Carbon $defaultTo): array|JsonResponse
    {
        $from = $defaultFrom;
        $to = $defaultTo;

        try {
            if ($request->filled('from')) {
                $from = Carbon::parse($request->input('from'));
            }
            if ($request->filled('to')) {
                $to = Carbon::parse($request->input('to'));
            }
        } catch (\Throwable $e) {
            return $this->error('Invalid date range.', [], 422);
        }

        return [$from, $to];
    }
}
