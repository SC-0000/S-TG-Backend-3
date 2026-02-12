<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminTask;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\Organization;
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
}
