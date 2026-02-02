<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ContentLesson;
use App\Models\Assessment;
use App\Models\Service;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContentManagementController extends Controller
{
    public function courses(Request $request)
    {
        $courses = Course::query()
            ->with(['modules'])
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->paginate(20);

        return Inertia::render('@superadmin/Content/AllCourses', [
            'courses' => $courses,
        ]);
    }

    public function lessons(Request $request)
    {
        $lessons = ContentLesson::query()
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->paginate(20);

        return Inertia::render('@superadmin/Content/AllLessons', [
            'lessons' => $lessons,
        ]);
    }

    public function assessments(Request $request)
    {
        $assessments = Assessment::query()
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->paginate(20);

        return Inertia::render('@superadmin/Content/Assessments', [
            'assessments' => $assessments,
        ]);
    }

    public function services(Request $request)
    {
        $services = Service::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate(20);

        return Inertia::render('@superadmin/Content/Services', [
            'services' => $services,
        ]);
    }

    public function articles(Request $request)
    {
        return Inertia::render('@superadmin/Content/Articles', [
            'articles' => [],
        ]);
    }

    public function moderation(Request $request)
    {
        return Inertia::render('@superadmin/Content/Moderation');
    }

    public function feature(Request $request, $type, $id)
    {
        // Implementation for featuring content
        return back()->with('success', ucfirst($type) . ' featured successfully');
    }

    public function unfeature(Request $request, $type, $id)
    {
        // Implementation for unfeaturing content
        return back()->with('success', ucfirst($type) . ' unfeatured successfully');
    }

    public function delete($type, $id)
    {
        // Implementation for deleting content
        return back()->with('success', ucfirst($type) . ' deleted successfully');
    }
}
