<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentLesson;
use App\Models\Course;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'total_organizations' => Organization::count(),
            'total_courses' => Course::count(),
            'total_lessons' => ContentLesson::count(),
            'active_users_today' => User::whereDate('updated_at', today())->count(),
            'new_users_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];

        return $this->success(['stats' => $stats]);
    }

    public function stats(Request $request): JsonResponse
    {
        return $this->success([
            'users' => [
                'total' => User::count(),
                'admins' => User::where('role', 'admin')->count(),
                'teachers' => User::where('role', 'teacher')->count(),
                'parents' => User::where('role', 'parent')->count(),
                'super_admins' => User::where('role', 'super_admin')->count(),
            ],
            'organizations' => [
                'total' => Organization::count(),
                'active' => Organization::where('status', 'active')->count(),
            ],
            'content' => [
                'courses' => Course::count(),
                'lessons' => ContentLesson::count(),
            ],
        ]);
    }
}
