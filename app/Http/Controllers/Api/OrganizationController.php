<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\OrganizationResource;
use App\Http\Resources\OrganizationUserResource;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends ApiController
{
    private function canManageOrganizationUsers($user, Organization $organization): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasRoleInOrganization('super_admin', $organization->id)
            || $user->hasRoleInOrganization('org_admin', $organization->id);
    }

    private function canManageOrganizationSettings($user, Organization $organization): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return $user->isOrganizationOwner($organization->id)
            || $user->hasRoleInOrganization('super_admin', $organization->id);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            $query = Organization::query();
        } else {
            $query = $user->organizations();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $organizations = $query
            ->with('owner:id,name,email')
            ->withCount(['users', 'articles', 'assessments', 'liveLessonSessions', 'contentLessons', 'services'])
            ->latest()
            ->paginate(ApiPagination::perPage($request));

        $data = OrganizationResource::collection($organizations->items())->resolve();

        return $this->paginated($organizations, $data);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();

        if (!($user->isAdmin() || $user->isSuperAdmin() || $user->canAccessOrganization($organization->id))) {
            return $this->error('You do not have access to this organization.', [], 403);
        }

        $organization->load([
            'owner:id,name,email',
            'users' => function ($query) {
                $query->withPivot(['role', 'status', 'joined_at']);
            },
        ]);

        $organization->loadCount([
            'articles', 'assessments', 'liveLessonSessions', 'contentLessons', 'services',
            'children', 'transactions', 'applications', 'users',
        ]);

        $data = (new OrganizationResource($organization))->resolve();
        $data['user_role'] = $user->getRoleInOrganization($organization->id);
        $data['is_owner'] = $user->isOrganizationOwner($organization->id);

        return $this->success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!($user->isAdmin() || $user->isSuperAdmin() || $user->ownedOrganizations()->count() < 5)) {
            return $this->error('You are not allowed to create more organizations.', [], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:organizations,slug',
            'status' => 'required|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

        $organization = null;

        DB::transaction(function () use ($validated, $user, &$organization) {
            $organization = Organization::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? null,
                'status' => $validated['status'],
                'owner_id' => $user->id,
                'settings' => $validated['settings'] ?? [],
            ]);

            $organization->users()->attach($user->id, [
                'role' => 'super_admin',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $user->update(['current_organization_id' => $organization->id]);
        });

        return $this->success([
            'organization' => (new OrganizationResource($organization))->resolve(),
        ], status: 201);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageOrganizationSettings($user, $organization)) {
            return $this->error('You do not have permission to update this organization.', [], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('organizations', 'slug')->ignore($organization->id),
            ],
            'status' => 'required|in:active,inactive,suspended',
            'settings' => 'nullable|array',
        ]);

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

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        if (!($user->isAdmin() || $user->isSuperAdmin() || $user->isOrganizationOwner($organization->id))) {
            return $this->error('You do not have permission to delete this organization.', [], 403);
        }

        if (
            $organization->users()->count() > 1 ||
            $organization->articles()->count() > 0 ||
            $organization->assessments()->count() > 0 ||
            $organization->liveLessonSessions()->count() > 0 ||
            $organization->contentLessons()->count() > 0
        ) {
            return $this->error('Cannot delete organization with existing data.', [], 422);
        }

        $organization->delete();

        return $this->success(['message' => 'Organization deleted successfully.']);
    }

    public function switch(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|exists:organizations,id',
        ]);

        $user = Auth::user();

        if (!$user->canAccessOrganization($request->organization_id)) {
            return $this->error('You do not have access to this organization.', [], 403);
        }

        $user->switchOrganization($request->organization_id);
        $organization = Organization::find($request->organization_id);

        return $this->success([
            'message' => "Switched to {$organization->name} successfully.",
            'organization' => (new OrganizationResource($organization))->resolve(),
        ]);
    }

    public function users(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageOrganizationUsers($user, $organization)) {
            return $this->error('You do not have permission to manage users for this organization.', [], 403);
        }

        $users = $organization->users()
            ->withPivot(['role', 'status', 'joined_at', 'invited_by'])
            ->paginate(ApiPagination::perPage($request));

        $data = OrganizationUserResource::collection($users->items())->resolve();

        return $this->paginated($users, $data);
    }

    public function addUser(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageOrganizationUsers($user, $organization)) {
            return $this->error('You do not have permission to add users to this organization.', [], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:super_admin,org_admin,teacher,parent,student',
        ]);

        if ($organization->users()->where('user_id', $validated['user_id'])->exists()) {
            return $this->error('User is already a member of this organization.', [], 422);
        }

        $organization->users()->attach($validated['user_id'], [
            'role' => $validated['role'],
            'status' => 'active',
            'invited_by' => $user->id,
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

    public function updateUserRole(Request $request, Organization $organization, User $user): JsonResponse
    {
        $authUser = $request->user();
        if (!($authUser->isAdmin() || $authUser->isSuperAdmin() || $authUser->hasRoleInOrganization('super_admin', $organization->id))) {
            return $this->error('You do not have permission to update user roles.', [], 403);
        }

        $validated = $request->validate([
            'role' => 'required|in:super_admin,org_admin,teacher,parent,student',
        ]);

        $organization->users()->updateExistingPivot($user->id, [
            'role' => $validated['role'],
        ]);

        return $this->success(['message' => 'User role updated successfully.']);
    }

    public function removeUser(Request $request, Organization $organization, User $user): JsonResponse
    {
        $authUser = $request->user();
        if (!($authUser->isAdmin() || $authUser->isSuperAdmin() || $authUser->hasRoleInOrganization('super_admin', $organization->id))) {
            return $this->error('You do not have permission to remove users.', [], 403);
        }

        if ($organization->owner_id === $user->id) {
            return $this->error('Cannot remove organization owner.', [], 422);
        }

        $organization->users()->detach($user->id);

        return $this->success(['message' => 'User removed from organization successfully.']);
    }

    public function features(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageOrganizationSettings($user, $organization)) {
            return $this->error('You do not have permission to edit this organization.', [], 403);
        }

        $defaults = config('features.defaults', []);
        $overrides = config('features.overrides', []);
        $systemOverrides = SystemSetting::getValue('feature_overrides', []);
        $orgFeatures = $organization->settings['features'] ?? [];

        $effective = array_replace_recursive(
            $defaults,
            $orgFeatures,
            $systemOverrides,
            $overrides,
        );

        return $this->success([
            'organization' => $organization->only(['id', 'name']),
            'features' => $effective,
            'overrides' => array_replace_recursive($systemOverrides, $overrides),
        ]);
    }

    public function updateFeatures(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageOrganizationSettings($user, $organization)) {
            return $this->error('You do not have permission to update this organization.', [], 403);
        }

        $validated = $request->validate([
            'features' => 'required|array',
            'features.teacher.revenue_dashboard' => 'nullable|boolean',
            'features.teacher.content_edit' => 'nullable|boolean',
            'features.teacher.content_delete' => 'nullable|boolean',
            'features.parent.ai.chatbot' => 'nullable|boolean',
            'features.parent.ai.report_generation' => 'nullable|boolean',
            'features.parent.ai.post_submission_help' => 'nullable|boolean',
            'features.parent.subscriptions' => 'nullable|boolean',
        ]);

        $features = $validated['features'] ?? [];

        $flatten = function (array $array, string $prefix = '') use (&$flatten) {
            $result = [];
            foreach ($array as $key => $value) {
                $path = $prefix === '' ? $key : "{$prefix}.{$key}";
                if (is_array($value)) {
                    $result += $flatten($value, $path);
                } else {
                    $result[$path] = $value;
                }
            }
            return $result;
        };

        foreach ($flatten($features) as $path => $value) {
            $organization->setFeature($path, (bool) $value);
        }

        return $this->success(['message' => 'Features updated successfully.']);
    }
}
