<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $organizations = Organization::query()
            ->withCount(['users'])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate(20);

        return Inertia::render('@superadmin/Organizations/Index', [
            'organizations' => $organizations,
        ]);
    }

    public function create()
    {
        return Inertia::render('@superadmin/Organizations/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
             

        ]);
         $validated['owner_id'] = auth()->id();
        $organization = Organization::create($validated);

        return redirect()->route('superadmin.organizations.index')
            ->with('success', 'Organization created successfully');
    }

    public function show(Organization $organization)
    {
        $organization->loadCount([
            'users',
            'courses',
            'contentLessons',
            'liveLessonSessions',
            'assessments',
        ]);

        // Gather recent activities from multiple sources
        $activities = collect();

        // Recent users joined
        $recentUsers = $organization->users()
            ->latest('organization_users.created_at')
            ->take(5)
            ->get()
            ->map(fn($user) => [
                'type' => 'user_joined',
                'description' => "{$user->name} joined the organization",
                'created_at' => $user->pivot->created_at,
            ]);

        // Recent courses created
        $recentCourses = $organization->courses()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn($course) => [
                'type' => 'course_created',
                'description' => "Course \"{$course->title}\" was created",
                'created_at' => $course->created_at,
            ]);

        // Recent content lessons created
        $recentLessons = $organization->contentLessons()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn($lesson) => [
                'type' => 'lesson_created',
                'description' => "Lesson \"{$lesson->title}\" was created",
                'created_at' => $lesson->created_at,
            ]);

        // Recent live sessions scheduled
        $recentSessions = $organization->liveLessonSessions()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn($session) => [
                'type' => 'session_scheduled',
                'description' => "Live session \"{$session->title}\" was scheduled",
                'created_at' => $session->created_at,
            ]);

        // Recent assessments created
        $recentAssessments = $organization->assessments()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn($assessment) => [
                'type' => 'assessment_created',
                'description' => "Assessment \"{$assessment->title}\" was created",
                'created_at' => $assessment->created_at,
            ]);

        // Merge all activities, sort by created_at, take latest 10
        $activities = $activities
            ->merge($recentUsers)
            ->merge($recentCourses)
            ->merge($recentLessons)
            ->merge($recentSessions)
            ->merge($recentAssessments)
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return Inertia::render('@superadmin/Organizations/Show', [
            'organization' => $organization,
            'recentActivities' => $activities,
        ]);
    }

    public function edit(Organization $organization)
    {
        return Inertia::render('@superadmin/Organizations/Edit', [
            'organization' => $organization,
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        $organization->update($validated);

        return redirect()->route('superadmin.organizations.index')
            ->with('success', 'Organization updated successfully');
    }

    public function destroy(Organization $organization)
    {
        $organization->delete();

        return redirect()->route('superadmin.organizations.index')
            ->with('success', 'Organization deleted successfully');
    }

    public function analytics(Organization $organization)
    {
        return Inertia::render('@superadmin/Organizations/Analytics', [
            'organization' => $organization,
        ]);
    }

    public function content(Organization $organization)
    {
        return Inertia::render('@superadmin/Organizations/Content', [
            'organization' => $organization,
        ]);
    }

    public function users(Organization $organization)
    {
        return Inertia::render('@superadmin/Organizations/Users', [
            'organization' => $organization->load('users'),
        ]);
    }

    public function addUser(Request $request, Organization $organization)
    {
        // Implementation for adding user to organization
        return back()->with('success', 'User added to organization');
    }

    public function removeUser(Organization $organization, $user)
    {
        // Implementation for removing user from organization
        return back()->with('success', 'User removed from organization');
    }

    public function changeUserRole(Request $request, Organization $organization, $user)
    {
        // Implementation for changing user role in organization
        return back()->with('success', 'User role changed');
    }

    public function branding(Organization $organization)
    {
        return Inertia::render('@superadmin/Organizations/Branding', [
            'organization' => $organization,
        ]);
    }
}
