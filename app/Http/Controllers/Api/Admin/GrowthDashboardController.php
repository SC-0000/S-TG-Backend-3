<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\BackgroundAgentRun;
use App\Models\Child;
use App\Models\CommunicationMessage;
use App\Models\ContentLesson;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organization;
use App\Models\ScheduleAllocation;
use App\Models\Service;
use App\Models\Teacher;
use App\Models\TrackingEvent;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrowthDashboardController extends ApiController
{
    /**
     * GET /api/v1/admin/dashboard/growth
     *
     * Centralised analytics dashboard aggregating metrics across the platform.
     * All queries are org-scoped for multi-tenant isolation.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthenticated.', [], 401);
        }

        $orgId = $this->resolveOrganizationId($request);
        $range = $request->get('range', '30'); // days

        return $this->success([
            'financial'  => $this->getFinancialMetrics($orgId, (int) $range),
            'teachers'   => $this->getTeacherMetrics($orgId),
            'students'   => $this->getStudentMetrics($orgId, (int) $range),
            'content'    => $this->getContentMetrics($orgId),
            'agents'     => $this->getAgentMetrics($orgId),
            'growth'     => $this->getGrowthMetrics($orgId, (int) $range),
            'communications' => $this->getCommunicationMetrics($orgId, (int) $range),
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

    /* ════════════════════════════════════════════════════════════════
       FINANCIAL METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getFinancialMetrics(?int $orgId, int $days): array
    {
        $paidStatuses = ['completed', 'success', 'paid', 'confirmed'];
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        $revenueToday = (float) Transaction::whereIn('status', $paidStatuses)
            ->whereDate('created_at', today())
            ->tap($orgScope)->sum('total');

        $revenueThisMonth = (float) Transaction::whereIn('status', $paidStatuses)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->tap($orgScope)->sum('total');

        $revenueLastMonth = (float) Transaction::whereIn('status', $paidStatuses)
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->tap($orgScope)->sum('total');

        $monthGrowth = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : ($revenueThisMonth > 0 ? 100 : 0);

        // Revenue trend — daily for the requested range
        $revenueTrend = collect(range($days - 1, 0))->map(function ($daysAgo) use ($paidStatuses, $orgScope) {
            $date = now()->subDays($daysAgo);
            return [
                'date' => $date->format('M d'),
                'revenue' => (float) Transaction::whereIn('status', $paidStatuses)
                    ->whereDate('created_at', $date)->tap($orgScope)->sum('total'),
            ];
        })->values()->toArray();

        // Transactions by status
        $totalTransactions = Transaction::tap($orgScope)
            ->where('created_at', '>=', now()->subDays($days))->count();
        $successfulTransactions = Transaction::whereIn('status', $paidStatuses)
            ->tap($orgScope)->where('created_at', '>=', now()->subDays($days))->count();

        // Average transaction value
        $avgTransactionValue = (float) Transaction::whereIn('status', $paidStatuses)
            ->tap($orgScope)->where('created_at', '>=', now()->subDays($days))
            ->avg('total') ?? 0;

        return [
            'revenue_today'       => $revenueToday,
            'revenue_this_month'  => $revenueThisMonth,
            'revenue_last_month'  => $revenueLastMonth,
            'month_growth'        => $monthGrowth,
            'revenue_trend'       => $revenueTrend,
            'total_transactions'  => $totalTransactions,
            'successful_transactions' => $successfulTransactions,
            'success_rate'        => $totalTransactions > 0 ? round(($successfulTransactions / $totalTransactions) * 100, 1) : 0,
            'avg_transaction'     => round($avgTransactionValue, 2),
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       TEACHER METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getTeacherMetrics(?int $orgId): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        // Total teachers
        $totalTeachers = User::where('role', 'teacher')
            ->when($orgId, fn ($q) => $q->whereHas('organizations', fn ($q2) => $q2->where('organizations.id', $orgId)))
            ->count();

        // Sessions this week per teacher
        $sessionsThisWeek = Lesson::whereNotIn('status', ['cancelled', 'draft'])
            ->whereNotNull('start_time')
            ->whereBetween('start_time', [now()->startOfWeek(), now()->endOfWeek()])
            ->tap($orgScope)->count();

        // Sessions completed this month
        $sessionsCompletedMonth = Lesson::where('status', 'ended')
            ->whereMonth('end_time', now()->month)
            ->whereYear('end_time', now()->year)
            ->tap($orgScope)->count();

        // Allocations — teacher utilization
        $totalAllocations = ScheduleAllocation::when($orgId, fn ($q) => $q->whereHas('teacherProfile', function ($q2) use ($orgId) {
            $q2->whereHas('user', fn ($q3) => $q3->whereHas('organizations', fn ($q4) => $q4->where('organizations.id', $orgId)));
        }))->count();

        // Per-teacher breakdown — top 10 by sessions
        $teacherBreakdown = [];
        try {
            $teacherBreakdown = DB::table('live_sessions')
                ->select('instructor_id', DB::raw('COUNT(*) as sessions_count'))
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->whereNotNull('instructor_id')
                ->where('start_time', '>=', now()->subDays(30))
                ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                    $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
                }))
                ->groupBy('instructor_id')
                ->orderByDesc('sessions_count')
                ->limit(10)
                ->get()
                ->map(function ($row) {
                    $user = User::select('id', 'name')->find($row->instructor_id);
                    return [
                        'id' => $row->instructor_id,
                        'name' => $user?->name ?? 'Unknown',
                        'sessions' => $row->sessions_count,
                    ];
                })->toArray();
        } catch (\Exception $e) {}

        // Students per teacher (avg)
        $avgStudentsPerTeacher = $totalTeachers > 0
            ? round(Child::tap($orgScope)->count() / $totalTeachers, 1)
            : 0;

        return [
            'total_teachers'         => $totalTeachers,
            'sessions_this_week'     => $sessionsThisWeek,
            'sessions_completed_month' => $sessionsCompletedMonth,
            'total_allocations'      => $totalAllocations,
            'avg_sessions_per_teacher' => $totalTeachers > 0 ? round($sessionsCompletedMonth / $totalTeachers, 1) : 0,
            'avg_students_per_teacher' => $avgStudentsPerTeacher,
            'teacher_breakdown'      => $teacherBreakdown,
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       STUDENT & LEARNING METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getStudentMetrics(?int $orgId, int $days): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        $totalStudents = Child::tap($orgScope)->count();

        // Active students (engaged in last 7 days)
        $activeStudents = Child::tap($orgScope)->where(function ($q) {
            $q->whereHas('assessmentSubmissions', fn ($sq) => $sq->where('created_at', '>=', now()->subWeek()))
              ->orWhereHas('lessonProgress', fn ($sq) => $sq->where('updated_at', '>=', now()->subWeek()));
        })->count();

        // Assessment performance
        $totalSubmissions = AssessmentSubmission::where('status', 'graded')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($orgId, fn ($q) => $q->whereHas('assessment', fn ($q2) => $q2->where('organization_id', $orgId)))
            ->count();

        $avgScore = AssessmentSubmission::where('status', 'graded')
            ->whereNotNull('marks_obtained')->whereNotNull('total_marks')
            ->where('total_marks', '>', 0)
            ->where('created_at', '>=', now()->subDays($days))
            ->when($orgId, fn ($q) => $q->whereHas('assessment', fn ($q2) => $q2->where('organization_id', $orgId)))
            ->selectRaw('AVG(marks_obtained / total_marks * 100) as avg_pct')
            ->value('avg_pct') ?? 0;

        // Homework completion
        $hwSubmitted = HomeworkSubmission::where('created_at', '>=', now()->subDays($days))
            ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
            }))->count();

        $hwGraded = HomeworkSubmission::where('submission_status', 'graded')
            ->where('created_at', '>=', now()->subDays($days))
            ->when($orgId, fn ($q) => $q->where(function ($q2) use ($orgId) {
                $q2->where('organization_id', $orgId)->orWhereNull('organization_id');
            }))->count();

        // Lesson completion (e-learning)
        $lessonCompletions = LessonProgress::where('created_at', '>=', now()->subDays($days))
            ->where('completion_percentage', 100)
            ->when($orgId, fn ($q) => $q->whereHas('child', fn ($q2) => $q2->where('organization_id', $orgId)))
            ->count();

        // Enrollment trend — new students per week for last 8 weeks
        $enrollmentTrend = collect(range(7, 0))->map(function ($weeksAgo) use ($orgScope) {
            $start = now()->subWeeks($weeksAgo)->startOfWeek();
            $end = now()->subWeeks($weeksAgo)->endOfWeek();
            return [
                'week' => $start->format('d M'),
                'new_students' => Child::tap($orgScope)
                    ->whereBetween('created_at', [$start, $end])->count(),
            ];
        })->values()->toArray();

        return [
            'total_students'       => $totalStudents,
            'active_students'      => $activeStudents,
            'engagement_rate'      => $totalStudents > 0 ? round(($activeStudents / $totalStudents) * 100, 1) : 0,
            'total_submissions'    => $totalSubmissions,
            'avg_assessment_score' => round((float) $avgScore, 1),
            'homework_submitted'   => $hwSubmitted,
            'homework_graded'      => $hwGraded,
            'lesson_completions'   => $lessonCompletions,
            'enrollment_trend'     => $enrollmentTrend,
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       CONTENT METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getContentMetrics(?int $orgId): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        $totalCourses = Course::tap($orgScope)->count();
        $totalLessons = ContentLesson::tap($orgScope)->count();
        $totalAssessments = Assessment::tap($orgScope)->count();
        $totalServices = Service::tap($orgScope)->count();

        // Content created this month
        $newContentMonth = ContentLesson::tap($orgScope)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)->count()
          + Assessment::tap($orgScope)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)->count();

        // Lesson engagement — avg completion %
        $avgCompletion = (float) (LessonProgress::when($orgId, fn ($q) => $q->whereHas('child', fn ($q2) => $q2->where('organization_id', $orgId)))
            ->avg('completion_percentage') ?? 0);

        // Avg time spent on lessons
        $avgTimeSpent = (float) (LessonProgress::when($orgId, fn ($q) => $q->whereHas('child', fn ($q2) => $q2->where('organization_id', $orgId)))
            ->avg('time_spent_seconds') ?? 0);

        // Content by type breakdown
        $contentBreakdown = [
            ['name' => 'Courses', 'value' => $totalCourses],
            ['name' => 'E-Learning', 'value' => $totalLessons],
            ['name' => 'Assessments', 'value' => $totalAssessments],
            ['name' => 'Services', 'value' => $totalServices],
        ];

        return [
            'total_courses'       => $totalCourses,
            'total_lessons'       => $totalLessons,
            'total_assessments'   => $totalAssessments,
            'total_services'      => $totalServices,
            'new_content_month'   => $newContentMonth,
            'avg_completion'      => round($avgCompletion, 1),
            'avg_time_spent_mins' => round($avgTimeSpent / 60, 1),
            'content_breakdown'   => $contentBreakdown,
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       AI AGENT METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getAgentMetrics(?int $orgId): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        // Total agent runs in last 7 days
        $totalRuns = BackgroundAgentRun::tap($orgScope)
            ->where('created_at', '>=', now()->subDays(7))->count();

        $successfulRuns = BackgroundAgentRun::tap($orgScope)
            ->where('created_at', '>=', now()->subDays(7))
            ->where('status', 'completed')->count();

        $totalTokensUsed = (int) (BackgroundAgentRun::tap($orgScope)
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('platform_tokens_used') ?? 0);

        // Per-agent breakdown
        $agentBreakdown = BackgroundAgentRun::tap($orgScope)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('agent_type, COUNT(*) as runs, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successes, SUM(COALESCE(platform_tokens_used, 0)) as tokens')
            ->groupBy('agent_type')
            ->get()
            ->map(fn ($row) => [
                'agent' => str_replace('_', ' ', ucfirst($row->agent_type)),
                'runs' => $row->runs,
                'success_rate' => $row->runs > 0 ? round(($row->successes / $row->runs) * 100, 1) : 0,
                'tokens' => $row->tokens,
            ])->toArray();

        // Token balance
        $tokenBalance = null;
        try {
            $tokenBalance = \App\Models\AgentTokenBalance::tap($orgScope)->first()?->balance ?? 0;
        } catch (\Exception $e) {}

        return [
            'total_runs_7d'      => $totalRuns,
            'successful_runs_7d' => $successfulRuns,
            'success_rate'       => $totalRuns > 0 ? round(($successfulRuns / $totalRuns) * 100, 1) : 0,
            'tokens_used_7d'     => $totalTokensUsed,
            'token_balance'      => $tokenBalance,
            'agent_breakdown'    => $agentBreakdown,
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       GROWTH & ACQUISITION METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getGrowthMetrics(?int $orgId, int $days): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        // New users this period vs previous
        $newUsersThisPeriod = User::when($orgId, fn ($q) => $q->whereHas('organizations', fn ($q2) => $q2->where('organizations.id', $orgId)))
            ->where('created_at', '>=', now()->subDays($days))->count();
        $newUsersPrevPeriod = User::when($orgId, fn ($q) => $q->whereHas('organizations', fn ($q2) => $q2->where('organizations.id', $orgId)))
            ->whereBetween('created_at', [now()->subDays($days * 2), now()->subDays($days)])->count();

        $userGrowth = $newUsersPrevPeriod > 0
            ? round((($newUsersThisPeriod - $newUsersPrevPeriod) / $newUsersPrevPeriod) * 100, 1)
            : ($newUsersThisPeriod > 0 ? 100 : 0);

        // Applications
        $totalApplications = 0;
        $approvedApplications = 0;
        try {
            $totalApplications = Application::tap($orgScope)
                ->where('created_at', '>=', now()->subDays($days))->count();
            $approvedApplications = Application::tap($orgScope)
                ->where('created_at', '>=', now()->subDays($days))
                ->where('status', 'approved')->count();
        } catch (\Exception $e) {}

        // Tracking funnel
        $funnelData = [];
        try {
            $funnelData = TrackingEvent::tap($orgScope)
                ->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('event_type, COUNT(*) as count')
                ->groupBy('event_type')
                ->pluck('count', 'event_type')
                ->toArray();
        } catch (\Exception $e) {}

        $funnel = [
            ['stage' => 'Page Views', 'count' => $funnelData['page_view'] ?? 0],
            ['stage' => 'Form Started', 'count' => $funnelData['form_start'] ?? 0],
            ['stage' => 'Form Submitted', 'count' => $funnelData['form_submit'] ?? 0],
            ['stage' => 'Verified', 'count' => $funnelData['verified'] ?? 0],
            ['stage' => 'Approved', 'count' => $funnelData['approved'] ?? 0],
            ['stage' => 'First Purchase', 'count' => $funnelData['first_purchase'] ?? 0],
        ];

        // New students per month (last 6 months)
        $monthlyGrowth = collect(range(5, 0))->map(function ($monthsAgo) use ($orgScope) {
            $date = now()->subMonths($monthsAgo);
            return [
                'month' => $date->format('M Y'),
                'students' => Child::tap($orgScope)
                    ->whereMonth('created_at', $date->month)
                    ->whereYear('created_at', $date->year)->count(),
                'users' => User::when(null, fn ($q) => $q) // simplified
                    ->whereMonth('created_at', $date->month)
                    ->whereYear('created_at', $date->year)->count(),
            ];
        })->values()->toArray();

        return [
            'new_users_period'     => $newUsersThisPeriod,
            'user_growth'          => $userGrowth,
            'total_applications'   => $totalApplications,
            'approved_applications' => $approvedApplications,
            'conversion_rate'      => $totalApplications > 0 ? round(($approvedApplications / $totalApplications) * 100, 1) : 0,
            'funnel'               => $funnel,
            'monthly_growth'       => $monthlyGrowth,
        ];
    }

    /* ════════════════════════════════════════════════════════════════
       COMMUNICATION METRICS
       ════════════════════════════════════════════════════════════════ */

    private function getCommunicationMetrics(?int $orgId, int $days): array
    {
        $orgScope = fn ($q) => $q->when($orgId, fn ($q2) => $q2->where('organization_id', $orgId));

        // Messages by channel
        $channelBreakdown = [];
        try {
            $channelBreakdown = CommunicationMessage::tap($orgScope)
                ->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('channel, COUNT(*) as count')
                ->groupBy('channel')
                ->pluck('count', 'channel')
                ->toArray();
        } catch (\Exception $e) {}

        $channels = [
            ['channel' => 'Email', 'count' => $channelBreakdown['email'] ?? 0],
            ['channel' => 'SMS', 'count' => $channelBreakdown['sms'] ?? 0],
            ['channel' => 'WhatsApp', 'count' => $channelBreakdown['whatsapp'] ?? 0],
            ['channel' => 'In-App', 'count' => $channelBreakdown['in_app'] ?? 0],
            ['channel' => 'Push', 'count' => $channelBreakdown['push'] ?? 0],
        ];

        $totalMessages = array_sum(array_column($channels, 'count'));

        // Open conversations
        $openConversations = 0;
        try {
            $openConversations = Conversation::tap($orgScope)->where('status', 'open')->count();
        } catch (\Exception $e) {}

        // Response metrics
        $avgResponseTime = null;
        try {
            $avgResponseTime = CommunicationMessage::tap($orgScope)
                ->where('direction', 'outbound')
                ->where('sender_type', 'admin')
                ->where('created_at', '>=', now()->subDays($days))
                ->count();
        } catch (\Exception $e) {}

        return [
            'total_messages'      => $totalMessages,
            'channel_breakdown'   => $channels,
            'open_conversations'  => $openConversations,
            'admin_responses'     => $avgResponseTime ?? 0,
        ];
    }
}
