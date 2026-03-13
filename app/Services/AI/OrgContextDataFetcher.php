<?php

namespace App\Services\AI;

use App\Models\Access;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Module;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveLessonSession;
use App\Models\LiveSessionParticipant;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OrgContextDataFetcher - Stage 2 for admin/teacher org-level AI.
 */
class OrgContextDataFetcher
{
    public function fetch(User $user, array $requirements): array
    {
        $context = [];
        $filters = $requirements['filters'] ?? [];
        $required = $requirements['required_data'] ?? ['none'];

        foreach ($required as $source) {
            if ($source === 'none') {
                continue;
            }

            try {
                $context[$source] = $this->fetchDataSource($user, $source, $filters);
            } catch (\Exception $e) {
                Log::error("Failed to fetch org data source: {$source}", [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $context[$source] = ['error' => 'Failed to retrieve data'];
            }
        }

        return $context;
    }

    protected function fetchDataSource(User $user, string $source, array $filters): array
    {
        return match ($source) {
            'org_overview' => $this->fetchOrgOverview($user),
            'child_summary' => $this->fetchChildSummary($user, $filters),
            'child_access' => $this->fetchChildAccess($user, $filters),
            'child_progress' => $this->fetchChildProgress($user, $filters),
            'child_submissions' => $this->fetchChildSubmissions($user, $filters),
            'assessment_summary' => $this->fetchAssessmentSummary($user),
            'assessment_questions' => $this->fetchAssessmentQuestions($user, $filters),
            'assessment_access_stats' => $this->fetchAssessmentAccessStats($user, $filters),
            'assessment_access_students' => $this->fetchAssessmentAccessStudents($user, $filters),
            'assessments_catalog' => $this->fetchAssessmentsCatalog($user, $filters),
            'content_lessons_catalog' => $this->fetchContentLessonsCatalog($user, $filters),
            'live_sessions_catalog' => $this->fetchLiveSessionsCatalog($user, $filters),
            'courses_catalog' => $this->fetchCoursesCatalog($user, $filters),
            'services_catalog' => $this->fetchServicesCatalog($user, $filters),
            'teacher_load' => $this->fetchTeacherLoad($user, $filters),
            'teacher_schedule' => $this->fetchTeacherSchedule($user, $filters),
            'journey_progress' => $this->fetchJourneyProgress($user, $filters),
            'revenue_summary' => $this->fetchRevenueSummary($user, $filters),
            default => [],
        };
    }

    protected function orgId(User $user): ?int
    {
        return $user->current_organization_id;
    }

    protected function isTeacher(User $user): bool
    {
        return $user->role === User::ROLE_TEACHER;
    }

    protected function teacherVisibleChildIds(User $user): array
    {
        $assignedIds = DB::table('child_teacher')
            ->where('teacher_id', $user->id)
            ->pluck('child_id')
            ->toArray();

        $sessionIds = LiveLessonSession::where('teacher_id', $user->id)
            ->pluck('id')
            ->toArray();

        $sessionChildIds = LiveSessionParticipant::whereIn('live_lesson_session_id', $sessionIds)
            ->pluck('child_id')
            ->toArray();

        return array_values(array_unique(array_merge($assignedIds, $sessionChildIds)));
    }

    protected function fetchOrgOverview(User $user): array
    {
        $orgId = $this->orgId($user);

        if (!$orgId) {
            return ['message' => 'No organization context set'];
        }

        return [
            'children' => Child::where('organization_id', $orgId)->count(),
            'teachers' => User::where('current_organization_id', $orgId)->where('role', User::ROLE_TEACHER)->count(),
            'services' => Service::where('organization_id', $orgId)->count(),
            'courses' => Course::where('organization_id', $orgId)->count(),
            'content_lessons' => ContentLesson::where('organization_id', $orgId)->count(),
            'live_sessions' => Lesson::where('organization_id', $orgId)->count(),
            'assessments' => Assessment::where('organization_id', $orgId)
                ->orWhere('is_global', true)
                ->count(),
            'question_bank' => \App\Models\Question::where('organization_id', $orgId)->count(),
        ];
    }

    protected function fetchAssessmentSummary(User $user): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        return [
            'assessments_total' => Assessment::where('organization_id', $orgId)
                ->orWhere('is_global', true)
                ->count(),
            'question_bank_total' => \App\Models\Question::where('organization_id', $orgId)->count(),
        ];
    }

    protected function fetchAssessmentQuestions(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];
        if (empty($filters['assessment_id'])) {
            return ['message' => 'Specify an assessment_id to list its questions'];
        }

        $assessment = Assessment::where('id', $filters['assessment_id'])
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)->orWhere('is_global', true);
            })
            ->first();

        if (!$assessment) {
            return ['message' => 'Assessment not found in this organization'];
        }

        $inline = AssessmentQuestion::where('assessment_id', $assessment->id)
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'type' => $q->type ?? 'inline',
                'title' => $q->question_text,
                'marks' => $q->marks,
            ]);

        $bank = $assessment->bankQuestions()
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'type' => 'bank',
                'title' => $q->title,
                'marks' => $q->pivot?->custom_points ?? $q->marks,
            ]);

        $questions = $inline->concat($bank)->values();

        return [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
            ],
            'question_count' => $questions->count(),
            'questions' => $questions->take(20)->toArray(),
        ];
    }

    protected function fetchAssessmentsCatalog(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $limit = $filters['limit'] ?? 20;
        $assessments = Assessment::where(function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)->orWhere('is_global', true);
        })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $assessmentIds = $assessments->pluck('id');
        $inlineCounts = AssessmentQuestion::whereIn('assessment_id', $assessmentIds)
            ->select('assessment_id', DB::raw('count(*) as total'))
            ->groupBy('assessment_id')
            ->pluck('total', 'assessment_id');

        $bankCounts = DB::table('assessment_question_bank')
            ->whereIn('assessment_id', $assessmentIds)
            ->select('assessment_id', DB::raw('count(*) as total'))
            ->groupBy('assessment_id')
            ->pluck('total', 'assessment_id');

        return $assessments->map(function ($assessment) use ($inlineCounts, $bankCounts) {
            $inline = (int) ($inlineCounts[$assessment->id] ?? 0);
            $bank = (int) ($bankCounts[$assessment->id] ?? 0);
            return [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'status' => $assessment->status,
                'question_count' => $inline + $bank,
            ];
        })->toArray();
    }

    protected function fetchAssessmentAccessStats(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $assessment = null;
        if (!empty($filters['assessment_id'])) {
            $assessment = Assessment::where('id', $filters['assessment_id'])
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhere('is_global', true);
                })
                ->first();
        } elseif (!empty($filters['assessment_title'])) {
            $assessment = Assessment::where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhere('is_global', true);
                })
                ->where('title', 'like', '%' . $filters['assessment_title'] . '%')
                ->first();
        }

        if (!$assessment) {
            return ['message' => 'Specify an assessment ID or title to count access'];
        }

        $count = Access::where('access', true)
            ->where(function ($q) use ($assessment) {
                $q->where('assessment_id', $assessment->id)
                  ->orWhereJsonContains('assessment_ids', $assessment->id);
            })
            ->distinct('child_id')
            ->count('child_id');

        return [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
            ],
            'students_with_access' => $count,
        ];
    }

    protected function fetchAssessmentAccessStudents(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $assessment = null;
        if (!empty($filters['assessment_id'])) {
            $assessment = Assessment::where('id', $filters['assessment_id'])
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhere('is_global', true);
                })
                ->first();
        } elseif (!empty($filters['assessment_title'])) {
            $assessment = Assessment::where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhere('is_global', true);
                })
                ->where('title', 'like', '%' . $filters['assessment_title'] . '%')
                ->first();
        }

        if (!$assessment) {
            return ['message' => 'Specify an assessment ID or title to list student names'];
        }

        $childIds = Access::where('access', true)
            ->where(function ($q) use ($assessment) {
                $q->where('assessment_id', $assessment->id)
                  ->orWhereJsonContains('assessment_ids', $assessment->id);
            })
            ->pluck('child_id')
            ->unique()
            ->values();

        $children = Child::whereIn('id', $childIds)
            ->where('organization_id', $orgId)
            ->get(['id', 'child_name']);

        return [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
            ],
            'students' => $children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->child_name,
            ])->toArray(),
        ];
    }

    protected function fetchContentLessonsCatalog(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $limit = $filters['limit'] ?? 20;
        return ContentLesson::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'title', 'year_group', 'status'])
            ->toArray();
    }

    protected function fetchLiveSessionsCatalog(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $limit = $filters['limit'] ?? 20;
        $query = Lesson::where('organization_id', $orgId);
        if (!empty($filters['time_range'])) {
            $query = $this->applyTimeFilter($query, $filters['time_range'], 'start_time');
        }

        return $query->orderByDesc('start_time')
            ->limit($limit)
            ->get(['id', 'title', 'status', 'start_time'])
            ->map(fn ($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'status' => $lesson->status,
                'start_time' => $lesson->start_time?->toISOString(),
            ])
            ->toArray();
    }

    protected function fetchCoursesCatalog(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $limit = $filters['limit'] ?? 20;
        $courses = Course::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'title']);

        $courseIds = $courses->pluck('id');
        $moduleCounts = Module::whereIn('course_id', $courseIds)
            ->select('course_id', DB::raw('count(*) as total'))
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $lessonCounts = DB::table('content_lesson_module')
            ->join('modules', 'modules.id', '=', 'content_lesson_module.module_id')
            ->whereIn('modules.course_id', $courseIds)
            ->select('modules.course_id as course_id', DB::raw('count(distinct content_lesson_module.content_lesson_id) as total'))
            ->groupBy('modules.course_id')
            ->pluck('total', 'course_id');

        return $courses->map(function ($course) use ($moduleCounts, $lessonCounts) {
            return [
                'id' => $course->id,
                'title' => $course->title,
                'modules' => (int) ($moduleCounts[$course->id] ?? 0),
                'lessons' => (int) ($lessonCounts[$course->id] ?? 0),
            ];
        })->toArray();
    }

    protected function fetchServicesCatalog(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $limit = $filters['limit'] ?? 20;
        return Service::where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'service_name', 'service_type', 'service_level', 'price'])
            ->map(fn ($service) => [
                'id' => $service->id,
                'name' => $service->service_name,
                'type' => $service->service_type ?? $service->type ?? null,
                'level' => $service->service_level ?? null,
                'price' => $service->price,
            ])
            ->toArray();
    }

    protected function fetchChildSummary(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $query = Child::where('organization_id', $orgId)->with(['user:id,name,email', 'assignedTeachers:id,name']);

        if ($this->isTeacher($user)) {
            $query->whereIn('id', $this->teacherVisibleChildIds($user));
        }

        if (!empty($filters['child_id'])) {
            $query->where('id', $filters['child_id']);
        } elseif (!empty($filters['child_name'])) {
            $query->where('child_name', 'like', '%' . $filters['child_name'] . '%');
        }

        $limit = $filters['limit'] ?? 5;
        $children = $query->limit($limit)->get();

        if ($children->isEmpty()) {
            return ['message' => 'No children found'];
        }

        return $children->map(function (Child $child) {
            return [
                'id' => $child->id,
                'name' => $child->child_name,
                'year_group' => $child->year_group,
                'school' => $child->school_name,
                'parent' => $child->user ? [
                    'name' => $child->user->name,
                    'email' => $child->user->email,
                ] : null,
                'assigned_teachers' => $child->assignedTeachers->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                ])->values()->toArray(),
            ];
        })->toArray();
    }

    protected function fetchChildAccess(User $user, array $filters): array
    {
        $child = $this->resolveChild($user, $filters);
        if (!$child) {
            return ['message' => 'Specify a child to view access details'];
        }

        $accessRecords = Access::where('child_id', $child->id)
            ->where('access', true)
            ->orderByDesc('purchase_date')
            ->limit($filters['limit'] ?? 20)
            ->get();

        $courseIds = $accessRecords->flatMap(fn ($a) => $a->course_ids ?? [])->unique()->values();
        $lessonIds = $accessRecords->flatMap(fn ($a) => $a->lesson_ids ?? [])->unique()->values();
        $assessmentIds = $accessRecords->flatMap(fn ($a) => $a->assessment_ids ?? [])->unique()->values();

        $courses = Course::whereIn('id', $courseIds)->get()->keyBy('id');
        $lessons = ContentLesson::whereIn('id', $lessonIds)->get()->keyBy('id');
        $assessments = \App\Models\Assessment::whereIn('id', $assessmentIds)->get()->keyBy('id');

        $items = [];
        foreach ($accessRecords as $access) {
            $serviceName = $access->metadata['service_name'] ?? null;
            $serviceId = $access->metadata['service_id'] ?? null;

            foreach (($access->course_ids ?? []) as $cid) {
                $course = $courses->get($cid);
                if ($course) {
                    $items[] = [
                        'type' => 'course',
                        'id' => $course->id,
                        'title' => $course->title,
                        'service_name' => $serviceName,
                        'service_id' => $serviceId,
                    ];
                }
            }

            foreach (($access->lesson_ids ?? []) as $lid) {
                $lesson = $lessons->get($lid);
                if ($lesson) {
                    $items[] = [
                        'type' => 'content_lesson',
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'service_name' => $serviceName,
                        'service_id' => $serviceId,
                    ];
                }
            }

            foreach (($access->assessment_ids ?? []) as $aid) {
                $assessment = $assessments->get($aid);
                if ($assessment) {
                    $items[] = [
                        'type' => 'assessment',
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'service_name' => $serviceName,
                        'service_id' => $serviceId,
                    ];
                }
            }
        }

        $items = collect($items)->unique(fn ($i) => $i['type'] . ':' . $i['id'])->values();

        return [
            'child' => ['id' => $child->id, 'name' => $child->child_name],
            'total_items' => $items->count(),
            'items' => $items->take(10)->toArray(),
        ];
    }

    protected function fetchChildProgress(User $user, array $filters): array
    {
        $child = $this->resolveChild($user, $filters);
        if (!$child) return ['message' => 'Specify a child to view progress'];

        $query = LessonProgress::where('child_id', $child->id);
        $limit = $filters['limit'] ?? 10;

        $recent = $query->orderByDesc('updated_at')->limit($limit)->get();

        return [
            'child' => ['id' => $child->id, 'name' => $child->child_name],
            'total' => LessonProgress::where('child_id', $child->id)->count(),
            'completed' => LessonProgress::where('child_id', $child->id)->where('status', 'completed')->count(),
            'in_progress' => LessonProgress::where('child_id', $child->id)->where('status', 'in_progress')->count(),
            'recent' => $recent->map(fn ($p) => [
                'lesson_id' => $p->lesson_id,
                'status' => $p->status,
                'completion_percentage' => $p->completion_percentage,
                'last_accessed_at' => $p->last_accessed_at?->toISOString(),
            ])->toArray(),
        ];
    }

    protected function fetchChildSubmissions(User $user, array $filters): array
    {
        $child = $this->resolveChild($user, $filters);
        if (!$child) return ['message' => 'Specify a child to view submissions'];

        $limit = $filters['limit'] ?? 10;
        $submissions = AssessmentSubmission::where('child_id', $child->id)
            ->with('assessment:id,title')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'child' => ['id' => $child->id, 'name' => $child->child_name],
            'total' => AssessmentSubmission::where('child_id', $child->id)->count(),
            'recent' => $submissions->map(fn ($s) => [
                'assessment_id' => $s->assessment_id,
                'assessment_title' => $s->assessment?->title,
                'score' => "{$s->marks_obtained}/{$s->total_marks}",
                'status' => $s->status,
                'submitted_at' => $s->finished_at?->toISOString(),
            ])->toArray(),
        ];
    }

    protected function fetchTeacherLoad(User $user, array $filters): array
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $limit = $filters['limit'] ?? 5;
        $teacherIds = User::where('current_organization_id', $orgId)
            ->where('role', User::ROLE_TEACHER)
            ->pluck('id');

        $teacherData = User::whereIn('id', $teacherIds)->get()->map(function (User $teacher) use ($limit) {
            $assignedCount = DB::table('child_teacher')
                ->where('teacher_id', $teacher->id)
                ->count();

            $upcomingSessions = LiveLessonSession::where('teacher_id', $teacher->id)
                ->where('scheduled_start_time', '>=', now())
                ->count();

            return [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->name,
                'assigned_children' => $assignedCount,
                'upcoming_live_sessions' => $upcomingSessions,
            ];
        })->take($limit)->values();

        return $teacherData->toArray();
    }

    protected function fetchTeacherSchedule(User $user, array $filters): array
    {
        $teacherId = $filters['teacher_id'] ?? ($this->isTeacher($user) ? $user->id : null);
        if (!$teacherId) return ['message' => 'Specify a teacher to view schedule'];

        $limit = $filters['limit'] ?? 10;
        $query = LiveLessonSession::where('teacher_id', $teacherId);

        if (!empty($filters['time_range'])) {
            $query = $this->applyTimeFilter($query, $filters['time_range'], 'scheduled_start_time');
        } else {
            $query->where('scheduled_start_time', '>=', now());
        }

        $sessions = $query->orderBy('scheduled_start_time')->limit($limit)->get();

        return $sessions->map(fn ($s) => [
            'session_id' => $s->id,
            'title' => $s->lesson?->title,
            'scheduled_start_time' => $s->scheduled_start_time?->toISOString(),
            'status' => $s->status,
        ])->toArray();
    }

    protected function fetchJourneyProgress(User $user, array $filters): array
    {
        $child = $this->resolveChild($user, $filters);
        if (!$child) return ['message' => 'Specify a child to view journey progress'];

        $accessRecords = Access::where('child_id', $child->id)
            ->where('access', true)
            ->get();

        $courseIds = $accessRecords->flatMap(fn ($a) => $a->course_ids ?? [])->unique()->values();
        if ($courseIds->isEmpty()) {
            return ['message' => 'No courses found for this child'];
        }

        $courses = Course::with(['modules' => function ($q) {
            $q->orderBy('order_position')->with(['lessons' => function ($lq) {
                $lq->select('new_lessons.id', 'new_lessons.title', 'new_lessons.status');
            }]);
        }])->whereIn('id', $courseIds)->get();

        $courseData = $courses->map(function ($course) use ($child) {
            $lessonIds = $course->modules->flatMap(fn ($m) => $m->lessons->pluck('id'));
            $progress = LessonProgress::where('child_id', $child->id)
                ->whereIn('lesson_id', $lessonIds)
                ->get();

            $totalLessons = $lessonIds->count();
            $completedLessons = $progress->where('status', 'completed')->count();

            return [
                'course_id' => $course->id,
                'title' => $course->title,
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'completion_percentage' => $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0,
            ];
        });

        return [
            'child' => ['id' => $child->id, 'name' => $child->child_name],
            'courses' => $courseData->take(5)->values()->toArray(),
        ];
    }

    protected function fetchRevenueSummary(User $user, array $filters): array
    {
        if ($this->isTeacher($user)) {
            return ['message' => 'Revenue data is not available for teacher role'];
        }

        $orgId = $this->orgId($user);
        if (!$orgId) return ['message' => 'No organization context set'];

        $query = Transaction::where('organization_id', $orgId);
        if (!empty($filters['time_range']) && $filters['time_range'] !== 'all') {
            $query = $this->applyTimeFilter($query, $filters['time_range'], 'created_at');
        }

        $transactions = $query->orderByDesc('created_at')->limit(10)->get();

        $totals = [
            'last_7_days' => Transaction::where('organization_id', $orgId)->where('created_at', '>=', now()->subDays(7))->sum('amount'),
            'last_30_days' => Transaction::where('organization_id', $orgId)->where('created_at', '>=', now()->subDays(30))->sum('amount'),
            'last_90_days' => Transaction::where('organization_id', $orgId)->where('created_at', '>=', now()->subDays(90))->sum('amount'),
        ];

        return [
            'totals' => $totals,
            'recent' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'amount' => $t->amount,
                'currency' => $t->currency ?? 'GBP',
                'status' => $t->status,
                'created_at' => $t->created_at?->toISOString(),
            ])->toArray(),
        ];
    }

    protected function resolveChild(User $user, array $filters): ?Child
    {
        $orgId = $this->orgId($user);
        if (!$orgId) return null;

        $query = Child::where('organization_id', $orgId);

        if ($this->isTeacher($user)) {
            $query->whereIn('id', $this->teacherVisibleChildIds($user));
        }

        if (!empty($filters['child_id'])) {
            $query->where('id', $filters['child_id']);
        } elseif (!empty($filters['child_name'])) {
            $query->where('child_name', 'like', '%' . $filters['child_name'] . '%');
        } else {
            return null;
        }

        return $query->first();
    }

    protected function applyTimeFilter($query, string $timeRange, string $dateColumn)
    {
        $now = now();
        return match ($timeRange) {
            'last_7_days' => $query->where($dateColumn, '>=', $now->copy()->subDays(7)),
            'last_30_days' => $query->where($dateColumn, '>=', $now->copy()->subDays(30)),
            'last_90_days' => $query->where($dateColumn, '>=', $now->copy()->subDays(90)),
            'upcoming_7_days' => $query->whereBetween($dateColumn, [$now, $now->copy()->addDays(7)]),
            'upcoming_30_days' => $query->whereBetween($dateColumn, [$now, $now->copy()->addDays(30)]),
            default => $query,
        };
    }
}
