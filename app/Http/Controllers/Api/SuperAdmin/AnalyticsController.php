<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Assessment;
use App\Models\AssessmentSubmission;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\LiveLessonSession;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends ApiController
{
    public function dashboard(Request $request): JsonResponse
    {
        return $this->success([
            'users' => $this->userStats(),
            'content' => $this->contentStats(),
            'engagement' => $this->engagementStats(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        return $this->success($this->userStats());
    }

    public function content(Request $request): JsonResponse
    {
        return $this->success($this->contentStats());
    }

    public function engagement(Request $request): JsonResponse
    {
        return $this->success($this->engagementStats());
    }

    public function customReport(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $start = $request->date('start_date') ?? now()->subDays(30)->startOfDay();
        $end = $request->date('end_date') ?? now()->endOfDay();

        $newUsers = User::whereBetween('created_at', [$start, $end])->count();
        $newOrganizations = Organization::whereBetween('created_at', [$start, $end])->count();

        return $this->success([
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'metrics' => [
                'new_users' => $newUsers,
                'new_organizations' => $newOrganizations,
            ],
        ]);
    }

    private function userStats(): array
    {
        $byRole = User::selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $last7Days = collect(range(6, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            return [
                'date' => $date->toDateString(),
                'count' => User::whereDate('created_at', $date)->count(),
            ];
        });

        return [
            'total' => User::count(),
            'by_role' => $byRole,
            'new_users_last_7_days' => $last7Days,
        ];
    }

    private function contentStats(): array
    {
        return [
            'courses' => Course::count(),
            'lessons' => ContentLesson::count(),
            'assessments' => Assessment::count(),
            'services' => Service::count(),
        ];
    }

    private function engagementStats(): array
    {
        return [
            'live_sessions' => LiveLessonSession::count(),
            'assessment_submissions' => AssessmentSubmission::count(),
        ];
    }
}
