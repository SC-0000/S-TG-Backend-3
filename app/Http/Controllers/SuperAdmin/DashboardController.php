<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Organization;
use App\Models\Course;
use App\Models\ContentLesson;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the super admin dashboard
     */
    public function index()
    {
        $stats = [
            'total_users' => User::count(),
            'total_organizations' => Organization::count(),
            'total_courses' => Course::count(),
            'total_lessons' => ContentLesson::count(),
            'active_users_today' => User::whereDate('updated_at', today())->count(),
            'new_users_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];

        return Inertia::render('@superadmin/Dashboard/Index', [
            'stats' => $stats,
        ]);
    }

    /**
     * Get detailed statistics for dashboard
     */
    public function stats(Request $request)
    {
        // Return comprehensive platform statistics
        return response()->json([
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
