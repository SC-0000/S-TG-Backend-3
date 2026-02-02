<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminTask;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\Child;
use App\Models\Transaction;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $organizations = $user->role === 'super_admin'
            ? Organization::orderBy('name')->get()
            : null;

        // Get dashboard metrics
        $metrics = $this->getDashboardMetrics($request);

        return Inertia::render('@admin/Dashboard/AdminDashboard', [
            'auth' => [
                'user' => $user
            ],
            'organizations' => $organizations,
            'userOrganizations' => [],
            'currentOrganization' => $user->current_organization_id 
                ? Organization::find($user->current_organization_id)
                : null,
            'flash' => session()->all(),
            'metrics' => $metrics
        ]);
    }

    private function getDashboardMetrics(Request $request)
    {
        $orgId = $this->getOrganizationId($request);

        return [
            'revenue_today' => $this->getTodayRevenue($orgId),
            'revenue_month' => $this->getMonthlyRevenue($orgId),
            'active_students' => $this->getActiveStudentsCount($orgId),
            'pending_actions' => $this->getPendingActionsCount($orgId),
            'completion_rate' => $this->getLessonCompletionRate($orgId),
            'success_rate' => $this->getAssessmentSuccessRate($orgId),
            'weekly_revenue' => $this->getWeeklyRevenueTrend($orgId),
            'critical_actions' => $this->getCriticalActionsCount($orgId)
        ];
    }

    private function getOrganizationId(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role === 'super_admin' && $request->filled('organization_id')) {
            return $request->organization_id;
        }
        
        return $user->current_organization_id;
    }

    private function getTodayRevenue($orgId)
    {
        // Check for multiple possible completion statuses
        $query = Transaction::whereIn('status', ['completed', 'success', 'paid', 'confirmed'])
            ->whereDate('created_at', today());

        if ($orgId) {
            $query->whereHas('user', function ($q) use ($orgId) {
                $q->where('current_organization_id', $orgId);
            });
        }

        return $query->sum('total') ?? 0;
    }

    private function getMonthlyRevenue($orgId)
    {
        // Check for multiple possible completion statuses
        $query = Transaction::whereIn('status', ['completed', 'success', 'paid', 'confirmed'])
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        if ($orgId) {
            $query->whereHas('user', function ($q) use ($orgId) {
                $q->where('current_organization_id', $orgId);
            });
        }

        return $query->sum('total') ?? 0;
    }

    private function getActiveStudentsCount($orgId)
    {
        $query = Child::query();

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        // Students with recent activity (submissions, lesson progress, or user activity in the last week)
        return $query->where(function($q) {
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

    private function getPendingActionsCount($orgId)
    {
        return AdminTask::where('status', 'Pending')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();
    }

    private function getCriticalActionsCount($orgId)
    {
        return AdminTask::where('status', 'Pending')
            ->where('priority', 'Critical')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();
    }

    private function getLessonCompletionRate($orgId)
    {
        $totalAssessments = Assessment::where('status', 'active')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->count();

        if ($totalAssessments === 0) {
            return 0;
        }

        // Count assessments that have at least one graded submission (not total submissions)
        $assessmentsWithCompletions = Assessment::where('status', 'active')
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->whereHas('submissions', fn($q) => $q->where('status', 'graded'))
            ->count();

        return round(($assessmentsWithCompletions / $totalAssessments) * 100, 1);
    }

    private function getAssessmentSuccessRate($orgId)
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
            return 0;
        }

        // Consider 70% or above as success
        $successCount = $submissions->filter(function ($submission) {
            return $submission->total_marks > 0 && 
                   ($submission->marks_obtained / $submission->total_marks) >= 0.7;
        })->count();

        return round(($successCount / $submissions->count()) * 100, 1);
    }

    private function getWeeklyRevenueTrend($orgId)
    {
        return collect(range(6, 0))->map(function ($daysAgo) use ($orgId) {
            $date = now()->subDays($daysAgo);
            $query = Transaction::whereIn('status', ['completed', 'success', 'paid', 'confirmed'])
                ->whereDate('created_at', $date);

            if ($orgId) {
                $query->whereHas('user', function ($q) use ($orgId) {
                    $q->where('current_organization_id', $orgId);
                });
            }

            return [
                'date' => $date->format('M d'),
                'revenue' => $query->sum('total') ?? 0
            ];
        })->toArray();
    }

    /**
     * Debug method to investigate data issues
     */
    public function debug(Request $request)
    {
        $user = Auth::user();
        $orgId = $this->getOrganizationId($request);

        $debug = [
            'organization_id' => $orgId,
            'user_role' => $user->role,
            'transaction_statuses' => Transaction::select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(fn($item) => [$item->status => $item->count]),
            'admin_tasks_by_status' => AdminTask::select('status', 'organization_id', DB::raw('COUNT(*) as count'))
                ->groupBy('status', 'organization_id')
                ->get()
                ->groupBy('organization_id'),
            'assessment_submission_stats' => [
                'total_submissions' => AssessmentSubmission::count(),
                'graded_submissions' => AssessmentSubmission::where('status', 'graded')->count(),
                'by_status' => AssessmentSubmission::select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')
                    ->get()
                    ->mapWithKeys(fn($item) => [$item->status => $item->count]),
            ],
            'assessment_stats' => [
                'total_assessments' => Assessment::count(),
                'active_assessments' => Assessment::where('status', 'active')->count(),
                'by_organization' => Assessment::select('organization_id', DB::raw('COUNT(*) as count'))
                    ->groupBy('organization_id')
                    ->get()
                    ->mapWithKeys(fn($item) => [$item->organization_id => $item->count]),
            ],
        ];

        return response()->json($debug);
    }
}
