<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminTask;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\ParentFeedbacks;
use App\Models\Teacher;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrganizationId($request);
        $metrics = $this->getDashboardMetrics($orgId);

        $organizations = null;
        if ($user->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')
                ->get(['id', 'name', 'slug', 'status']);
        }

        $currentOrganization = $orgId
            ? Organization::select(['id', 'name', 'slug', 'status'])->find($orgId)
            : null;

        return $this->success([
            'metrics' => $metrics,
            'organizations' => $organizations,
            'current_organization' => $currentOrganization,
        ]);
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $user = $request->user();
        $orgId = $request->attributes->get('organization_id') ?: $user?->current_organization_id;

        if ($user?->isSuperAdmin() && $request->filled('organization_id')) {
            return $request->integer('organization_id');
        }

        return $orgId ? (int) $orgId : null;
    }

    /**
     * GET /api/v1/admin/dashboard/delivery
     * Enriched data for the Delivery Hub page.
     */
    public function deliveryHub(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) return $this->error('Unauthenticated.', [], 401);

        $orgId = $this->resolveOrganizationId($request);

        // Sessions from start of today + next 14 days
        $upcomingSessions = \App\Models\Lesson::query()
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereNotNull('start_time')
            ->where('start_time', '>=', now()->startOfDay())
            ->where('start_time', '<=', now()->addDays(14)->endOfDay())
            ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
            }))
            ->with([
                'children:id,child_name',
                'service:id,service_name,max_participants,session_duration_minutes',
            ])
            ->withCount('children as participants_count')
            ->orderBy('start_time')
            ->limit(50)
            ->get()
            ->map(function ($l) {
                // Resolve instructor
                $instructor = $l->instructor_id ? \App\Models\User::select('id', 'name', 'avatar_path')->find($l->instructor_id) : null;
                $teacherRecord = $l->instructor_id ? \App\Models\Teacher::where('user_id', $l->instructor_id)->select('image_path')->first() : null;
                $avatarUrl = $teacherRecord?->image_path ? '/storage/' . $teacherRecord->image_path
                    : ($instructor?->avatar_path ? '/storage/' . $instructor->avatar_path : null);

                $duration = $l->start_time && $l->end_time
                    ? \Carbon\Carbon::parse($l->start_time)->diffInMinutes(\Carbon\Carbon::parse($l->end_time))
                    : ($l->service?->session_duration_minutes ?? null);

                return [
                    'id'                 => $l->id,
                    'title'              => $l->title,
                    'start_time'         => $l->start_time?->toIso8601String(),
                    'end_time'           => $l->end_time?->toIso8601String(),
                    'status'             => $l->status,
                    'lesson_type'        => $l->lesson_type,
                    'lesson_mode'        => $l->lesson_mode,
                    'duration_minutes'   => $duration,
                    'participants_count' => $l->participants_count,
                    'max_participants'   => $l->max_participants ?? $l->service?->max_participants,
                    'student_name'       => $l->children->pluck('child_name')->join(', ') ?: null,
                    'service_name'       => $l->service?->service_name,
                    'service_id'         => $l->service_id,
                    'instructor_id'      => $l->instructor_id,
                    'instructor_name'    => $instructor?->name,
                    'instructor_avatar'  => $avatarUrl,
                ];
            });

        // Open tasks (Pending + In Progress) — enriched with new fields
        $pendingTasks = collect([]);
        try {
            $pendingTasks = AdminTask::query()
                ->whereIn('status', ['Pending', 'In Progress'])
                ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                    $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
                }))
                ->with('assignedUser:id,name')
                ->orderByRaw("CASE WHEN due_at IS NOT NULL AND due_at < NOW() THEN 0 ELSE 1 END")
                ->orderByRaw("FIELD(priority, 'Critical', 'High', 'Medium', 'Low')")
                ->orderBy('created_at', 'desc')
                ->limit(15)
                ->get(['id', 'title', 'task_type', 'description', 'priority', 'status',
                       'due_at', 'category', 'action_url', 'source', 'assigned_to', 'created_at'])
                ->map(fn ($t) => array_merge($t->toArray(), [
                    'is_overdue' => $t->is_overdue,
                    'days_open'  => $t->days_open,
                ]));
        } catch (\Exception $e) {
            // AdminTask table may not exist — graceful fallback
        }

        // Task summary counts
        $taskSummary = $this->getTaskSummary($orgId);

        // Pending marking — homework submissions awaiting grading (kept for backward compat)
        $pendingMarking = $this->getPendingMarking($orgId);

        // Unified pending actions — real-time from source tables
        $pendingActions = $this->getPendingActions($orgId);

        return $this->success([
            'upcoming_sessions' => $upcomingSessions,
            'pending_tasks'     => $pendingTasks,
            'pending_marking'   => $pendingMarking,
            'pending_actions'   => $pendingActions,
            'task_summary'      => $taskSummary,
            'hub_statuses'      => $this->computeHubStatuses($orgId, $upcomingSessions),
            'metrics'           => $this->getDashboardMetrics($orgId),
        ]);
    }

    private function getDashboardMetrics(?int $orgId): array
    {
        return [
            'revenue_today' => $this->getTodayRevenue($orgId),
            'revenue_month' => $this->getMonthlyRevenue($orgId),
            'active_students' => $this->getActiveStudentsCount($orgId),
            'pending_actions' => $this->getPendingActionsCount($orgId),
            'completion_rate' => $this->getLessonCompletionRate($orgId),
            'success_rate' => $this->getAssessmentSuccessRate($orgId),
            'weekly_revenue' => $this->getWeeklyRevenueTrend($orgId),
            'critical_actions' => $this->getCriticalActionsCount($orgId),
        ];
    }

    private function getTodayRevenue(?int $orgId): float
    {
        $query = Transaction::whereIn('status', ['completed', 'success', 'paid', 'confirmed'])
            ->whereDate('created_at', today());

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        return (float) ($query->sum('total') ?? 0);
    }

    private function getMonthlyRevenue(?int $orgId): float
    {
        $query = Transaction::whereIn('status', ['completed', 'success', 'paid', 'confirmed'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        return (float) ($query->sum('total') ?? 0);
    }

    private function getActiveStudentsCount(?int $orgId): int
    {
        $query = Child::query();

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        return $query->where(function ($q) {
            $q->whereHas('assessmentSubmissions', function ($subQ) {
                $subQ->where('created_at', '>=', now()->subWeek());
            })
            ->orWhereHas('lessonProgress', function ($subQ) {
                $subQ->where('updated_at', '>=', now()->subWeek());
            })
            ->orWhereHas('user', function ($subQ) {
                $subQ->where('updated_at', '>=', now()->subWeek());
            });
        })
            ->distinct()
            ->count();
    }

    private function getPendingActionsCount(?int $orgId): int
    {
        return AdminTask::where('status', 'Pending')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();
    }

    private function getCriticalActionsCount(?int $orgId): int
    {
        return AdminTask::where('status', 'Pending')
            ->where('priority', 'Critical')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();
    }

    private function getLessonCompletionRate(?int $orgId): float
    {
        $totalAssessments = Assessment::where('status', 'active')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();

        if ($totalAssessments === 0) {
            return 0.0;
        }

        $assessmentsWithCompletions = Assessment::where('status', 'active')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->whereHas('submissions', fn($q) => $q->where('status', 'graded'))
            ->count();

        return round(($assessmentsWithCompletions / $totalAssessments) * 100, 1);
    }

    private function getAssessmentSuccessRate(?int $orgId): float
    {
        $submissionsQuery = AssessmentSubmission::where('status', 'graded')
            ->whereNotNull('marks_obtained')
            ->whereNotNull('total_marks');

        if ($orgId) {
            $submissionsQuery->whereHas('assessment', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            });
        }

        $submissions = $submissionsQuery->get();

        if ($submissions->count() === 0) {
            return 0.0;
        }

        $successCount = $submissions->filter(function ($submission) {
            return $submission->total_marks > 0 &&
                ($submission->marks_obtained / $submission->total_marks) >= 0.7;
        })->count();

        return round(($successCount / $submissions->count()) * 100, 1);
    }

    private function getWeeklyRevenueTrend(?int $orgId): array
    {
        return collect(range(6, 0))->map(function ($daysAgo) use ($orgId) {
            $date = now()->subDays($daysAgo);
            $query = Transaction::whereIn('status', ['completed', 'success', 'paid', 'confirmed'])
                ->whereDate('created_at', $date);

            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            return [
                'date' => $date->format('M d'),
                'revenue' => (float) ($query->sum('total') ?? 0),
            ];
        })->toArray();
    }

    /**
     * Get homework submissions + assessment submissions awaiting marking, sorted by urgency.
     */
    private function getPendingMarking(?int $orgId): array
    {
        $items = collect();

        // Homework submissions needing marking
        try {
            $homeworkItems = HomeworkSubmission::query()
                ->where('submission_status', 'submitted')
                ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                    $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
                }))
                ->with([
                    'child:id,child_name',
                    'assignment:id,title,due_date,subject',
                ])
                ->orderByRaw('submitted_at IS NULL, submitted_at ASC')
                ->limit(15)
                ->get()
                ->map(function ($s) {
                    $submittedAt = $s->submitted_at ? \Carbon\Carbon::parse($s->submitted_at) : null;
                    $waitingDays = $submittedAt ? $submittedAt->diffInDays(now()) : 0;

                    return [
                        'id'              => $s->id,
                        'type'            => 'homework',
                        'title'           => $s->assignment?->title ?? 'Untitled Homework',
                        'subject'         => $s->assignment?->subject ?? null,
                        'student_id'      => $s->student_id,
                        'student_name'    => $s->child?->child_name ?? 'Unknown',
                        'attempt'         => $s->attempt ?? 1,
                        'submitted_at'    => $submittedAt?->toIso8601String(),
                        'waiting_days'    => $waitingDays,
                        'is_urgent'       => $waitingDays >= 3,
                        'grade_url'       => "/admin/homework-submissions/{$s->id}/edit",
                    ];
                });
            $items = $items->merge($homeworkItems);
        } catch (\Exception $e) {
            // HomeworkSubmission table may not exist
        }

        // Assessment submissions needing marking
        try {
            $assessmentItems = AssessmentSubmission::query()
                ->where('status', 'pending')
                ->whereNotNull('finished_at')
                ->when($orgId, fn ($q) => $q->whereHas('assessment', function ($q2) use ($orgId) {
                    $q2->where('organization_id', $orgId);
                }))
                ->with([
                    'child:id,child_name',
                    'assessment:id,title',
                ])
                ->orderBy('finished_at', 'asc')
                ->limit(15)
                ->get()
                ->map(function ($s) {
                    $finishedAt = $s->finished_at ? \Carbon\Carbon::parse($s->finished_at) : null;
                    $waitingDays = $finishedAt ? $finishedAt->diffInDays(now()) : 0;

                    return [
                        'id'              => $s->id,
                        'type'            => 'assessment',
                        'title'           => $s->assessment?->title ?? 'Untitled Assessment',
                        'subject'         => null,
                        'student_id'      => $s->child_id,
                        'student_name'    => $s->child?->child_name ?? 'Unknown',
                        'attempt'         => $s->retake_number ?? 1,
                        'submitted_at'    => $finishedAt?->toIso8601String(),
                        'waiting_days'    => $waitingDays,
                        'is_urgent'       => $waitingDays >= 3,
                        'grade_url'       => "/admin/submissions/{$s->id}/grade",
                    ];
                });
            $items = $items->merge($assessmentItems);
        } catch (\Exception $e) {
            // AssessmentSubmission table may not exist
        }

        // Sort by waiting_days desc (most urgent first), limit to 20
        return $items
            ->sortByDesc('waiting_days')
            ->values()
            ->take(20)
            ->toArray();
    }

    /**
     * Resolve teacher name + avatar for a given user ID.
     * Checks Teacher record first for image_path, falls back to User avatar_path.
     */
    private function resolveTeacherInfo(?int $userId): ?array
    {
        if (!$userId) return null;
        $user = \App\Models\User::select('id', 'name', 'avatar_path')->find($userId);
        if (!$user) return null;

        $teacherRecord = Teacher::where('user_id', $userId)->select('image_path')->first();
        $avatarUrl = $teacherRecord?->image_path ? '/storage/' . $teacherRecord->image_path
            : ($user->avatar_path ? '/storage/' . $user->avatar_path : null);

        return [
            'id'     => $user->id,
            'name'   => $user->name,
            'avatar' => $avatarUrl,
        ];
    }

    /**
     * Resolve the responsible teacher for a child via child_teacher pivot.
     * Returns the first assigned teacher or null.
     */
    private function resolveChildTeacher(?int $childId): ?array
    {
        if (!$childId) return null;
        try {
            $child = Child::find($childId);
            if (!$child) return null;
            $teacher = $child->assignedTeachers()->first();
            if (!$teacher) return null;
            return $this->resolveTeacherInfo($teacher->id);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Unified pending actions — pulls from real data sources (not the task system).
     * Returns all actionable items sorted by urgency for the Needs Action panel.
     *
     * Urgency thresholds: 0-4 days = normal, 4-7 days = warning/overdue, 7+ days = critical/red.
     */
    private function getPendingActions(?int $orgId): array
    {
        $actions = collect();
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where(function ($q3) use ($orgId) {
            $q3->where('organization_id', $orgId)->orWhereNull('organization_id');
        }));

        // ── 1. Grade Homework ──
        try {
            $hwActions = HomeworkSubmission::query()
                ->where('submission_status', 'submitted')
                ->tap($orgScope)
                ->with(['child:id,child_name', 'assignment:id,title,due_date,subject,assigned_by'])
                ->orderByRaw('submitted_at IS NULL, submitted_at ASC')
                ->limit(20)
                ->get()
                ->map(function ($s) {
                    $submittedAt = $s->submitted_at ? \Carbon\Carbon::parse($s->submitted_at) : null;
                    $waitingDays = $submittedAt ? (int) $submittedAt->diffInDays(now()) : 0;
                    // Teacher: assignment's assigned_by, or child's assigned teacher
                    $teacher = $this->resolveTeacherInfo($s->assignment?->assigned_by)
                            ?? $this->resolveChildTeacher($s->student_id);
                    return [
                        'id'          => 'hw-' . $s->id,
                        'entity_id'   => $s->id,
                        'category'    => 'marking',
                        'type'        => 'grade_homework',
                        'label'       => 'Grade Homework',
                        'title'       => $s->assignment?->title ?? 'Untitled Homework',
                        'subtitle'    => $s->child?->child_name ?? 'Unknown Student',
                        'detail'      => $s->assignment?->subject,
                        'action_url'  => "/admin/homework-submissions/{$s->id}/edit",
                        'timestamp'   => $submittedAt?->toIso8601String(),
                        'waiting_days' => $waitingDays,
                        'is_overdue'  => $waitingDays >= 4,
                        'is_critical' => $waitingDays >= 7,
                        'priority'    => $waitingDays >= 7 ? 'critical' : ($waitingDays >= 4 ? 'high' : 'medium'),
                        'teacher'     => $teacher,
                    ];
                });
            $actions = $actions->merge($hwActions);
        } catch (\Exception $e) {}

        // ── 2. Grade Assessment ──
        try {
            $asActions = AssessmentSubmission::query()
                ->where('status', 'pending')
                ->whereNotNull('finished_at')
                ->when($orgId, fn ($q) => $q->whereHas('assessment', fn ($q2) => $q2->where('organization_id', $orgId)))
                ->with(['child:id,child_name', 'assessment:id,title'])
                ->orderBy('finished_at', 'asc')
                ->limit(20)
                ->get()
                ->map(function ($s) {
                    $finishedAt = $s->finished_at ? \Carbon\Carbon::parse($s->finished_at) : null;
                    $waitingDays = $finishedAt ? (int) $finishedAt->diffInDays(now()) : 0;
                    // Teacher: child's assigned teacher
                    $teacher = $this->resolveChildTeacher($s->child_id);
                    return [
                        'id'          => 'as-' . $s->id,
                        'entity_id'   => $s->id,
                        'category'    => 'marking',
                        'type'        => 'grade_assessment',
                        'label'       => 'Grade Assessment',
                        'title'       => $s->assessment?->title ?? 'Untitled Assessment',
                        'subtitle'    => $s->child?->child_name ?? 'Unknown Student',
                        'detail'      => null,
                        'action_url'  => "/admin/submissions/{$s->id}/grade",
                        'timestamp'   => $finishedAt?->toIso8601String(),
                        'waiting_days' => $waitingDays,
                        'is_overdue'  => $waitingDays >= 4,
                        'is_critical' => $waitingDays >= 7,
                        'priority'    => $waitingDays >= 7 ? 'critical' : ($waitingDays >= 4 ? 'high' : 'medium'),
                        'teacher'     => $teacher,
                    ];
                });
            $actions = $actions->merge($asActions);
        } catch (\Exception $e) {}

        // ── 3. Mark Attendance ──
        try {
            $unmarkedLessons = Lesson::query()
                ->where('status', 'ended')
                ->where('end_time', '>=', now()->subDays(14))
                ->tap($orgScope)
                ->whereDoesntHave('attendances')
                ->with(['children:id,child_name', 'service:id,service_name'])
                ->orderBy('end_time', 'asc')
                ->limit(20)
                ->get()
                ->map(function ($l) {
                    $endedAt = $l->end_time ? \Carbon\Carbon::parse($l->end_time) : null;
                    $waitingDays = $endedAt ? (int) $endedAt->diffInDays(now()) : 0;
                    $studentNames = $l->children->pluck('child_name')->join(', ');
                    // Teacher: the lesson's instructor
                    $teacher = $this->resolveTeacherInfo($l->instructor_id);
                    return [
                        'id'          => 'att-' . $l->id,
                        'entity_id'   => $l->id,
                        'category'    => 'attendance',
                        'type'        => 'mark_attendance',
                        'label'       => 'Mark Attendance',
                        'title'       => $l->title ?: ($l->service?->service_name ?? 'Session'),
                        'subtitle'    => $studentNames ?: 'No students listed',
                        'detail'      => $endedAt ? $endedAt->format('D j M, H:i') : null,
                        'action_url'  => "/admin/lessons/{$l->id}",
                        'timestamp'   => $endedAt?->toIso8601String(),
                        'waiting_days' => $waitingDays,
                        'is_overdue'  => $waitingDays >= 4,
                        'is_critical' => $waitingDays >= 7,
                        'priority'    => $waitingDays >= 7 ? 'critical' : ($waitingDays >= 4 ? 'high' : 'medium'),
                        'teacher'     => $teacher,
                    ];
                });
            $actions = $actions->merge($unmarkedLessons);
        } catch (\Exception $e) {}

        // ── 4. Assign Teacher (unassigned upcoming sessions) ──
        try {
            $unassignedSessions = Lesson::query()
                ->whereNotIn('status', ['cancelled', 'draft', 'ended'])
                ->whereNotNull('start_time')
                ->where('start_time', '>=', now()->startOfDay())
                ->where('start_time', '<=', now()->addDays(14)->endOfDay())
                ->whereNull('instructor_id')
                ->tap($orgScope)
                ->with(['children:id,child_name', 'service:id,service_name'])
                ->orderBy('start_time', 'asc')
                ->limit(15)
                ->get()
                ->map(function ($l) {
                    $startAt = $l->start_time ? \Carbon\Carbon::parse($l->start_time) : null;
                    $hoursUntil = $startAt ? now()->diffInHours($startAt, false) : 999;
                    $isToday = $startAt && $startAt->isToday();
                    $isPast = $hoursUntil < 0;
                    // No teacher by definition — these need assignment
                    return [
                        'id'          => 'sch-' . $l->id,
                        'entity_id'   => $l->id,
                        'category'    => 'schedule',
                        'type'        => 'assign_teacher',
                        'label'       => 'Assign Teacher',
                        'title'       => $l->title ?: ($l->service?->service_name ?? 'Session'),
                        'subtitle'    => $l->children->pluck('child_name')->join(', ') ?: 'No students',
                        'detail'      => $startAt ? $startAt->format('D j M, H:i') : null,
                        'action_url'  => "/admin/scheduling",
                        'timestamp'   => $startAt?->toIso8601String(),
                        'waiting_days' => $isToday || $isPast ? 99 : 0,
                        'is_overdue'  => $isToday || $isPast,
                        'is_critical' => $isToday || $isPast,
                        'priority'    => ($isToday || $isPast) ? 'critical' : ($hoursUntil <= 48 ? 'high' : 'medium'),
                        'teacher'     => null, // Unassigned — no teacher
                    ];
                });
            $actions = $actions->merge($unassignedSessions);
        } catch (\Exception $e) {}

        // ── 5. Parent Feedback ──
        try {
            $feedbackActions = ParentFeedbacks::query()
                ->where('status', 'New')
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->orderBy('created_at', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($f) {
                    $createdAt = $f->created_at ? \Carbon\Carbon::parse($f->created_at) : null;
                    $waitingDays = $createdAt ? (int) $createdAt->diffInDays(now()) : 0;
                    return [
                        'id'          => 'fb-' . $f->id,
                        'entity_id'   => $f->id,
                        'category'    => 'feedback',
                        'type'        => 'respond_feedback',
                        'label'       => 'Respond to Feedback',
                        'title'       => $f->category ? ucfirst($f->category) . ' Feedback' : 'Parent Feedback',
                        'subtitle'    => $f->name ?? 'Unknown Parent',
                        'detail'      => $f->message ? \Illuminate\Support\Str::limit($f->message, 60) : null,
                        'action_url'  => "/admin/feedback/{$f->id}",
                        'timestamp'   => $createdAt?->toIso8601String(),
                        'waiting_days' => $waitingDays,
                        'is_overdue'  => $waitingDays >= 4,
                        'is_critical' => $waitingDays >= 7,
                        'priority'    => $waitingDays >= 7 ? 'critical' : ($waitingDays >= 4 ? 'high' : 'medium'),
                        'teacher'     => null, // Admin responsibility, not teacher-specific
                    ];
                });
            $actions = $actions->merge($feedbackActions);
        } catch (\Exception $e) {}

        // ── Sort: critical first, then overdue, then by waiting_days desc ──
        $sorted = $actions
            ->sortBy([
                fn ($a, $b) => ($b['is_critical'] ?? false) <=> ($a['is_critical'] ?? false),
                fn ($a, $b) => ($b['is_overdue'] ?? false) <=> ($a['is_overdue'] ?? false),
                fn ($a, $b) => ($b['waiting_days'] ?? 0) <=> ($a['waiting_days'] ?? 0),
            ])
            ->values()
            ->take(40);

        // ── Category summary counts ──
        $counts = [
            'total'      => $sorted->count(),
            'overdue'    => $sorted->where('is_overdue', true)->count(),
            'critical'   => $sorted->where('is_critical', true)->count(),
            'marking'    => $actions->where('category', 'marking')->count(),
            'attendance' => $actions->where('category', 'attendance')->count(),
            'schedule'   => $actions->where('category', 'schedule')->count(),
            'feedback'   => $actions->where('category', 'feedback')->count(),
        ];

        return [
            'items'  => $sorted->toArray(),
            'counts' => $counts,
        ];
    }

    /**
     * Compute real-time status for each DeliveryHub quick link.
     */
    private function computeHubStatuses(?int $orgId, $upcomingSessions = null): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where(function ($q3) use ($orgId) {
            $q3->where('organization_id', $orgId)->orWhereNull('organization_id');
        }));

        // Schedule: check for unassigned upcoming sessions
        $unassigned = Lesson::query()
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereNotNull('start_time')
            ->where('start_time', '>=', now())
            ->whereNull('instructor_id')
            ->tap($orgScope)
            ->count();

        $unassignedToday = Lesson::query()
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereNotNull('start_time')
            ->whereBetween('start_time', [now()->startOfDay(), now()->endOfDay()])
            ->whereNull('instructor_id')
            ->tap($orgScope)
            ->count();

        $scheduleStatus = $unassignedToday > 0 ? 'error'
            : ($unassigned > 0 ? 'warning' : 'ok');
        $scheduleDetail = $unassignedToday > 0 ? "{$unassignedToday} session(s) today need teachers"
            : ($unassigned > 0 ? "{$unassigned} upcoming session(s) unassigned" : 'All sessions assigned');

        // Sessions: check today's sessions
        $sessionsToday = $upcomingSessions
            ? collect($upcomingSessions)->filter(fn ($s) => isset($s['start_time']) && now()->isSameDay(\Carbon\Carbon::parse($s['start_time'])))->count()
            : 0;
        $sessionsStatus = $sessionsToday > 0 ? 'ok' : 'neutral';
        $sessionsDetail = $sessionsToday > 0 ? "{$sessionsToday} session(s) today" : 'No sessions today';

        // Tasks: check ALL open tasks (Pending + In Progress), not just Pending
        $taskOrgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where(function ($q3) use ($orgId) {
            $q3->where('organization_id', $orgId)->orWhereNull('organization_id');
        }));
        $overdueCount = AdminTask::open()->overdue()->tap($taskOrgScope)->count();
        $openCount = AdminTask::open()->tap($taskOrgScope)->count();
        $pendingCount = AdminTask::pending()->tap($taskOrgScope)->count();
        $inProgressCount = AdminTask::where('status', 'In Progress')->tap($taskOrgScope)->count();

        $tasksStatus = $overdueCount > 0 ? 'error'
            : ($openCount > 0 ? 'warning' : 'ok');
        $tasksDetail = $overdueCount > 0 ? "{$overdueCount} overdue task(s)"
            : ($openCount > 0 ? "{$pendingCount} pending, {$inProgressCount} in progress" : 'All tasks complete');

        // Attendance: check recent sessions with no attendance
        $recentLessonsCount = Lesson::where('status', 'ended')
            ->where('end_time', '>=', now()->subDays(7))
            ->tap($orgScope)
            ->count();
        $unmarkedAttendance = 0;
        if ($recentLessonsCount > 0) {
            $unmarkedAttendance = Lesson::where('status', 'ended')
                ->where('end_time', '>=', now()->subDays(7))
                ->tap($orgScope)
                ->whereDoesntHave('attendances')
                ->count();
        }
        $attendanceStatus = $unmarkedAttendance > 0 ? 'warning'
            : ($recentLessonsCount > 0 ? 'ok' : 'neutral');
        $attendanceDetail = $unmarkedAttendance > 0 ? "{$unmarkedAttendance} session(s) need attendance"
            : ($recentLessonsCount > 0 ? 'All attendance marked' : 'No recent sessions');

        // Live: check live sessions today
        $liveToday = Lesson::whereIn('status', ['scheduled', 'live'])
            ->whereNotNull('start_time')
            ->whereBetween('start_time', [now()->startOfDay(), now()->endOfDay()])
            ->tap($orgScope)
            ->count();
        $liveStatus = $liveToday > 0 ? 'ok' : 'neutral';
        $liveDetail = $liveToday > 0 ? "{$liveToday} live session(s) today" : 'No live sessions today';

        // Marking: check ungraded homework + assessment submissions
        $ungradedCount = 0;
        $urgentCount = 0;
        try {
            $hwUngraded = HomeworkSubmission::where('submission_status', 'submitted')
                ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                    $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
                }))
                ->count();
            $hwUrgent = HomeworkSubmission::where('submission_status', 'submitted')
                ->where('submitted_at', '<=', now()->subDays(3))
                ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                    $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
                }))
                ->count();
            $ungradedCount += $hwUngraded;
            $urgentCount += $hwUrgent;
        } catch (\Exception $e) {}
        try {
            $asUngraded = AssessmentSubmission::where('status', 'pending')
                ->whereNotNull('finished_at')
                ->when($orgId, fn ($q) => $q->whereHas('assessment', fn ($q2) => $q2->where('organization_id', $orgId)))
                ->count();
            $asUrgent = AssessmentSubmission::where('status', 'pending')
                ->whereNotNull('finished_at')
                ->where('finished_at', '<=', now()->subDays(3))
                ->when($orgId, fn ($q) => $q->whereHas('assessment', fn ($q2) => $q2->where('organization_id', $orgId)))
                ->count();
            $ungradedCount += $asUngraded;
            $urgentCount += $asUrgent;
        } catch (\Exception $e) {}
        $markingStatus = $urgentCount > 0 ? 'error'
            : ($ungradedCount > 0 ? 'warning' : 'ok');
        $markingDetail = $urgentCount > 0 ? "{$urgentCount} submission(s) waiting 3+ days"
            : ($ungradedCount > 0 ? "{$ungradedCount} submission(s) to mark" : 'All marking complete');

        // Feedback: check unread parent feedback
        $unreadFeedback = 0;
        try {
            $unreadFeedback = ParentFeedbacks::where('status', 'New')
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->count();
        } catch (\Exception $e) {
            // ParentFeedback may not exist
        }
        $feedbackStatus = $unreadFeedback > 0 ? 'warning' : 'ok';
        $feedbackDetail = $unreadFeedback > 0 ? "{$unreadFeedback} unread feedback" : 'No pending feedback';

        return [
            'schedule'   => ['status' => $scheduleStatus, 'detail' => $scheduleDetail],
            'sessions'   => ['status' => $sessionsStatus, 'detail' => $sessionsDetail],
            'tasks'      => ['status' => $tasksStatus, 'detail' => $tasksDetail],
            'attendance' => ['status' => $attendanceStatus, 'detail' => $attendanceDetail],
            'live'       => ['status' => $liveStatus, 'detail' => $liveDetail],
            'marking'    => ['status' => $markingStatus, 'detail' => $markingDetail],
            'feedback'   => ['status' => $feedbackStatus, 'detail' => $feedbackDetail],
        ];
    }

    /**
     * Summary counts for the task system.
     */
    private function getTaskSummary(?int $orgId): array
    {
        $base = AdminTask::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

        $totalPending = (clone $base)->where('status', 'Pending')->count();
        $totalInProgress = (clone $base)->where('status', 'In Progress')->count();

        return [
            'total_open'     => $totalPending + $totalInProgress,
            'total_pending'  => $totalPending,
            'total_overdue'  => (clone $base)->overdue()->count(),
            'total_in_progress' => $totalInProgress,
            'completed_today' => (clone $base)->where('status', 'Completed')
                ->whereDate('completed_at', today())->count(),
            'by_category' => (clone $base)->where('status', '!=', 'Completed')
                ->selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray(),
        ];
    }
}
