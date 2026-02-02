<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\SuperAdmin\Organizations\OrganizationStoreRequest;
use App\Http\Requests\Api\SuperAdmin\Organizations\OrganizationUpdateRequest;
use App\Http\Requests\Api\SuperAdmin\Organizations\OrganizationUserAttachRequest;
use App\Http\Requests\Api\SuperAdmin\Organizations\OrganizationUserRoleRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\OrganizationUserResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiPagination;
use App\Support\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()
            ->withCount(['users', 'courses', 'contentLessons', 'liveLessonSessions', 'assessments']);

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        ApiQuery::applyFilters($query, $request, [
            'status' => true,
        ]);

        ApiQuery::applySort($query, $request, ['created_at', 'name', 'status'], '-created_at');

        $organizations = $query->paginate(ApiPagination::perPage($request));
        $data = OrganizationResource::collection($organizations->items())->resolve();

        return $this->paginated($organizations, $data);
    }

    public function store(OrganizationStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $organization = Organization::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'status' => $validated['status'],
            'settings' => $validated['settings'] ?? [],
            'owner_id' => $request->user()->id,
        ]);

        return $this->success([
            'organization' => (new OrganizationResource($organization))->resolve(),
        ], status: 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        $organization->loadCount([
            'users',
            'courses',
            'contentLessons',
            'liveLessonSessions',
            'assessments',
        ]);

        $recentUsers = $organization->users()
            ->latest('organization_users.created_at')
            ->take(5)
            ->get()
            ->map(fn ($user) => [
                'type' => 'user_joined',
                'description' => "{$user->name} joined the organization",
                'created_at' => $user->pivot->created_at,
            ]);

        $recentCourses = $organization->courses()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn ($course) => [
                'type' => 'course_created',
                'description' => "Course \"{$course->title}\" was created",
                'created_at' => $course->created_at,
            ]);

        $recentLessons = $organization->contentLessons()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn ($lesson) => [
                'type' => 'lesson_created',
                'description' => "Lesson \"{$lesson->title}\" was created",
                'created_at' => $lesson->created_at,
            ]);

        $recentSessions = $organization->liveLessonSessions()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn ($session) => [
                'type' => 'session_scheduled',
                'description' => "Live session \"{$session->title}\" was scheduled",
                'created_at' => $session->created_at,
            ]);

        $recentAssessments = $organization->assessments()
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(fn ($assessment) => [
                'type' => 'assessment_created',
                'description' => "Assessment \"{$assessment->title}\" was created",
                'created_at' => $assessment->created_at,
            ]);

        $activities = collect()
            ->merge($recentUsers)
            ->merge($recentCourses)
            ->merge($recentLessons)
            ->merge($recentSessions)
            ->merge($recentAssessments)
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return $this->success([
            'organization' => (new OrganizationResource($organization))->resolve(),
            'recent_activities' => $activities,
        ]);
    }

    public function update(OrganizationUpdateRequest $request, Organization $organization): JsonResponse
    {
        $validated = $request->validated();

        $organization->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? $organization->slug,
            'status' => $validated['status'],
            'settings' => $validated['settings'] ?? $organization->settings,
        ]);

        return $this->success([
            'organization' => (new OrganizationResource($organization->fresh()))->resolve(),
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();

        return $this->success(['message' => 'Organization deleted successfully.']);
    }

    public function users(Request $request, Organization $organization): JsonResponse
    {
        $users = $organization->users()
            ->withPivot(['role', 'status', 'joined_at', 'invited_by'])
            ->paginate(ApiPagination::perPage($request));

        $data = OrganizationUserResource::collection($users->items())->resolve();

        return $this->paginated($users, $data);
    }

    public function addUser(OrganizationUserAttachRequest $request, Organization $organization): JsonResponse
    {
        $validated = $request->validated();

        if ($organization->users()->where('user_id', $validated['user_id'])->exists()) {
            return $this->error('User is already a member of this organization.', [], 422);
        }

        $organization->users()->attach($validated['user_id'], [
            'role' => $validated['role'],
            'status' => 'active',
            'invited_by' => $request->user()->id,
            'joined_at' => now(),
        ]);

        $addedUser = $organization->users()
            ->withPivot(['role', 'status', 'joined_at', 'invited_by'])
            ->where('user_id', $validated['user_id'])
            ->first();

        return $this->success([
            'user' => (new OrganizationUserResource($addedUser))->resolve(),
        ], status: 201);
    }

    public function removeUser(Organization $organization, User $user): JsonResponse
    {
        if ($organization->owner_id === $user->id) {
            return $this->error('Cannot remove organization owner.', [], 422);
        }

        $organization->users()->detach($user->id);

        return $this->success(['message' => 'User removed from organization successfully.']);
    }

    public function changeUserRole(OrganizationUserRoleRequest $request, Organization $organization, User $user): JsonResponse
    {
        $validated = $request->validated();

        $organization->users()->updateExistingPivot($user->id, [
            'role' => $validated['role'],
        ]);

        return $this->success(['message' => 'User role updated successfully.']);
    }
}
